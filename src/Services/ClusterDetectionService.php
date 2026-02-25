<?php

namespace AmirhMoradi\CoolifyEnhanced\Services;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Notifications\ClusterDegraded;
use AmirhMoradi\CoolifyEnhanced\Notifications\ClusterRecovered;
use AmirhMoradi\CoolifyEnhanced\Notifications\ClusterUnreachable;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

class ClusterDetectionService
{
    /**
     * Detect and register clusters from all Swarm manager servers belonging to a team.
     *
     * @return Cluster[]
     */
    public function detectClusters(int $teamId): array
    {
        $managers = Server::where('team_id', $teamId)
            ->whereHas('settings', fn ($q) => $q->where('is_swarm_manager', true))
            ->get();

        $detected = [];
        $seenSwarmIds = [];

        foreach ($managers as $server) {
            try {
                $cluster = $this->detectFromServer($server);
                if ($cluster) {
                    $swarmId = data_get($cluster->metadata, 'swarm_id');
                    if ($swarmId && ! in_array($swarmId, $seenSwarmIds, true)) {
                        $detected[] = $cluster;
                        $seenSwarmIds[] = $swarmId;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ClusterDetection: Failed for server', [
                    'server' => $server->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $detected;
    }

    /**
     * Detect cluster from a specific Swarm manager server.
     * Uses `docker info` to read Swarm metadata, then finds or creates a Cluster record.
     */
    public function detectFromServer(Server $server): ?Cluster
    {
        if (! $server->isFunctional()) {
            return null;
        }

        $dockerInfoJson = instant_remote_process(
            ['docker info --format "{{json .Swarm}}" 2>/dev/null'],
            $server,
            throwError: false
        );

        if (empty($dockerInfoJson)) {
            return null;
        }

        $swarmInfo = json_decode($dockerInfoJson, true);
        if (! $swarmInfo || data_get($swarmInfo, 'LocalNodeState') !== 'active') {
            return null;
        }

        $swarmId = data_get($swarmInfo, 'Cluster.ID');
        if (! $swarmId) {
            return null;
        }

        $isManager = data_get($swarmInfo, 'ControlAvailable', false);
        if (! $isManager) {
            return null;
        }

        $cluster = Cluster::where('team_id', $server->team_id)
            ->whereJsonContains('metadata->swarm_id', $swarmId)
            ->first();

        if (! $cluster) {
            $cluster = Cluster::create([
                'name' => $server->name.' Cluster',
                'type' => 'swarm',
                'status' => 'unknown',
                'manager_server_id' => $server->id,
                'team_id' => $server->team_id,
                'metadata' => ['swarm_id' => $swarmId],
            ]);
        }

        if (! $server->cluster_id) {
            $server->update(['cluster_id' => $cluster->id]);
        }

        $this->syncClusterMetadata($cluster);

        return $cluster->fresh();
    }

    /**
     * Sync cluster metadata from Docker: health, node counts, join tokens, and server links.
     * Fires notifications on status transitions (healthy→degraded, *→unreachable, recovered).
     */
    public function syncClusterMetadata(Cluster $cluster): void
    {
        $previousStatus = $cluster->status;

        if (! $cluster->managerServer || ! $cluster->managerServer->isFunctional()) {
            $cluster->update(['status' => 'unreachable']);
            $this->notifyStatusTransition($cluster, $previousStatus, 'unreachable');

            return;
        }

        try {
            $driver = $cluster->driver();

            $info = $driver->getClusterInfo();
            $health = $driver->getClusterHealth();
            $nodes = $driver->getNodes();

            $tokens = ['worker' => null, 'manager' => null];
            try {
                $tokens = $driver->getJoinTokens();
            } catch (\Throwable $e) {
                Log::warning('ClusterDetection: Could not fetch join tokens', [
                    'cluster' => $cluster->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $serviceCount = 0;
            try {
                $serviceCount = $driver->getServices()->count();
            } catch (\Throwable $e) {
                // Non-critical
            }

            $downNodes = $nodes->where('status', '!=', 'ready')->count();
            $totalNodes = $nodes->count();

            $cluster->update([
                'status' => $health,
                'metadata' => array_merge($cluster->metadata ?? [], [
                    'swarm_id' => data_get($info, 'id') ?: data_get($cluster->metadata, 'swarm_id'),
                    'swarm_created' => data_get($info, 'created'),
                    'docker_version' => $nodes->first()['engine_version'] ?? null,
                    'node_count' => $totalNodes,
                    'manager_count' => $nodes->where('role', 'manager')->count(),
                    'worker_count' => $nodes->where('role', 'worker')->count(),
                    'service_count' => $serviceCount,
                    'total_cpu' => $nodes->sum('cpu_cores'),
                    'total_memory_bytes' => $nodes->sum('memory_bytes'),
                    'last_sync_at' => now()->toIso8601String(),
                ]),
                'settings' => array_merge($cluster->settings ?? [], [
                    'swarm_join_token_worker' => data_get($tokens, 'worker'),
                    'swarm_join_token_manager' => data_get($tokens, 'manager'),
                ]),
            ]);

            $this->notifyStatusTransition($cluster, $previousStatus, $health, $downNodes, $totalNodes);
            $this->linkKnownServers($cluster, $nodes);

        } catch (\Throwable $e) {
            Log::error('ClusterDetection: Metadata sync failed', [
                'cluster' => $cluster->id,
                'error' => $e->getMessage(),
            ]);

            $cluster->update(['status' => 'unreachable']);
            $this->notifyStatusTransition($cluster, $previousStatus, 'unreachable');
        }
    }

    /**
     * Send team notifications when cluster status transitions between states.
     */
    protected function notifyStatusTransition(
        Cluster $cluster,
        string $previousStatus,
        string $newStatus,
        int $downNodes = 0,
        int $totalNodes = 0,
    ): void {
        if ($previousStatus === $newStatus) {
            return;
        }

        $team = $cluster->team;
        if (! $team) {
            return;
        }

        if ($previousStatus === 'healthy' && $newStatus === 'degraded') {
            $team->notify(new ClusterDegraded($cluster, $downNodes, $totalNodes));
        } elseif ($newStatus === 'unreachable' && $previousStatus !== 'unreachable') {
            $team->notify(new ClusterUnreachable($cluster));
        } elseif ($newStatus === 'healthy' && in_array($previousStatus, ['degraded', 'unreachable'], true)) {
            $team->notify(new ClusterRecovered($cluster));
        }
    }

    /**
     * Match discovered Swarm nodes to existing Coolify Server records by IP address.
     * Only links servers that belong to the same team and aren't already linked.
     */
    public function linkKnownServers(Cluster $cluster, $nodes): void
    {
        foreach ($nodes as $node) {
            $ip = $node['ip'] ?? null;
            if (! $ip) {
                continue;
            }

            $server = Server::where('team_id', $cluster->team_id)
                ->where('ip', $ip)
                ->whereNull('cluster_id')
                ->first();

            if ($server) {
                $server->update(['cluster_id' => $cluster->id]);
            }
        }
    }
}

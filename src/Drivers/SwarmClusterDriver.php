<?php

namespace AmirhMoradi\CoolifyEnhanced\Drivers;

use AmirhMoradi\CoolifyEnhanced\Contracts\ClusterDriverInterface;
use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SwarmClusterDriver implements ClusterDriverInterface
{
    private Cluster $cluster;

    private Server $managerServer;

    private int $cacheTtl = 30;

    public function setCluster(Cluster $cluster): self
    {
        $this->cluster = $cluster;
        $this->managerServer = $cluster->managerServer;
        $this->cacheTtl = (int) data_get($cluster->settings, 'cache_ttl_seconds', 30);

        return $this;
    }

    // ══════════════════════════════════════════════════════
    //  Phase 1: Read-Only Operations
    // ══════════════════════════════════════════════════════

    // ── Cluster Info ──────────────────────────────────────

    public function getClusterInfo(): array
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:info",
            $this->cacheTtl,
            function () {
                $json = $this->exec('docker info --format "{{json .Swarm}}" 2>/dev/null');
                $swarm = json_decode($json, true) ?? [];

                return [
                    'id' => data_get($swarm, 'Cluster.ID'),
                    'created' => data_get($swarm, 'Cluster.CreatedAt'),
                    'version' => data_get($swarm, 'Cluster.Version.Index'),
                    'nodes' => (int) data_get($swarm, 'Nodes', 0),
                    'managers' => (int) data_get($swarm, 'Managers', 0),
                    'workers' => (int) data_get($swarm, 'Nodes', 0) - (int) data_get($swarm, 'Managers', 0),
                ];
            }
        );
    }

    public function getClusterHealth(): string
    {
        try {
            $nodes = $this->getNodes();
            $totalNodes = $nodes->count();

            if ($totalNodes === 0) {
                return 'unreachable';
            }

            $downNodes = $nodes->where('status', '!=', 'ready')->count();

            if ($downNodes === 0) {
                return 'healthy';
            }
            if ($downNodes < $totalNodes) {
                return 'degraded';
            }

            return 'unreachable';
        } catch (\Throwable $e) {
            Log::warning('SwarmClusterDriver: Health check failed', [
                'cluster' => $this->cluster->id,
                'error' => $e->getMessage(),
            ]);

            return 'unreachable';
        }
    }

    // ── Nodes ─────────────────────────────────────────────

    public function getNodes(): Collection
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:nodes",
            $this->cacheTtl,
            function () {
                $output = $this->exec('docker node ls --format "{{json .}}" 2>/dev/null');
                $nodeList = collect();

                foreach (explode("\n", trim($output)) as $line) {
                    if (empty(trim($line))) {
                        continue;
                    }
                    $node = json_decode($line, true);
                    if ($node) {
                        $nodeList->push($node);
                    }
                }

                if ($nodeList->isEmpty()) {
                    return collect();
                }

                $allNodeIds = $nodeList->map(fn ($n) => $this->escape(data_get($n, 'ID')))->implode(' ');
                $inspectJson = $this->exec(
                    "docker node inspect {$allNodeIds} --format '{{json .}}' 2>/dev/null"
                );
                $inspects = collect(explode("\n", trim($inspectJson)))
                    ->filter()
                    ->mapWithKeys(function ($line) {
                        $data = json_decode($line, true);

                        return $data ? [data_get($data, 'ID') => $data] : [];
                    });

                $nodes = collect();
                foreach ($nodeList as $node) {
                    $nodeId = data_get($node, 'ID');
                    $inspect = $inspects->get($nodeId, []);

                    $managerStatus = data_get($node, 'ManagerStatus', '');
                    $isManager = ! empty($managerStatus) && $managerStatus !== '';

                    $nodes->push([
                        'id' => $nodeId,
                        'hostname' => data_get($node, 'Hostname'),
                        'role' => $isManager ? 'manager' : 'worker',
                        'status' => strtolower(data_get($node, 'Status', 'unknown')),
                        'availability' => strtolower(data_get($node, 'Availability', 'active')),
                        'ip' => data_get($inspect, 'Status.Addr', ''),
                        'engine_version' => data_get($node, 'EngineVersion', ''),
                        'cpu_cores' => (int) (data_get($inspect, 'Description.Resources.NanoCPUs', 0) / 1e9),
                        'memory_bytes' => (int) data_get($inspect, 'Description.Resources.MemoryBytes', 0),
                        'labels' => data_get($inspect, 'Spec.Labels', []),
                        'is_leader' => $managerStatus === 'Leader',
                        'manager_reachability' => data_get($inspect, 'ManagerStatus.Reachability'),
                        'platform_os' => data_get($inspect, 'Description.Platform.OS', 'linux'),
                        'platform_arch' => data_get($inspect, 'Description.Platform.Architecture', 'amd64'),
                    ]);
                }

                return $nodes;
            }
        );
    }

    public function getNode(string $nodeId): array
    {
        $inspect = $this->inspectNode($nodeId);

        if (empty($inspect)) {
            return [];
        }

        $managerReachability = data_get($inspect, 'ManagerStatus.Reachability');
        $isManager = $managerReachability !== null;
        $isLeader = data_get($inspect, 'ManagerStatus.Leader', false);

        return [
            'id' => data_get($inspect, 'ID'),
            'hostname' => data_get($inspect, 'Description.Hostname'),
            'role' => $isManager ? 'manager' : 'worker',
            'status' => strtolower(data_get($inspect, 'Status.State', 'unknown')),
            'availability' => strtolower(data_get($inspect, 'Spec.Availability', 'active')),
            'ip' => data_get($inspect, 'Status.Addr', ''),
            'engine_version' => data_get($inspect, 'Description.Engine.EngineVersion', ''),
            'cpu_cores' => (int) (data_get($inspect, 'Description.Resources.NanoCPUs', 0) / 1e9),
            'memory_bytes' => (int) data_get($inspect, 'Description.Resources.MemoryBytes', 0),
            'labels' => data_get($inspect, 'Spec.Labels', []),
            'is_leader' => (bool) $isLeader,
            'manager_reachability' => $managerReachability,
            'platform_os' => data_get($inspect, 'Description.Platform.OS', 'linux'),
            'platform_arch' => data_get($inspect, 'Description.Platform.Architecture', 'amd64'),
        ];
    }

    /**
     * Return static resource info from Docker node inspect.
     *
     * Live CPU/memory/disk utilization is unavailable: the manager SSH session
     * cannot read /proc on remote worker nodes, and CPU measurement requires
     * two time-separated samples which is impractical in a synchronous request.
     */
    public function getNodeResources(string $nodeId): array
    {
        $node = $this->getNode($nodeId);

        return [
            'cpu_percent' => null,
            'memory_percent' => null,
            'memory_used' => 0,
            'memory_total' => $node['memory_bytes'] ?? 0,
            'disk_percent' => null,
        ];
    }

    // ── Services ──────────────────────────────────────────

    public function getServices(): Collection
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:services",
            $this->cacheTtl,
            function () {
                $output = $this->exec('docker service ls --format "{{json .}}" 2>/dev/null');

                return collect(explode("\n", trim($output)))
                    ->filter(fn ($line) => ! empty(trim($line)))
                    ->map(function ($line) {
                        $svc = json_decode($line, true);
                        if (! $svc) {
                            return null;
                        }

                        $replicas = data_get($svc, 'Replicas', '0/0');
                        [$running, $desired] = $this->parseReplicaString($replicas);

                        return [
                            'id' => data_get($svc, 'ID'),
                            'name' => data_get($svc, 'Name'),
                            'image' => data_get($svc, 'Image'),
                            'mode' => data_get($svc, 'Mode'),
                            'replicas_running' => $running,
                            'replicas_desired' => $desired,
                            'ports' => data_get($svc, 'Ports', ''),
                            'updated_at' => null,
                        ];
                    })
                    ->filter()
                    ->values();
            }
        );
    }

    public function getService(string $serviceId): array
    {
        $json = $this->exec(
            'docker service inspect '.$this->escape($serviceId).' --format "{{json .}}" 2>/dev/null'
        );

        $svc = json_decode($json, true);
        if (! $svc) {
            return [];
        }

        $mode = data_get($svc, 'Spec.Mode.Replicated') !== null ? 'replicated' : 'global';
        $desired = $mode === 'replicated'
            ? (int) data_get($svc, 'Spec.Mode.Replicated.Replicas', 0)
            : 0;

        $tasks = $this->getServiceTasks($serviceId);
        $running = $tasks->where('status', 'running')->where('desired_state', 'running')->count();

        $ports = collect(data_get($svc, 'Endpoint.Ports', []))->map(function ($port) {
            return [
                'protocol' => data_get($port, 'Protocol', 'tcp'),
                'target' => (int) data_get($port, 'TargetPort', 0),
                'published' => (int) data_get($port, 'PublishedPort', 0),
                'mode' => data_get($port, 'PublishMode', 'ingress'),
            ];
        })->toArray();

        return [
            'id' => data_get($svc, 'ID'),
            'name' => data_get($svc, 'Spec.Name'),
            'image' => data_get($svc, 'Spec.TaskTemplate.ContainerSpec.Image', ''),
            'mode' => $mode,
            'replicas_running' => $running,
            'replicas_desired' => $desired,
            'ports' => $ports,
            'labels' => data_get($svc, 'Spec.Labels', []),
            'created_at' => data_get($svc, 'CreatedAt', ''),
            'updated_at' => data_get($svc, 'UpdatedAt', ''),
        ];
    }

    public function getServiceTasks(string $serviceId): Collection
    {
        $output = $this->exec(
            'docker service ps '.$this->escape($serviceId).' --format "{{json .}}" --no-trunc 2>/dev/null'
        );

        return $this->parseTasks($output);
    }

    // ── Tasks ─────────────────────────────────────────────

    public function getAllTasks(): Collection
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:tasks",
            $this->cacheTtl,
            function () {
                $output = $this->exec(
                    'docker node ps $(docker node ls -q) --format "{{json .}}" --no-trunc 2>/dev/null'
                );

                return $this->parseTasks($output);
            }
        );
    }

    public function getNodeTasks(string $nodeId): Collection
    {
        $output = $this->exec(
            'docker node ps '.$this->escape($nodeId).' --format "{{json .}}" --no-trunc 2>/dev/null'
        );

        return $this->parseTasks($output);
    }

    // ── Events ────────────────────────────────────────────

    public function getEvents(int $since, ?string $filterType = null): Collection
    {
        $now = time();
        $cmd = 'docker system events'
            .' --since '.$this->escape((string) $since)
            .' --until '.$this->escape((string) $now)
            .' --format "{{json .}}" 2>/dev/null';

        if ($filterType) {
            $cmd = 'docker system events'
                .' --since '.$this->escape((string) $since)
                .' --until '.$this->escape((string) $now)
                .' --filter type='.$this->escape($filterType)
                .' --format "{{json .}}" 2>/dev/null';
        }

        $output = $this->exec($cmd);

        return collect(explode("\n", trim($output)))
            ->filter(fn ($line) => ! empty(trim($line)))
            ->map(function ($line) {
                $event = json_decode($line, true);
                if (! $event) {
                    return null;
                }

                return [
                    'type' => data_get($event, 'Type', ''),
                    'action' => data_get($event, 'Action', ''),
                    'actor_id' => data_get($event, 'Actor.ID'),
                    'actor_name' => data_get($event, 'Actor.Attributes.name'),
                    'attributes' => data_get($event, 'Actor.Attributes', []),
                    'scope' => data_get($event, 'scope'),
                    'time' => (int) data_get($event, 'time', 0),
                ];
            })
            ->filter()
            ->values();
    }

    // ── Join Tokens ───────────────────────────────────────

    public function getJoinTokens(): array
    {
        $workerToken = trim($this->exec('docker swarm join-token worker -q 2>/dev/null'));
        $managerToken = trim($this->exec('docker swarm join-token manager -q 2>/dev/null'));

        return [
            'worker' => $workerToken,
            'manager' => $managerToken,
        ];
    }

    // ══════════════════════════════════════════════════════
    //  Phase 2: Node & Service Management
    // ══════════════════════════════════════════════════════

    // ── Node Management ───────────────────────────────────

    public function updateNodeAvailability(string $nodeId, string $availability): bool
    {
        $allowed = ['active', 'pause', 'drain'];
        if (! in_array($availability, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid availability: {$availability}");
        }

        $this->execWrite(
            'docker node update --availability '.$this->escape($availability).' '.$this->escape($nodeId)
        );
        $this->invalidateCache('nodes');

        return true;
    }

    public function promoteNode(string $nodeId): bool
    {
        $this->execWrite('docker node promote '.$this->escape($nodeId));
        $this->invalidateCache('nodes');

        return true;
    }

    public function demoteNode(string $nodeId): bool
    {
        $nodes = $this->getNodes();
        $managers = $nodes->where('role', 'manager');

        if ($managers->count() <= 1) {
            Log::warning('SwarmClusterDriver: Cannot demote last manager', [
                'cluster' => $this->cluster->id,
                'node' => $nodeId,
            ]);

            return false;
        }

        $this->execWrite('docker node demote '.$this->escape($nodeId));
        $this->invalidateCache('nodes');

        return true;
    }

    public function removeNode(string $nodeId, bool $force = false): bool
    {
        $cmd = 'docker node rm '.$this->escape($nodeId);
        if ($force) {
            $cmd .= ' --force';
        }

        $this->execWrite($cmd);
        $this->invalidateCache('nodes');

        return true;
    }

    public function updateNodeLabels(string $nodeId, array $add = [], array $remove = []): bool
    {
        if (empty($add) && empty($remove)) {
            return true;
        }

        $parts = ['docker node update'];

        foreach ($add as $key => $value) {
            $parts[] = '--label-add '.$this->escape($key.'='.$value);
        }

        foreach ($remove as $key) {
            $parts[] = '--label-rm '.$this->escape($key);
        }

        $parts[] = $this->escape($nodeId);

        $this->execWrite(implode(' ', $parts));
        $this->invalidateCache('nodes');

        return true;
    }

    // ── Service Management ────────────────────────────────

    public function scaleService(string $serviceId, int $replicas): bool
    {
        $replicas = max(0, $replicas);

        $this->execWrite(
            'docker service scale '.$this->escape($serviceId).'='.$replicas
        );
        $this->invalidateCache('services');
        $this->invalidateCache('tasks');

        return true;
    }

    public function rollbackService(string $serviceId): bool
    {
        $this->execWrite(
            'docker service rollback '.$this->escape($serviceId)
        );
        $this->invalidateCache('services');
        $this->invalidateCache('tasks');

        return true;
    }

    public function forceUpdateService(string $serviceId): bool
    {
        $this->execWrite(
            'docker service update --force '.$this->escape($serviceId)
        );
        $this->invalidateCache('services');
        $this->invalidateCache('tasks');

        return true;
    }

    // ══════════════════════════════════════════════════════
    //  Phase 3: Secrets, Configs, Stacks
    // ══════════════════════════════════════════════════════

    // ── Secrets ───────────────────────────────────────────

    public function getSecrets(): Collection
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:secrets",
            $this->cacheTtl,
            function () {
                $output = $this->exec('docker secret ls --format "{{json .}}" 2>/dev/null');

                return collect(explode("\n", trim($output)))
                    ->filter(fn ($line) => ! empty(trim($line)))
                    ->map(function ($line) {
                        $secret = json_decode($line, true);
                        if (! $secret) {
                            return null;
                        }

                        return [
                            'id' => data_get($secret, 'ID'),
                            'name' => data_get($secret, 'Name'),
                            'created_at' => data_get($secret, 'CreatedAt', ''),
                            'updated_at' => data_get($secret, 'UpdatedAt', ''),
                            'labels' => $this->parseLabelsString(data_get($secret, 'Labels', '')),
                        ];
                    })
                    ->filter()
                    ->values();
            }
        );
    }

    public function createSecret(string $name, string $data, array $labels = []): string
    {
        $parts = ["printf '%s' ".$this->escape($data).' | docker secret create'];

        foreach ($labels as $key => $value) {
            $parts[] = '--label '.$this->escape($key.'='.$value);
        }

        $parts[] = $this->escape($name);
        $parts[] = '-';
        $parts[] = '2>&1';

        $result = trim($this->exec(implode(' ', $parts)));

        $this->invalidateCache('secrets');

        return $result;
    }

    public function removeSecret(string $secretId): bool
    {
        $this->execWrite('docker secret rm '.$this->escape($secretId));
        $this->invalidateCache('secrets');

        return true;
    }

    // ── Configs ───────────────────────────────────────────

    public function getConfigs(): Collection
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:configs",
            $this->cacheTtl,
            function () {
                $output = $this->exec('docker config ls --format "{{json .}}" 2>/dev/null');

                return collect(explode("\n", trim($output)))
                    ->filter(fn ($line) => ! empty(trim($line)))
                    ->map(function ($line) {
                        $config = json_decode($line, true);
                        if (! $config) {
                            return null;
                        }

                        $configId = data_get($config, 'ID');
                        $configData = '';

                        $inspectJson = $this->exec(
                            'docker config inspect '.$this->escape($configId).' --format "{{json .Spec.Data}}" 2>/dev/null'
                        );
                        $decoded = json_decode($inspectJson, true);
                        if (is_string($decoded)) {
                            $configData = base64_decode($decoded) ?: $decoded;
                        }

                        return [
                            'id' => $configId,
                            'name' => data_get($config, 'Name'),
                            'data' => $configData,
                            'created_at' => data_get($config, 'CreatedAt', ''),
                            'labels' => $this->parseLabelsString(data_get($config, 'Labels', '')),
                        ];
                    })
                    ->filter()
                    ->values();
            }
        );
    }

    public function createConfig(string $name, string $data, array $labels = []): string
    {
        $parts = ["printf '%s' ".$this->escape($data).' | docker config create'];

        foreach ($labels as $key => $value) {
            $parts[] = '--label '.$this->escape($key.'='.$value);
        }

        $parts[] = $this->escape($name);
        $parts[] = '-';
        $parts[] = '2>&1';

        $result = trim($this->exec(implode(' ', $parts)));

        $this->invalidateCache('configs');

        return $result;
    }

    public function removeConfig(string $configId): bool
    {
        $this->execWrite('docker config rm '.$this->escape($configId));
        $this->invalidateCache('configs');

        return true;
    }

    // ── Stacks ────────────────────────────────────────────

    public function getStacks(): Collection
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:stacks",
            $this->cacheTtl,
            function () {
                $output = $this->exec('docker stack ls --format "{{json .}}" 2>/dev/null');

                return collect(explode("\n", trim($output)))
                    ->filter(fn ($line) => ! empty(trim($line)))
                    ->map(function ($line) {
                        $stack = json_decode($line, true);
                        if (! $stack) {
                            return null;
                        }

                        return [
                            'name' => data_get($stack, 'Name'),
                            'services' => (int) data_get($stack, 'Services', 0),
                            'orchestrator' => data_get($stack, 'Orchestrator', 'swarm'),
                        ];
                    })
                    ->filter()
                    ->values();
            }
        );
    }

    // ══════════════════════════════════════════════════════
    //  Private Helpers
    // ══════════════════════════════════════════════════════

    private function exec(string $command): string
    {
        return instant_remote_process(
            [$command],
            $this->managerServer,
            throwError: false
        ) ?? '';
    }

    /**
     * Execute a write command that must succeed. Throws on non-zero exit code
     * instead of relying on fragile string matching against output.
     */
    private function execWrite(string $command): string
    {
        try {
            $result = instant_remote_process(
                [$command],
                $this->managerServer,
                throwError: true
            );

            return $result ?? '';
        } catch (\Throwable $e) {
            throw new \RuntimeException('Docker command failed: '.$e->getMessage());
        }
    }

    private function escape(string $value): string
    {
        return escapeshellarg($value);
    }

    private function inspectNode(string $nodeId): array
    {
        $json = $this->exec(
            'docker node inspect '.$this->escape($nodeId).' --format "{{json .}}" 2>/dev/null'
        );

        return json_decode($json, true) ?? [];
    }

    private function invalidateCache(string $resource): void
    {
        Cache::forget("cluster:{$this->cluster->id}:{$resource}");
    }

    /**
     * Parse "3/5" or "global" replicas string from docker service ls.
     *
     * @return array{0: int, 1: int}
     */
    private function parseReplicaString(string $replicas): array
    {
        if (str_contains($replicas, '/')) {
            $parts = explode('/', $replicas);

            return [(int) $parts[0], (int) ($parts[1] ?? 0)];
        }

        return [0, 0];
    }

    private function parseTasks(string $output): Collection
    {
        return collect(explode("\n", trim($output)))
            ->filter(fn ($line) => ! empty(trim($line)))
            ->map(function ($line) {
                $task = json_decode($line, true);
                if (! $task) {
                    return null;
                }

                return [
                    'id' => data_get($task, 'ID'),
                    'name' => data_get($task, 'Name'),
                    'node' => data_get($task, 'Node', ''),
                    'service_id' => data_get($task, 'Name') ? explode('.', data_get($task, 'Name'))[0] ?? '' : '',
                    'node_id' => data_get($task, 'Node', ''),
                    'status' => strtolower(data_get($task, 'CurrentState', '')),
                    'desired_state' => strtolower(data_get($task, 'DesiredState', '')),
                    'error' => data_get($task, 'Error', '') ?: null,
                    'image' => data_get($task, 'Image', ''),
                    'ports' => data_get($task, 'Ports', ''),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Parse comma-separated "key=value,key2=value2" label string from docker ls output.
     */
    private function parseLabelsString(string $labels): array
    {
        if (empty(trim($labels))) {
            return [];
        }

        $result = [];
        foreach (explode(',', $labels) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $result[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $result;
    }

}

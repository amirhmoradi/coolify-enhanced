# Cluster Management — Technical Implementation Plan

> **Prerequisite reading:** [PRD.md](PRD.md) for full requirements and UX mockups.

## Phase 1: Cluster Dashboard & Node Visibility (Read-Only)

### 1.1 Database Migrations

#### Migration: `create_clusters_table`

```php
Schema::create('clusters', function (Blueprint $table) {
    $table->id();
    $table->string('uuid')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->enum('type', ['swarm', 'kubernetes'])->default('swarm');
    $table->enum('status', ['healthy', 'degraded', 'unreachable', 'unknown'])->default('unknown');
    $table->foreignId('manager_server_id')->nullable()->constrained('servers')->nullOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->json('settings')->nullable();
    // settings JSON schema:
    // {
    //   "swarm_join_token_worker": "encrypted:...",
    //   "swarm_join_token_manager": "encrypted:...",
    //   "auto_detect_nodes": true,
    //   "metrics_retention_hours": 168,
    //   "sync_interval_seconds": 30,
    //   "cache_ttl_seconds": 30
    // }
    $table->json('metadata')->nullable();
    // metadata JSON schema (cached from Docker):
    // {
    //   "swarm_id": "abc123...",
    //   "swarm_created_at": "2024-01-01T00:00:00Z",
    //   "docker_version": "24.0.7",
    //   "node_count": 5,
    //   "manager_count": 3,
    //   "worker_count": 2,
    //   "service_count": 12,
    //   "task_count": 47,
    //   "total_cpu": 48,
    //   "total_memory_gb": 96,
    //   "last_sync_at": "2024-01-01T12:00:00Z"
    // }
    $table->timestamps();
});
```

#### Migration: `add_cluster_id_to_servers`

```php
Schema::table('servers', function (Blueprint $table) {
    $table->foreignId('cluster_id')->nullable()->constrained('clusters')->nullOnDelete();
    // Note: Coolify already has `swarm_cluster` integer field.
    // We add a proper FK. The old field can coexist.
});
```

#### Migration: `create_cluster_events_table`

```php
Schema::create('cluster_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
    $table->string('event_type');       // node, service, task, container
    $table->string('action');           // create, update, remove, start, stop, die
    $table->string('actor_id')->nullable();    // Docker ID of the actor
    $table->string('actor_name')->nullable();
    $table->json('attributes')->nullable();     // Docker event attributes
    $table->string('scope')->nullable();        // swarm, local
    $table->timestamp('event_time');
    $table->timestamps();

    $table->index(['cluster_id', 'event_time']);
    $table->index(['cluster_id', 'event_type']);
});
```

### 1.2 Orchestrator Abstraction Layer

#### Contract: `ClusterDriverInterface`

**File:** `src/Contracts/ClusterDriverInterface.php`

```php
<?php

namespace AmirhMoradi\CoolifyEnhanced\Contracts;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Support\Collection;

interface ClusterDriverInterface
{
    /**
     * Initialize the driver with a cluster instance.
     */
    public function setCluster(Cluster $cluster): self;

    // ── Cluster Info ──────────────────────────────────────

    /**
     * Get cluster-wide information (version, status, node count, etc.)
     * @return array{id: string, created: string, version: string, nodes: int, managers: int, workers: int}
     */
    public function getClusterInfo(): array;

    /**
     * Check cluster health. Returns 'healthy', 'degraded', or 'unreachable'.
     */
    public function getClusterHealth(): string;

    // ── Nodes ─────────────────────────────────────────────

    /**
     * List all nodes in the cluster.
     * @return Collection<int, array{
     *   id: string, hostname: string, role: string, status: string,
     *   availability: string, ip: string, engine_version: string,
     *   cpu_cores: int, memory_bytes: int, labels: array,
     *   is_leader: bool, manager_reachability: ?string
     * }>
     */
    public function getNodes(): Collection;

    /**
     * Get detailed info for a single node.
     */
    public function getNode(string $nodeId): array;

    /**
     * Get resource usage for a node (CPU%, mem%, disk%).
     * @return array{cpu_percent: float, memory_percent: float, memory_used: int, memory_total: int, disk_percent: float}
     */
    public function getNodeResources(string $nodeId): array;

    // ── Services ──────────────────────────────────────────

    /**
     * List all services in the cluster.
     * @return Collection<int, array{
     *   id: string, name: string, image: string, mode: string,
     *   replicas_running: int, replicas_desired: int,
     *   ports: array, labels: array, updated_at: string
     * }>
     */
    public function getServices(): Collection;

    /**
     * Get detailed info for a single service.
     */
    public function getService(string $serviceId): array;

    /**
     * Get tasks for a specific service.
     * @return Collection<int, array{
     *   id: string, service_id: string, node_id: string, slot: int,
     *   status: string, desired_state: string, error: ?string,
     *   container_id: ?string, started_at: ?string, ports: array
     * }>
     */
    public function getServiceTasks(string $serviceId): Collection;

    // ── Tasks ─────────────────────────────────────────────

    /**
     * Get all tasks across the cluster.
     */
    public function getAllTasks(): Collection;

    /**
     * Get tasks running on a specific node.
     */
    public function getNodeTasks(string $nodeId): Collection;

    // ── Events ────────────────────────────────────────────

    /**
     * Get recent cluster events.
     * @param int $since Unix timestamp
     */
    public function getEvents(int $since, ?string $filterType = null): Collection;

    // ── Join Tokens ───────────────────────────────────────

    /**
     * Get the current join tokens.
     * @return array{worker: string, manager: string}
     */
    public function getJoinTokens(): array;

    // ── Node Management (Phase 2) ─────────────────────────

    /**
     * Update node availability (active, pause, drain).
     */
    public function updateNodeAvailability(string $nodeId, string $availability): bool;

    /**
     * Promote a worker to manager.
     */
    public function promoteNode(string $nodeId): bool;

    /**
     * Demote a manager to worker.
     */
    public function demoteNode(string $nodeId): bool;

    /**
     * Remove a node from the cluster.
     */
    public function removeNode(string $nodeId, bool $force = false): bool;

    /**
     * Add or remove node labels.
     */
    public function updateNodeLabels(string $nodeId, array $add = [], array $remove = []): bool;

    // ── Service Management (Phase 2) ──────────────────────

    /**
     * Scale a service to the desired number of replicas.
     */
    public function scaleService(string $serviceId, int $replicas): bool;

    /**
     * Rollback a service to its previous version.
     */
    public function rollbackService(string $serviceId): bool;

    /**
     * Force update a service (redistribute tasks).
     */
    public function forceUpdateService(string $serviceId): bool;

    // ── Secrets (Phase 3) ─────────────────────────────────

    /**
     * List all secrets.
     * @return Collection<int, array{id: string, name: string, created_at: string, updated_at: string, labels: array}>
     */
    public function getSecrets(): Collection;

    /**
     * Create a secret.
     */
    public function createSecret(string $name, string $data, array $labels = []): string;

    /**
     * Remove a secret.
     */
    public function removeSecret(string $secretId): bool;

    // ── Configs (Phase 3) ─────────────────────────────────

    /**
     * List all configs.
     * @return Collection<int, array{id: string, name: string, data: string, created_at: string, labels: array}>
     */
    public function getConfigs(): Collection;

    /**
     * Create a config.
     */
    public function createConfig(string $name, string $data, array $labels = []): string;

    /**
     * Remove a config.
     */
    public function removeConfig(string $configId): bool;

    // ── Stacks (Phase 3) ──────────────────────────────────

    /**
     * List all stacks.
     */
    public function getStacks(): Collection;
}
```

#### Swarm Driver: `SwarmClusterDriver`

**File:** `src/Drivers/SwarmClusterDriver.php`

This driver executes Docker CLI commands on the cluster's manager server via SSH (using Coolify's `instant_remote_process()`).

**Key implementation patterns:**

```php
<?php

namespace AmirhMoradi\CoolifyEnhanced\Drivers;

use AmirhMoradi\CoolifyEnhanced\Contracts\ClusterDriverInterface;
use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SwarmClusterDriver implements ClusterDriverInterface
{
    private Cluster $cluster;
    private Server $managerServer;
    private int $cacheTtl = 30; // seconds

    public function setCluster(Cluster $cluster): self
    {
        $this->cluster = $cluster;
        $this->managerServer = $cluster->managerServer;
        $this->cacheTtl = data_get($cluster->settings, 'cache_ttl_seconds', 30);
        return $this;
    }

    // ── Cluster Info ──────────────────────────────────────

    public function getClusterInfo(): array
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:info",
            $this->cacheTtl,
            function () {
                $json = $this->exec('docker info --format "{{json .Swarm}}"');
                $swarm = json_decode($json, true);

                return [
                    'id'       => data_get($swarm, 'Cluster.ID'),
                    'created'  => data_get($swarm, 'Cluster.CreatedAt'),
                    'version'  => data_get($swarm, 'Cluster.Version.Index'),
                    'nodes'    => data_get($swarm, 'Nodes', 0),
                    'managers' => data_get($swarm, 'Managers', 0),
                    'workers'  => data_get($swarm, 'Nodes', 0) - data_get($swarm, 'Managers', 0),
                ];
            }
        );
    }

    public function getClusterHealth(): string
    {
        try {
            $nodes = $this->getNodes();
            $downNodes = $nodes->where('status', 'down')->count();
            $totalNodes = $nodes->count();

            if ($downNodes === 0) return 'healthy';
            if ($downNodes < $totalNodes) return 'degraded';
            return 'unreachable';
        } catch (\Exception $e) {
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
                // docker node ls gives basic info
                $nodesJson = $this->exec(
                    'docker node ls --format "{{json .}}" 2>/dev/null'
                );

                $nodes = collect();
                foreach (explode("\n", trim($nodesJson)) as $line) {
                    if (empty($line)) continue;
                    $node = json_decode($line, true);
                    if (!$node) continue;

                    // Get detailed inspect for each node
                    $nodeId = data_get($node, 'ID');
                    $inspect = $this->inspectNode($nodeId);

                    $nodes->push([
                        'id'                    => $nodeId,
                        'hostname'              => data_get($node, 'Hostname'),
                        'role'                  => strtolower(data_get($node, 'ManagerStatus', '') === '' ? 'worker' : 'manager'),
                        'status'                => strtolower(data_get($node, 'Status')),
                        'availability'          => strtolower(data_get($node, 'Availability', 'active')),
                        'ip'                    => data_get($inspect, 'Status.Addr', ''),
                        'engine_version'        => data_get($node, 'EngineVersion', ''),
                        'cpu_cores'             => data_get($inspect, 'Description.Resources.NanoCPUs', 0) / 1e9,
                        'memory_bytes'          => data_get($inspect, 'Description.Resources.MemoryBytes', 0),
                        'labels'                => data_get($inspect, 'Spec.Labels', []),
                        'is_leader'             => data_get($node, 'ManagerStatus') === 'Leader',
                        'manager_reachability'  => data_get($inspect, 'ManagerStatus.Reachability'),
                        'platform_os'           => data_get($inspect, 'Description.Platform.OS', 'linux'),
                        'platform_arch'         => data_get($inspect, 'Description.Platform.Architecture', 'amd64'),
                    ]);
                }

                return $nodes;
            }
        );
    }

    // ── Services ──────────────────────────────────────────

    public function getServices(): Collection
    {
        return Cache::remember(
            "cluster:{$this->cluster->id}:services",
            $this->cacheTtl,
            function () {
                $servicesJson = $this->exec(
                    'docker service ls --format "{{json .}}" 2>/dev/null'
                );

                return collect(explode("\n", trim($servicesJson)))
                    ->filter()
                    ->map(function ($line) {
                        $svc = json_decode($line, true);
                        if (!$svc) return null;

                        // Parse "3/5" replicas format
                        $replicas = data_get($svc, 'Replicas', '0/0');
                        [$running, $desired] = array_map('intval', explode('/', $replicas));

                        return [
                            'id'               => data_get($svc, 'ID'),
                            'name'             => data_get($svc, 'Name'),
                            'image'            => data_get($svc, 'Image'),
                            'mode'             => data_get($svc, 'Mode'),
                            'replicas_running' => $running,
                            'replicas_desired' => $desired,
                            'ports'            => data_get($svc, 'Ports', ''),
                            'updated_at'       => null, // populated from inspect if needed
                        ];
                    })
                    ->filter()
                    ->values();
            }
        );
    }

    public function getServiceTasks(string $serviceId): Collection
    {
        $tasksJson = $this->exec(
            "docker service ps {$this->escape($serviceId)} --format '{{json .}}' --no-trunc 2>/dev/null"
        );

        return collect(explode("\n", trim($tasksJson)))
            ->filter()
            ->map(function ($line) {
                $task = json_decode($line, true);
                if (!$task) return null;

                return [
                    'id'            => data_get($task, 'ID'),
                    'name'          => data_get($task, 'Name'),
                    'node'          => data_get($task, 'Node'),
                    'status'        => strtolower(data_get($task, 'CurrentState', '')),
                    'desired_state' => strtolower(data_get($task, 'DesiredState', '')),
                    'error'         => data_get($task, 'Error', ''),
                    'ports'         => data_get($task, 'Ports', ''),
                    'image'         => data_get($task, 'Image', ''),
                ];
            })
            ->filter()
            ->values();
    }

    // ── Helpers ───────────────────────────────────────────

    private function exec(string $command): string
    {
        return instant_remote_process(
            [$command],
            $this->managerServer,
            throwError: false
        );
    }

    private function inspectNode(string $nodeId): array
    {
        $json = $this->exec(
            "docker node inspect {$this->escape($nodeId)} --format '{{json .}}' 2>/dev/null"
        );
        return json_decode($json, true) ?? [];
    }

    private function escape(string $value): string
    {
        return escapeshellarg($value);
    }

    // ... Phase 2 and 3 methods follow same patterns
}
```

**Critical implementation notes:**
- All Docker commands go through `instant_remote_process()` (Coolify's SSH executor)
- JSON format (`--format '{{json .}}'`) for reliable parsing
- `escapeshellarg()` on ALL interpolated values (prevent command injection)
- Cache with configurable TTL (default 30s) to avoid SSH storms
- Error suppression with `2>/dev/null` for graceful degradation

### 1.3 Cluster Model

**File:** `src/Models/Cluster.php`

```php
<?php

namespace AmirhMoradi\CoolifyEnhanced\Models;

use App\Models\BaseModel;
use App\Models\Server;
use App\Models\Team;
use AmirhMoradi\CoolifyEnhanced\Contracts\ClusterDriverInterface;
use AmirhMoradi\CoolifyEnhanced\Drivers\SwarmClusterDriver;

class Cluster extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'encrypted:array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Cluster $cluster) {
            if (empty($cluster->uuid)) {
                $cluster->uuid = (string) new \Cuid2\Cuid2(7);
            }
        });
    }

    // ── Relationships ─────────────────────────────────────

    public function managerServer()
    {
        return $this->belongsTo(Server::class, 'manager_server_id');
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function events()
    {
        return $this->hasMany(ClusterEvent::class);
    }

    // ── Driver ────────────────────────────────────────────

    public function driver(): ClusterDriverInterface
    {
        return match ($this->type) {
            'swarm' => app(SwarmClusterDriver::class)->setCluster($this),
            // 'kubernetes' => app(KubernetesClusterDriver::class)->setCluster($this),
            default => throw new \InvalidArgumentException("Unknown cluster type: {$this->type}"),
        };
    }

    // ── Status ────────────────────────────────────────────

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    public function isDegraded(): bool
    {
        return $this->status === 'degraded';
    }

    public function isSwarm(): bool
    {
        return $this->type === 'swarm';
    }

    // ── Scopes ────────────────────────────────────────────

    public function scopeOwnedByTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}
```

### 1.4 Cluster Detection Service

**File:** `src/Services/ClusterDetectionService.php`

Responsible for auto-detecting Swarm clusters from servers and creating/updating Cluster records.

```php
<?php

namespace AmirhMoradi\CoolifyEnhanced\Services;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

class ClusterDetectionService
{
    /**
     * Detect and register clusters from all Swarm manager servers belonging to a team.
     */
    public function detectClusters(int $teamId): array
    {
        $managers = Server::where('team_id', $teamId)
            ->whereHas('settings', fn ($q) => $q->where('is_swarm_manager', true))
            ->get();

        $detected = [];

        foreach ($managers as $server) {
            try {
                $cluster = $this->detectFromServer($server);
                if ($cluster) {
                    $detected[] = $cluster;
                }
            } catch (\Exception $e) {
                Log::warning("Cluster detection failed for server {$server->name}: {$e->getMessage()}");
            }
        }

        return $detected;
    }

    /**
     * Detect cluster from a specific Swarm manager server.
     */
    public function detectFromServer(Server $server): ?Cluster
    {
        if (!$server->isSwarmManager()) {
            return null;
        }

        // Query docker info for Swarm metadata
        $dockerInfoJson = instant_remote_process(
            ['docker info --format "{{json .Swarm}}"'],
            $server,
            throwError: false
        );

        $swarmInfo = json_decode($dockerInfoJson, true);
        if (!$swarmInfo || data_get($swarmInfo, 'LocalNodeState') !== 'active') {
            return null;
        }

        $swarmId = data_get($swarmInfo, 'Cluster.ID');
        if (!$swarmId) {
            return null;
        }

        // Find or create cluster by Swarm ID
        $cluster = Cluster::where('team_id', $server->team_id)
            ->whereJsonContains('metadata->swarm_id', $swarmId)
            ->first();

        if (!$cluster) {
            $cluster = Cluster::create([
                'name'              => $server->name . ' Cluster',
                'type'              => 'swarm',
                'status'            => 'unknown',
                'manager_server_id' => $server->id,
                'team_id'           => $server->team_id,
                'metadata'          => ['swarm_id' => $swarmId],
            ]);
        }

        // Link server to cluster
        $server->update(['cluster_id' => $cluster->id]);

        // Sync full metadata
        $this->syncClusterMetadata($cluster);

        return $cluster;
    }

    /**
     * Sync cluster metadata and discover all nodes.
     */
    public function syncClusterMetadata(Cluster $cluster): void
    {
        $driver = $cluster->driver();

        // Get cluster info
        $info = $driver->getClusterInfo();
        $health = $driver->getClusterHealth();
        $nodes = $driver->getNodes();

        // Get join tokens (encrypted in settings)
        try {
            $tokens = $driver->getJoinTokens();
        } catch (\Exception $e) {
            $tokens = ['worker' => null, 'manager' => null];
        }

        // Update metadata
        $cluster->update([
            'status'   => $health,
            'metadata' => array_merge($cluster->metadata ?? [], [
                'swarm_id'       => data_get($info, 'id'),
                'swarm_created'  => data_get($info, 'created'),
                'docker_version' => $nodes->first()['engine_version'] ?? null,
                'node_count'     => $nodes->count(),
                'manager_count'  => $nodes->where('role', 'manager')->count(),
                'worker_count'   => $nodes->where('role', 'worker')->count(),
                'last_sync_at'   => now()->toIso8601String(),
            ]),
            'settings' => array_merge($cluster->settings ?? [], [
                'swarm_join_token_worker'  => data_get($tokens, 'worker'),
                'swarm_join_token_manager' => data_get($tokens, 'manager'),
            ]),
        ]);

        // Auto-link known servers to this cluster
        $this->linkKnownServers($cluster, $nodes);
    }

    /**
     * Match discovered Swarm nodes to existing Coolify Server records by IP.
     */
    private function linkKnownServers(Cluster $cluster, $nodes): void
    {
        foreach ($nodes as $node) {
            $ip = $node['ip'] ?? null;
            if (!$ip) continue;

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
```

### 1.5 Cluster Sync Job

**File:** `src/Jobs/ClusterSyncJob.php`

Periodic background job that refreshes cluster metadata. Registered in the service provider's scheduler.

```php
<?php

namespace AmirhMoradi\CoolifyEnhanced\Jobs;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Services\ClusterDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClusterSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $clusterId,
    ) {}

    public function handle(ClusterDetectionService $service): void
    {
        if (!config('coolify-enhanced.enabled')) return;
        if (!config('coolify-enhanced.cluster_management', false)) return;

        $cluster = Cluster::find($this->clusterId);
        if (!$cluster) return;

        $service->syncClusterMetadata($cluster);
    }

    public $tries = 1;
    public $timeout = 60;
}
```

### 1.6 Livewire Components

#### ClusterList Component

**File:** `src/Livewire/ClusterList.php`

```php
<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Services\ClusterDetectionService;
use Livewire\Component;

class ClusterList extends Component
{
    public $clusters = [];

    public function mount()
    {
        $this->loadClusters();
    }

    public function loadClusters()
    {
        $this->clusters = Cluster::ownedByTeam(currentTeam()->id)
            ->with('managerServer')
            ->get()
            ->toArray();
    }

    public function autoDetect()
    {
        $service = app(ClusterDetectionService::class);
        $detected = $service->detectClusters(currentTeam()->id);

        if (count($detected) > 0) {
            $this->dispatch('success', 'Detected ' . count($detected) . ' cluster(s).');
        } else {
            $this->dispatch('info', 'No new Swarm clusters detected. Ensure your servers are configured as Swarm managers.');
        }

        $this->loadClusters();
    }

    public function render()
    {
        return view('enhanced::livewire.cluster-list');
    }
}
```

#### ClusterDashboard Component

**File:** `src/Livewire/ClusterDashboard.php`

```php
<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Livewire\Component;

class ClusterDashboard extends Component
{
    public Cluster $cluster;
    public array $nodes = [];
    public array $services = [];
    public array $clusterInfo = [];
    public string $activeTab = 'overview'; // overview, services, visualizer, events
    public string $visualizerMode = 'grid'; // grid, topology

    // Auto-refresh every 30 seconds
    public int $pollInterval = 30;

    public function mount(string $cluster_uuid)
    {
        $this->cluster = Cluster::ownedByTeam(currentTeam()->id)
            ->where('uuid', $cluster_uuid)
            ->firstOrFail();

        $this->refreshData();
    }

    public function refreshData()
    {
        $driver = $this->cluster->driver();

        $this->clusterInfo = $driver->getClusterInfo();
        $this->nodes = $driver->getNodes()->toArray();
        $this->services = $driver->getServices()->toArray();
    }

    public function getTasksForNode(string $nodeId): array
    {
        return $this->cluster->driver()->getNodeTasks($nodeId)->toArray();
    }

    public function getTasksForService(string $serviceId): array
    {
        return $this->cluster->driver()->getServiceTasks($serviceId)->toArray();
    }

    public function render()
    {
        return view('enhanced::livewire.cluster-dashboard');
    }
}
```

### 1.7 Blade Views (Key Layouts)

#### cluster-list.blade.php

```blade
<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-bold">Clusters</h2>
        <div class="flex gap-2">
            <x-forms.button wire:click="autoDetect">Auto-detect from Servers</x-forms.button>
        </div>
    </div>

    @if(count($clusters) === 0)
        <div class="text-center py-12">
            <p class="text-neutral-400">No clusters found.</p>
            <p class="text-neutral-500 text-sm mt-2">
                Mark a server as "Swarm Manager" and click Auto-detect, or create a cluster manually.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($clusters as $cluster)
                <a href="/cluster/{{ $cluster['uuid'] }}"
                   class="block p-4 rounded-lg bg-coolgray-200 hover:bg-coolgray-300 transition">
                    <div class="flex items-center gap-2 mb-2">
                        {{-- Status indicator --}}
                        <span class="w-2 h-2 rounded-full {{ match($cluster['status']) {
                            'healthy' => 'bg-green-500',
                            'degraded' => 'bg-yellow-500',
                            'unreachable' => 'bg-red-500',
                            default => 'bg-neutral-500'
                        } }}"></span>
                        <h3 class="font-semibold">{{ $cluster['name'] }}</h3>
                    </div>
                    <div class="text-sm text-neutral-400 space-y-1">
                        <p>{{ ucfirst($cluster['type']) }} &middot;
                           {{ data_get($cluster, 'metadata.node_count', '?') }} nodes &middot;
                           {{ data_get($cluster, 'metadata.service_count', '?') }} services</p>
                        <p class="text-xs">
                            Last sync: {{ data_get($cluster, 'metadata.last_sync_at')
                                ? \Carbon\Carbon::parse(data_get($cluster, 'metadata.last_sync_at'))->diffForHumans()
                                : 'Never' }}
                        </p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
```

#### cluster-dashboard.blade.php (overview tab)

```blade
<div wire:poll.{{ $pollInterval }}s="refreshData">
    {{-- Header --}}
    <div class="flex items-center gap-3 mb-6">
        <a href="/clusters" class="text-neutral-400 hover:text-white">&larr;</a>
        <h2 class="text-xl font-bold">{{ $cluster->name }}</h2>
        <span class="px-2 py-0.5 rounded text-xs font-medium {{ match($cluster->status) {
            'healthy' => 'bg-green-500/20 text-green-400',
            'degraded' => 'bg-yellow-500/20 text-yellow-400',
            'unreachable' => 'bg-red-500/20 text-red-400',
            default => 'bg-neutral-500/20 text-neutral-400'
        } }}">{{ ucfirst($cluster->status) }}</span>
        <span class="px-2 py-0.5 rounded text-xs bg-coolgray-300 text-neutral-400">
            {{ ucfirst($cluster->type) }}
        </span>
    </div>

    {{-- Tabs --}}
    <div class="flex border-b border-coolgray-300 mb-6">
        @foreach(['overview' => 'Overview', 'services' => 'Services', 'visualizer' => 'Visualizer', 'events' => 'Events'] as $tab => $label)
            <button wire:click="$set('activeTab', '{{ $tab }}')"
                class="px-4 py-2 text-sm border-b-2 transition {{ $activeTab === $tab
                    ? 'border-white text-white'
                    : 'border-transparent text-neutral-400 hover:text-white' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Overview Tab --}}
    @if($activeTab === 'overview')
        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            {{-- Cluster Status Card --}}
            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wide mb-1">Cluster</p>
                <p class="text-lg font-semibold flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full {{ $cluster->status === 'healthy' ? 'bg-green-500' : ($cluster->status === 'degraded' ? 'bg-yellow-500' : 'bg-red-500') }}"></span>
                    {{ ucfirst($cluster->status) }}
                </p>
                <p class="text-xs text-neutral-500 mt-1">
                    Docker {{ data_get($clusterInfo, 'version', '?') }}
                </p>
            </div>

            {{-- Nodes Card --}}
            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wide mb-1">Nodes</p>
                <p class="text-lg font-semibold">{{ count($nodes) }} total</p>
                <p class="text-xs text-neutral-500 mt-1">
                    {{ collect($nodes)->where('role', 'manager')->count() }} managers &middot;
                    {{ collect($nodes)->where('role', 'worker')->count() }} workers
                </p>
            </div>

            {{-- Services Card --}}
            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wide mb-1">Services</p>
                <p class="text-lg font-semibold">{{ count($services) }} running</p>
                <p class="text-xs text-neutral-500 mt-1">
                    {{ collect($services)->where('replicas_running', '<', collect($services)->pluck('replicas_desired'))->count() }} degraded
                </p>
            </div>

            {{-- Tasks Card --}}
            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wide mb-1">Tasks</p>
                <p class="text-lg font-semibold">
                    {{ collect($services)->sum('replicas_running') }} running
                </p>
                <p class="text-xs text-neutral-500 mt-1">
                    {{ collect($services)->sum('replicas_desired') }} desired
                </p>
            </div>
        </div>

        {{-- Node Listing Table --}}
        <div class="rounded-lg bg-coolgray-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-coolgray-300">
                <h3 class="font-semibold text-sm">Nodes</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-coolgray-300">
                        <tr>
                            <th class="px-4 py-2 text-left text-neutral-400">Status</th>
                            <th class="px-4 py-2 text-left text-neutral-400">Hostname</th>
                            <th class="px-4 py-2 text-left text-neutral-400">Role</th>
                            <th class="px-4 py-2 text-left text-neutral-400">IP</th>
                            <th class="px-4 py-2 text-left text-neutral-400">Docker</th>
                            <th class="px-4 py-2 text-left text-neutral-400">CPU</th>
                            <th class="px-4 py-2 text-left text-neutral-400">Memory</th>
                            <th class="px-4 py-2 text-left text-neutral-400">Availability</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($nodes as $node)
                        <tr class="border-b border-coolgray-300 hover:bg-coolgray-300/50">
                            <td class="px-4 py-2">
                                <span class="w-2 h-2 rounded-full inline-block {{ $node['status'] === 'ready' ? 'bg-green-500' : 'bg-red-500' }}"></span>
                                {{ ucfirst($node['status']) }}
                            </td>
                            <td class="px-4 py-2 font-medium">
                                {{ $node['hostname'] }}
                                @if($node['is_leader'])
                                    <span class="text-xs text-yellow-400 ml-1">(Leader)</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <span class="px-1.5 py-0.5 rounded text-xs {{ $node['role'] === 'manager' ? 'bg-blue-500/20 text-blue-400' : 'bg-neutral-500/20 text-neutral-400' }}">
                                    {{ ucfirst($node['role']) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $node['ip'] }}</td>
                            <td class="px-4 py-2">{{ $node['engine_version'] }}</td>
                            <td class="px-4 py-2">{{ $node['cpu_cores'] }} cores</td>
                            <td class="px-4 py-2">{{ round($node['memory_bytes'] / 1073741824, 1) }} GB</td>
                            <td class="px-4 py-2">
                                <span class="px-1.5 py-0.5 rounded text-xs {{ match($node['availability']) {
                                    'active' => 'bg-green-500/20 text-green-400',
                                    'drain' => 'bg-yellow-500/20 text-yellow-400',
                                    'pause' => 'bg-orange-500/20 text-orange-400',
                                    default => 'bg-neutral-500/20 text-neutral-400'
                                } }}">
                                    {{ ucfirst($node['availability']) }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Services Tab --}}
    @if($activeTab === 'services')
        @livewire('enhanced::cluster-service-viewer', ['clusterId' => $cluster->id])
    @endif

    {{-- Visualizer Tab --}}
    @if($activeTab === 'visualizer')
        @livewire('enhanced::cluster-visualizer', [
            'clusterId' => $cluster->id,
            'mode' => $visualizerMode,
        ])
    @endif

    {{-- Events Tab --}}
    @if($activeTab === 'events')
        @livewire('enhanced::cluster-events', ['clusterId' => $cluster->id])
    @endif
</div>
```

### 1.8 Route Registration

**File changes:** `routes/web.php`

```php
// Cluster Management Routes
Route::middleware(['auth'])->group(function () {
    Route::get('/clusters', \AmirhMoradi\CoolifyEnhanced\Livewire\ClusterList::class)
        ->name('clusters.index');
    Route::get('/cluster/{cluster_uuid}', \AmirhMoradi\CoolifyEnhanced\Livewire\ClusterDashboard::class)
        ->name('clusters.show');
});
```

**File changes:** `routes/api.php`

```php
// Cluster Management API
Route::prefix('clusters')->group(function () {
    Route::get('/', [ClusterController::class, 'index']);
    Route::post('/', [ClusterController::class, 'create']);
    Route::get('/{uuid}', [ClusterController::class, 'show']);
    Route::patch('/{uuid}', [ClusterController::class, 'update']);
    Route::delete('/{uuid}', [ClusterController::class, 'destroy']);
    Route::post('/{uuid}/sync', [ClusterController::class, 'sync']);
    Route::get('/{uuid}/nodes', [ClusterController::class, 'nodes']);
    Route::get('/{uuid}/services', [ClusterController::class, 'services']);
    Route::get('/{uuid}/services/{serviceId}/tasks', [ClusterController::class, 'serviceTasks']);
    Route::get('/{uuid}/events', [ClusterController::class, 'events']);
    Route::get('/{uuid}/visualizer', [ClusterController::class, 'visualizer']);
]);
```

### 1.9 Service Provider Registration

**File changes:** `src/CoolifyEnhancedServiceProvider.php`

Add to the `boot()` method's `$this->app->booted()` callback:

```php
// Cluster Management
if (config('coolify-enhanced.cluster_management', false)) {
    $this->registerClusterManagement();
}
```

New method:

```php
private function registerClusterManagement(): void
{
    // Register Livewire components
    \Livewire\Livewire::component('enhanced::cluster-list', \AmirhMoradi\CoolifyEnhanced\Livewire\ClusterList::class);
    \Livewire\Livewire::component('enhanced::cluster-dashboard', \AmirhMoradi\CoolifyEnhanced\Livewire\ClusterDashboard::class);
    \Livewire\Livewire::component('enhanced::cluster-service-viewer', \AmirhMoradi\CoolifyEnhanced\Livewire\ClusterServiceViewer::class);
    \Livewire\Livewire::component('enhanced::cluster-visualizer', \AmirhMoradi\CoolifyEnhanced\Livewire\ClusterVisualizer::class);
    \Livewire\Livewire::component('enhanced::cluster-events', \AmirhMoradi\CoolifyEnhanced\Livewire\ClusterEvents::class);

    // Register policy
    Gate::policy(\AmirhMoradi\CoolifyEnhanced\Models\Cluster::class, \AmirhMoradi\CoolifyEnhanced\Policies\ClusterPolicy::class);

    // Register periodic sync
    $this->app->booted(function () {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $schedule->call(function () {
            Cluster::where('status', '!=', 'unreachable')->each(function ($cluster) {
                ClusterSyncJob::dispatch($cluster->id);
            });
        })->everyMinute();
    });
}
```

### 1.10 Configuration

**File changes:** `config/coolify-enhanced.php`

```php
// Add to existing config array:
'cluster_management' => env('COOLIFY_CLUSTER_MANAGEMENT', false),
'cluster_sync_interval' => env('COOLIFY_CLUSTER_SYNC_INTERVAL', 60), // seconds
'cluster_cache_ttl' => env('COOLIFY_CLUSTER_CACHE_TTL', 30), // seconds
'cluster_event_retention_days' => env('COOLIFY_CLUSTER_EVENT_RETENTION', 7),
```

### 1.11 Navigation Integration

**Approach:** Modify the sidebar overlay to conditionally show "Clusters" link when clusters exist.

The sidebar navigation in Coolify is at `resources/views/layouts/sidebar.blade.php`. We'll use our existing middleware injection approach to add the Clusters link.

**File changes:** `src/Http/Middleware/InjectPermissionsUI.php` (or new middleware)

Add cluster navigation injection for any page, inserting a "Clusters" link in the sidebar when clusters exist for the current team.

---

## Phase 2: Node & Service Management (Write Operations)

### 2.1 Node Management Actions

Add these methods to `SwarmClusterDriver`:

```php
public function updateNodeAvailability(string $nodeId, string $availability): bool
{
    $valid = ['active', 'pause', 'drain'];
    if (!in_array($availability, $valid)) {
        throw new \InvalidArgumentException("Invalid availability: {$availability}");
    }

    $result = $this->exec(
        "docker node update --availability {$this->escape($availability)} {$this->escape($nodeId)} 2>&1"
    );

    return !str_contains($result, 'Error');
}

public function promoteNode(string $nodeId): bool
{
    $result = $this->exec("docker node promote {$this->escape($nodeId)} 2>&1");
    return !str_contains($result, 'Error');
}

public function demoteNode(string $nodeId): bool
{
    // Safety: check this isn't the last manager
    $managers = $this->getNodes()->where('role', 'manager');
    if ($managers->count() <= 1) {
        throw new \RuntimeException('Cannot demote the last manager node.');
    }

    $result = $this->exec("docker node demote {$this->escape($nodeId)} 2>&1");
    return !str_contains($result, 'Error');
}

public function removeNode(string $nodeId, bool $force = false): bool
{
    $forceFlag = $force ? '--force' : '';
    $result = $this->exec("docker node rm {$forceFlag} {$this->escape($nodeId)} 2>&1");
    return !str_contains($result, 'Error');
}

public function updateNodeLabels(string $nodeId, array $add = [], array $remove = []): bool
{
    $parts = [];
    foreach ($add as $key => $value) {
        $parts[] = '--label-add ' . $this->escape("{$key}={$value}");
    }
    foreach ($remove as $key) {
        $parts[] = '--label-rm ' . $this->escape($key);
    }

    if (empty($parts)) return true;

    $result = $this->exec(
        "docker node update " . implode(' ', $parts) . " {$this->escape($nodeId)} 2>&1"
    );
    return !str_contains($result, 'Error');
}

public function getJoinTokens(): array
{
    $workerToken = trim($this->exec('docker swarm join-token -q worker 2>/dev/null'));
    $managerToken = trim($this->exec('docker swarm join-token -q manager 2>/dev/null'));

    return [
        'worker'  => $workerToken ?: null,
        'manager' => $managerToken ?: null,
    ];
}
```

### 2.2 Add Node Wizard Component

**File:** `src/Livewire/ClusterAddNode.php`

```php
class ClusterAddNode extends Component
{
    public Cluster $cluster;
    public string $role = 'worker'; // worker or manager
    public string $joinCommand = '';
    public bool $showWizard = false;
    public int $step = 1;

    public function generateJoinCommand()
    {
        $driver = $this->cluster->driver();
        $tokens = $driver->getJoinTokens();
        $managerIp = $this->cluster->managerServer->ip;

        $token = $this->role === 'manager' ? $tokens['manager'] : $tokens['worker'];

        $this->joinCommand = "docker swarm join --token {$token} {$managerIp}:2377";
        $this->step = 2;
    }

    public function checkNewNode()
    {
        // Poll docker node ls for new nodes
        $driver = $this->cluster->driver();
        $nodes = $driver->getNodes();

        // Compare with known nodes
        $knownCount = data_get($this->cluster->metadata, 'node_count', 0);
        if ($nodes->count() > $knownCount) {
            $this->step = 3; // Success!
            // Re-sync cluster metadata
            app(ClusterDetectionService::class)->syncClusterMetadata($this->cluster);
            $this->dispatch('success', 'New node joined the cluster!');
        }
    }
}
```

### 2.3 Node Manager Component (Actions)

**File:** `src/Livewire/ClusterNodeManager.php`

Extends the read-only node table from Phase 1 with action dropdowns:

```php
class ClusterNodeManager extends Component
{
    public Cluster $cluster;
    public array $nodes = [];
    public ?string $selectedNodeId = null;
    public array $selectedNodeLabels = [];
    public string $newLabelKey = '';
    public string $newLabelValue = '';

    public function drainNode(string $nodeId)
    {
        $this->authorize('update', $this->cluster);
        $driver = $this->cluster->driver();

        // Show task count warning
        $tasks = $driver->getNodeTasks($nodeId);
        $runningTasks = $tasks->where('status', 'running')->count();

        if ($runningTasks > 0) {
            // Confirmation is handled in Blade via x-modal
        }

        $driver->updateNodeAvailability($nodeId, 'drain');
        $this->dispatch('success', "Node draining. {$runningTasks} tasks will be rescheduled.");
        $this->refreshNodes();
    }

    public function activateNode(string $nodeId)
    {
        $this->authorize('update', $this->cluster);
        $this->cluster->driver()->updateNodeAvailability($nodeId, 'active');
        $this->dispatch('success', 'Node activated.');
        $this->refreshNodes();
    }

    public function promoteNode(string $nodeId)
    {
        $this->authorize('update', $this->cluster);
        $this->cluster->driver()->promoteNode($nodeId);
        $this->dispatch('success', 'Node promoted to manager.');
        $this->refreshNodes();
    }

    public function demoteNode(string $nodeId)
    {
        $this->authorize('update', $this->cluster);
        try {
            $this->cluster->driver()->demoteNode($nodeId);
            $this->dispatch('success', 'Node demoted to worker.');
        } catch (\RuntimeException $e) {
            $this->dispatch('error', $e->getMessage());
        }
        $this->refreshNodes();
    }

    public function removeNode(string $nodeId)
    {
        $this->authorize('delete', $this->cluster);
        // Must be drained first
        $node = collect($this->nodes)->firstWhere('id', $nodeId);
        if ($node && $node['availability'] !== 'drain') {
            $this->dispatch('error', 'Node must be drained before removal.');
            return;
        }
        $this->cluster->driver()->removeNode($nodeId);
        $this->dispatch('success', 'Node removed from cluster.');
        $this->refreshNodes();
    }

    public function addLabel()
    {
        if (!$this->selectedNodeId || !$this->newLabelKey) return;
        $this->authorize('update', $this->cluster);

        $this->cluster->driver()->updateNodeLabels(
            $this->selectedNodeId,
            [$this->newLabelKey => $this->newLabelValue],
        );

        $this->newLabelKey = '';
        $this->newLabelValue = '';
        $this->refreshNodeLabels();
    }

    public function removeLabel(string $key)
    {
        if (!$this->selectedNodeId) return;
        $this->authorize('update', $this->cluster);

        $this->cluster->driver()->updateNodeLabels(
            $this->selectedNodeId,
            remove: [$key],
        );

        $this->refreshNodeLabels();
    }
}
```

### 2.4 Per-Resource Swarm Configuration

**File:** `src/Livewire/SwarmConfigForm.php`

Replaces Coolify's raw YAML textarea with structured form. This is rendered as an overlay of `resources/views/livewire/project/application/swarm.blade.php`.

```php
class SwarmConfigForm extends Component
{
    public $application;

    // Replicas
    public string $mode = 'replicated'; // replicated or global
    public int $replicas = 1;
    public bool $workerOnly = false;

    // Update policy
    public int $updateParallelism = 1;
    public string $updateDelay = '10s';
    public string $updateFailureAction = 'rollback'; // rollback, pause, continue
    public string $updateMonitor = '5s';
    public string $updateOrder = 'start-first'; // start-first, stop-first
    public float $updateMaxFailureRatio = 0;

    // Rollback policy
    public int $rollbackParallelism = 1;
    public string $rollbackFailureAction = 'pause';
    public string $rollbackOrder = 'stop-first';

    // Placement constraints (array of {field, operator, value})
    public array $constraints = [];

    // Placement preferences (array of {strategy, field})
    public array $preferences = [];

    // Resource limits
    public ?float $cpuLimit = null;
    public ?int $memoryLimitMb = null;
    public ?float $cpuReservation = null;
    public ?int $memoryReservationMb = null;

    // Health check
    public ?string $healthCmd = null;
    public string $healthInterval = '30s';
    public string $healthTimeout = '10s';
    public int $healthRetries = 3;
    public string $healthStartPeriod = '40s';

    // Restart policy
    public string $restartCondition = 'on-failure'; // none, on-failure, any
    public string $restartDelay = '5s';
    public int $restartMaxAttempts = 3;
    public string $restartWindow = '120s';

    public bool $showAdvanced = false;

    public function mount()
    {
        // Load from application model
        $this->replicas = $this->application->swarm_replicas ?? 1;
        $this->workerOnly = $this->application->settings->is_swarm_only_worker_nodes ?? false;

        // Decode existing constraints from base64 YAML
        if ($this->application->swarm_placement_constraints) {
            $yaml = base64_decode($this->application->swarm_placement_constraints);
            $this->parseConstraintsFromYaml($yaml);
        }
    }

    /**
     * Generate the deploy YAML section from structured form inputs.
     */
    public function generateDeployYaml(): string
    {
        $deploy = [
            'mode' => $this->mode,
        ];

        if ($this->mode === 'replicated') {
            $deploy['replicas'] = $this->replicas;
        }

        // Update config
        $deploy['update_config'] = [
            'parallelism'       => $this->updateParallelism,
            'delay'             => $this->updateDelay,
            'failure_action'    => $this->updateFailureAction,
            'monitor'           => $this->updateMonitor,
            'order'             => $this->updateOrder,
            'max_failure_ratio' => $this->updateMaxFailureRatio,
        ];

        // Rollback config
        $deploy['rollback_config'] = [
            'parallelism'    => $this->rollbackParallelism,
            'failure_action' => $this->rollbackFailureAction,
            'order'          => $this->rollbackOrder,
        ];

        // Placement
        $placement = [];
        if ($this->workerOnly) {
            $placement['constraints'][] = 'node.role == worker';
        }
        foreach ($this->constraints as $c) {
            $placement['constraints'][] = "{$c['field']} {$c['operator']} {$c['value']}";
        }
        foreach ($this->preferences as $p) {
            $placement['preferences'][] = ['spread' => $p['field']];
        }
        if (!empty($placement)) {
            $deploy['placement'] = $placement;
        }

        // Resources
        $resources = [];
        if ($this->cpuLimit || $this->memoryLimitMb) {
            $resources['limits'] = [];
            if ($this->cpuLimit) $resources['limits']['cpus'] = (string) $this->cpuLimit;
            if ($this->memoryLimitMb) $resources['limits']['memory'] = $this->memoryLimitMb . 'M';
        }
        if ($this->cpuReservation || $this->memoryReservationMb) {
            $resources['reservations'] = [];
            if ($this->cpuReservation) $resources['reservations']['cpus'] = (string) $this->cpuReservation;
            if ($this->memoryReservationMb) $resources['reservations']['memory'] = $this->memoryReservationMb . 'M';
        }
        if (!empty($resources)) {
            $deploy['resources'] = $resources;
        }

        return \Symfony\Component\Yaml\Yaml::dump(['deploy' => $deploy], 6, 2);
    }

    public function submit()
    {
        $this->authorize('update', $this->application);

        // Save structured data
        $this->application->swarm_replicas = $this->replicas;
        $this->application->swarm_placement_constraints = base64_encode(
            $this->generateDeployYaml()
        );
        $this->application->settings->is_swarm_only_worker_nodes = $this->workerOnly;
        $this->application->settings->save();
        $this->application->save();

        $this->dispatch('success', 'Swarm configuration saved. Redeploy to apply changes.');
    }
}
```

### 2.5 Service Operations Component

**File:** `src/Livewire/ClusterServiceViewer.php`

```php
class ClusterServiceViewer extends Component
{
    public int $clusterId;
    public array $services = [];
    public ?string $expandedServiceId = null;
    public array $expandedTasks = [];

    public function mount(int $clusterId)
    {
        $this->clusterId = $clusterId;
        $this->refreshServices();
    }

    public function refreshServices()
    {
        $cluster = Cluster::findOrFail($this->clusterId);
        $this->services = $cluster->driver()->getServices()->toArray();
    }

    public function toggleServiceExpand(string $serviceId)
    {
        if ($this->expandedServiceId === $serviceId) {
            $this->expandedServiceId = null;
            $this->expandedTasks = [];
        } else {
            $this->expandedServiceId = $serviceId;
            $cluster = Cluster::findOrFail($this->clusterId);
            $this->expandedTasks = $cluster->driver()
                ->getServiceTasks($serviceId)
                ->toArray();
        }
    }

    // Phase 2 actions
    public function scaleService(string $serviceId, int $replicas)
    {
        $cluster = Cluster::findOrFail($this->clusterId);
        $this->authorize('update', $cluster);
        $cluster->driver()->scaleService($serviceId, $replicas);
        $this->dispatch('success', "Service scaled to {$replicas} replicas.");
        $this->refreshServices();
    }

    public function rollbackService(string $serviceId)
    {
        $cluster = Cluster::findOrFail($this->clusterId);
        $this->authorize('update', $cluster);
        $cluster->driver()->rollbackService($serviceId);
        $this->dispatch('success', 'Service rollback initiated.');
        $this->refreshServices();
    }
}
```

---

## Phase 3: Swarm Primitives (Secrets, Configs, Events)

### 3.1 Secrets & Configs Models (DB Tracking)

These models track metadata locally for faster lookups and team-level access control. The actual secret/config data lives in Docker.

#### Migration: `create_swarm_secrets_table`

```php
Schema::create('swarm_secrets', function (Blueprint $table) {
    $table->id();
    $table->string('docker_id')->index();  // Docker's secret ID
    $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->json('labels')->nullable();
    $table->text('description')->nullable();
    $table->timestamp('docker_created_at')->nullable();
    $table->timestamp('docker_updated_at')->nullable();
    $table->timestamps();

    $table->unique(['cluster_id', 'docker_id']);
});
```

Same pattern for `swarm_configs` table, plus a `data` column (configs are not sensitive).

### 3.2 Secrets Management Component

**File:** `src/Livewire/ClusterSecrets.php`

```php
class ClusterSecrets extends Component
{
    public int $clusterId;
    public array $secrets = [];
    public bool $showCreateForm = false;
    public string $newName = '';
    public string $newValue = '';
    public array $newLabels = [];

    public function mount(int $clusterId)
    {
        $this->clusterId = $clusterId;
        $this->refreshSecrets();
    }

    public function refreshSecrets()
    {
        $cluster = Cluster::findOrFail($this->clusterId);
        $this->secrets = $cluster->driver()->getSecrets()->toArray();
    }

    public function createSecret()
    {
        $cluster = Cluster::findOrFail($this->clusterId);
        $this->authorize('update', $cluster);

        $this->validate([
            'newName'  => 'required|regex:/^[a-zA-Z0-9._-]+$/',
            'newValue' => 'required',
        ]);

        $dockerId = $cluster->driver()->createSecret($this->newName, $this->newValue, $this->newLabels);

        // Track in local DB
        SwarmSecret::create([
            'docker_id'  => $dockerId,
            'cluster_id' => $cluster->id,
            'name'       => $this->newName,
            'labels'     => $this->newLabels,
        ]);

        $this->reset(['newName', 'newValue', 'newLabels', 'showCreateForm']);
        $this->dispatch('success', "Secret '{$this->newName}' created.");
        $this->refreshSecrets();
    }

    public function rotateSecret(string $secretName)
    {
        // Docker secrets are immutable. "Rotation" means:
        // 1. Create new secret with suffix (e.g., db-password-v2)
        // 2. Update all services to reference new secret
        // 3. Remove old secret
        // This is complex — implement as a multi-step wizard
    }

    public function removeSecret(string $secretId)
    {
        $cluster = Cluster::findOrFail($this->clusterId);
        $this->authorize('delete', $cluster);

        $cluster->driver()->removeSecret($secretId);
        SwarmSecret::where('docker_id', $secretId)->delete();

        $this->dispatch('success', 'Secret removed.');
        $this->refreshSecrets();
    }
}
```

### 3.3 Event Collector Job

**File:** `src/Jobs/ClusterEventCollectorJob.php`

```php
class ClusterEventCollectorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $clusterId,
        public int $sinceSec = 60,
    ) {}

    public function handle(): void
    {
        if (!config('coolify-enhanced.cluster_management')) return;

        $cluster = Cluster::find($this->clusterId);
        if (!$cluster) return;

        $since = now()->subSeconds($this->sinceSec)->timestamp;
        $events = $cluster->driver()->getEvents($since);

        foreach ($events as $event) {
            ClusterEvent::create([
                'cluster_id'  => $cluster->id,
                'event_type'  => $event['type'],
                'action'      => $event['action'],
                'actor_id'    => $event['actor_id'] ?? null,
                'actor_name'  => $event['actor_name'] ?? null,
                'attributes'  => $event['attributes'] ?? null,
                'scope'       => $event['scope'] ?? null,
                'event_time'  => \Carbon\Carbon::createFromTimestamp($event['time']),
            ]);
        }
    }
}
```

### 3.4 Events Driver Method

```php
public function getEvents(int $since, ?string $filterType = null): Collection
{
    $filters = "--since={$since}";
    if ($filterType) {
        $filters .= " --filter type={$this->escape($filterType)}";
    }

    $eventsJson = $this->exec(
        "docker system events {$filters} --until " . time() . " --format '{{json .}}' 2>/dev/null"
    );

    return collect(explode("\n", trim($eventsJson)))
        ->filter()
        ->map(function ($line) {
            $event = json_decode($line, true);
            if (!$event) return null;

            return [
                'type'       => data_get($event, 'Type'),
                'action'     => data_get($event, 'Action'),
                'actor_id'   => data_get($event, 'Actor.ID'),
                'actor_name' => data_get($event, 'Actor.Attributes.name'),
                'attributes' => data_get($event, 'Actor.Attributes'),
                'scope'      => data_get($event, 'scope'),
                'time'       => data_get($event, 'time'),
            ];
        })
        ->filter()
        ->values();
}
```

---

## Phase 4: Integration & Polish

### 4.1 Coolify Resource ↔ Swarm Service Linking

When displaying a Coolify Application/Service that uses SwarmDocker destination, show its Swarm task status inline.

**Approach:** Add a small component `SwarmTaskStatus` that can be embedded in any resource page:

```php
class SwarmTaskStatus extends Component
{
    public $resource; // Application, Service, or Database
    public array $tasks = [];

    public function mount($resource)
    {
        $this->resource = $resource;
        $this->loadTasks();
    }

    public function loadTasks()
    {
        $server = $this->resource->destination->server;
        if (!$server->isSwarm()) {
            $this->tasks = [];
            return;
        }

        $cluster = $server->cluster;
        if (!$cluster) return;

        // Find the Swarm service matching this resource's UUID
        $serviceName = $this->resource->uuid;
        $this->tasks = $cluster->driver()
            ->getServiceTasks($serviceName)
            ->toArray();
    }
}
```

### 4.2 MCP Server Extensions

**New files:** `mcp-server/src/tools/clusters.ts`, `swarm-nodes.ts`, `swarm-services.ts`

```typescript
// clusters.ts - ~12 tools
export function registerClusterTools(server: McpServer, client: CoolifyClient) {
    server.tool('list-clusters', { /* ... */ }, async () => { /* GET /clusters */ });
    server.tool('get-cluster', { /* ... */ }, async ({ uuid }) => { /* GET /clusters/{uuid} */ });
    server.tool('sync-cluster', { /* ... */ }, async ({ uuid }) => { /* POST /clusters/{uuid}/sync */ });
    server.tool('get-cluster-nodes', { /* ... */ }, async ({ uuid }) => { /* GET /clusters/{uuid}/nodes */ });
    server.tool('get-cluster-services', { /* ... */ }, async ({ uuid }) => { /* GET /clusters/{uuid}/services */ });
    server.tool('get-cluster-visualizer', { /* ... */ }, async ({ uuid }) => { /* GET /clusters/{uuid}/visualizer */ });
    server.tool('drain-node', { /* ... */ }, async ({ uuid, nodeId }) => { /* POST */ });
    server.tool('activate-node', { /* ... */ }, async ({ uuid, nodeId }) => { /* POST */ });
    server.tool('scale-service', { /* ... */ }, async ({ uuid, serviceId, replicas }) => { /* POST */ });
    server.tool('rollback-service', { /* ... */ }, async ({ uuid, serviceId }) => { /* POST */ });
    server.tool('list-secrets', { /* ... */ }, async ({ uuid }) => { /* GET /clusters/{uuid}/secrets */ });
    server.tool('create-secret', { /* ... */ }, async ({ uuid, name, value }) => { /* POST */ });
}
```

### 4.3 Notification Integration

Leverage Coolify's existing notification infrastructure:

```php
// In ClusterSyncJob, after health check:
if ($previousStatus === 'healthy' && $health === 'degraded') {
    // Fire notification via Coolify's notification system
    $cluster->team->notify(new ClusterDegraded($cluster));
}

if ($previousStatus !== 'unreachable' && $health === 'unreachable') {
    $cluster->team->notify(new ClusterUnreachable($cluster));
}
```

---

## File Inventory

### New Files (Total: ~35 files)

| Phase | File | Lines (est.) |
|-------|------|-------------|
| 1 | `src/Contracts/ClusterDriverInterface.php` | 120 |
| 1 | `src/Drivers/SwarmClusterDriver.php` | 450 |
| 1 | `src/Models/Cluster.php` | 80 |
| 1 | `src/Models/ClusterEvent.php` | 30 |
| 1 | `src/Services/ClusterDetectionService.php` | 120 |
| 1 | `src/Jobs/ClusterSyncJob.php` | 40 |
| 1 | `src/Policies/ClusterPolicy.php` | 50 |
| 1 | `src/Livewire/ClusterList.php` | 60 |
| 1 | `src/Livewire/ClusterDashboard.php` | 100 |
| 1 | `src/Livewire/ClusterServiceViewer.php` | 80 |
| 1 | `src/Livewire/ClusterVisualizer.php` | 100 |
| 1 | `src/Livewire/ClusterEvents.php` | 60 |
| 1 | `src/Http/Controllers/Api/ClusterController.php` | 200 |
| 1 | `database/migrations/*_create_clusters_table.php` | 30 |
| 1 | `database/migrations/*_add_cluster_id_to_servers.php` | 15 |
| 1 | `database/migrations/*_create_cluster_events_table.php` | 25 |
| 1 | `resources/views/livewire/cluster-list.blade.php` | 60 |
| 1 | `resources/views/livewire/cluster-dashboard.blade.php` | 250 |
| 1 | `resources/views/livewire/cluster-service-viewer.blade.php` | 120 |
| 1 | `resources/views/livewire/cluster-visualizer.blade.php` | 200 |
| 1 | `resources/views/livewire/cluster-events.blade.php` | 80 |
| 2 | `src/Livewire/ClusterNodeManager.php` | 150 |
| 2 | `src/Livewire/ClusterAddNode.php` | 80 |
| 2 | `src/Livewire/SwarmConfigForm.php` | 200 |
| 2 | `resources/views/livewire/cluster-node-manager.blade.php` | 200 |
| 2 | `resources/views/livewire/cluster-add-node.blade.php` | 100 |
| 2 | `resources/views/livewire/swarm-config-form.blade.php` | 250 |
| 3 | `src/Models/SwarmSecret.php` | 30 |
| 3 | `src/Models/SwarmConfig.php` | 30 |
| 3 | `src/Livewire/ClusterSecrets.php` | 100 |
| 3 | `src/Livewire/ClusterConfigs.php` | 100 |
| 3 | `src/Jobs/ClusterEventCollectorJob.php` | 50 |
| 3 | `database/migrations/*_create_swarm_secrets_table.php` | 20 |
| 3 | `database/migrations/*_create_swarm_configs_table.php` | 20 |
| 3 | `resources/views/livewire/cluster-secrets.blade.php` | 120 |
| 3 | `resources/views/livewire/cluster-configs.blade.php` | 120 |
| 4 | `src/Livewire/SwarmTaskStatus.php` | 40 |
| 4 | `resources/views/livewire/swarm-task-status.blade.php` | 40 |
| 4 | `mcp-server/src/tools/clusters.ts` | 200 |

### Modified Files

| Phase | File | Change |
|-------|------|--------|
| 1 | `src/CoolifyEnhancedServiceProvider.php` | Register cluster components, routes, jobs |
| 1 | `config/coolify-enhanced.php` | Add cluster config options |
| 1 | `routes/web.php` | Add cluster routes |
| 1 | `routes/api.php` | Add cluster API routes |
| 1 | `src/Http/Middleware/InjectPermissionsUI.php` | Add clusters nav link |
| 2 | `src/Overrides/Views/livewire/project/application/swarm.blade.php` | Replace YAML with structured form |
| 4 | `mcp-server/src/lib/mcp-server.ts` | Register cluster tool modules |
| 4 | `mcp-server/src/lib/types.ts` | Add cluster type definitions |

### Overlay Files

| Phase | File | Why overlay? |
|-------|------|-------------|
| 2 | `swarm.blade.php` | Replace raw YAML textarea with structured form |

**Note:** Phase 1 requires ZERO overlay files. Phase 2 requires ONE overlay (the Swarm config view). This is consistent with the "zero overlay where possible" principle.

---

## Implementation Order (Within Each Phase)

### Phase 1 (Recommended order):

1. Database migrations (clusters, cluster_events, servers.cluster_id)
2. `ClusterDriverInterface` contract
3. `Cluster` model + `ClusterEvent` model
4. `SwarmClusterDriver` (read-only methods only)
5. `ClusterDetectionService`
6. `ClusterSyncJob`
7. `ClusterPolicy`
8. `ClusterController` (API)
9. Service provider registration
10. Config additions
11. `ClusterList` component + view
12. `ClusterDashboard` component + view (overview tab)
13. `ClusterServiceViewer` component + view (services tab)
14. `ClusterVisualizer` component + view (visualizer tab)
15. `ClusterEvents` component + view (events tab)
16. Route registration (web + API)
17. Navigation sidebar integration

### Phase 2 (Recommended order):

1. Write methods in `SwarmClusterDriver`
2. `ClusterNodeManager` component + view
3. `ClusterAddNode` component + view
4. `SwarmConfigForm` component + view
5. Service scaling/rollback in `ClusterServiceViewer`
6. Swarm.blade.php overlay

### Phase 3 (Recommended order):

1. Secrets/Configs migrations
2. `SwarmSecret` / `SwarmConfig` models
3. Driver methods for secrets/configs
4. `ClusterSecrets` / `ClusterConfigs` components + views
5. `ClusterEventCollectorJob`
6. Event retention cleanup

### Phase 4 (Recommended order):

1. `SwarmTaskStatus` inline component
2. Notification integration
3. MCP server tool modules
4. Documentation updates (CLAUDE.md, AGENTS.md, README.md)

---

## Caching Strategy

| Data | TTL | Invalidation |
|------|-----|-------------|
| Cluster info | 60s | On manual sync |
| Node list | 30s | On node action |
| Service list | 30s | On scale/rollback |
| Service tasks | 15s | On service expand |
| Node resources | 60s | On refresh |
| Join tokens | 300s | On manual sync |

Cache keys follow pattern: `cluster:{id}:{resource}` (e.g., `cluster:5:nodes`).

Explicit cache invalidation on write operations (drain, scale, etc.) via `Cache::forget("cluster:{id}:{resource}")`.

---

## Security Considerations

1. **Command injection** — ALL Docker CLI arguments pass through `escapeshellarg()`. Node IDs, service IDs, label keys/values are all escaped.
2. **Join tokens** — Stored encrypted in cluster settings (`'encrypted:array'` cast). Never exposed in API responses unless explicitly requested by admin.
3. **Policy enforcement** — All write operations check `$this->authorize('update', $cluster)` or `$this->authorize('delete', $cluster)`.
4. **Team scoping** — Clusters are team-scoped. Users can only see/manage clusters belonging to their team.
5. **Sensitive data** — Docker secret values are NEVER stored locally or logged. Only metadata (name, labels, creation time) is tracked.
6. **SSH reuse** — Uses Coolify's existing `instant_remote_process()` which manages SSH connections securely.

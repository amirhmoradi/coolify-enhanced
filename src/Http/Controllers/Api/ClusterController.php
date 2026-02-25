<?php

namespace AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Models\ClusterEvent;
use AmirhMoradi\CoolifyEnhanced\Services\ClusterDetectionService;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class ClusterController extends Controller
{
    public function __construct()
    {
        if (! config('coolify-enhanced.enabled', false) || ! config('coolify-enhanced.cluster_management', false)) {
            abort(404);
        }
    }

    /**
     * List clusters for the current team.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Cluster::class);

        $clusters = Cluster::ownedByTeam($this->teamId($request))
            ->with('managerServer')
            ->get();

        return response()->json($clusters);
    }

    /**
     * Create a new cluster.
     */
    public function create(Request $request): JsonResponse
    {
        Gate::authorize('create', Cluster::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|in:swarm,kubernetes',
            'manager_server_id' => 'required|integer|exists:servers,id',
            'description' => 'nullable|string|max:1000',
        ]);

        $teamId = $this->teamId($request);

        $server = Server::where('id', $validated['manager_server_id'])
            ->where('team_id', $teamId)
            ->first();

        if (! $server) {
            return response()->json(['message' => 'Server not found or does not belong to your team.'], 404);
        }

        $cluster = Cluster::create([
            'name' => $validated['name'],
            'type' => $validated['type'] ?? 'swarm',
            'description' => $validated['description'] ?? null,
            'status' => 'unknown',
            'manager_server_id' => $server->id,
            'team_id' => $teamId,
        ]);

        return response()->json($cluster->load('managerServer'), 201);
    }

    /**
     * Get cluster details with metadata.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        return response()->json($cluster->load('managerServer'));
    }

    /**
     * Update cluster name, description, or settings.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('update', $cluster);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'manager_server_id' => 'sometimes|integer|exists:servers,id',
        ]);

        if (isset($validated['manager_server_id'])) {
            $server = Server::where('id', $validated['manager_server_id'])
                ->where('team_id', $this->teamId($request))
                ->first();

            if (! $server) {
                return response()->json(['message' => 'Server not found or does not belong to your team.'], 404);
            }
        }

        $cluster->update($validated);

        return response()->json($cluster->fresh()->load('managerServer'));
    }

    /**
     * Delete a cluster record.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('delete', $cluster);

        Server::where('cluster_id', $cluster->id)->update(['cluster_id' => null]);
        $cluster->delete();

        return response()->json(['message' => 'Cluster deleted']);
    }

    /**
     * Force a metadata sync from the cluster's manager server.
     */
    public function sync(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('update', $cluster);

        try {
            app(ClusterDetectionService::class)->syncClusterMetadata($cluster);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sync failed: '.$e->getMessage(),
            ], 500);
        }

        return response()->json($cluster->fresh()->load('managerServer'));
    }

    /**
     * List nodes in the cluster via the driver.
     */
    public function nodes(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        try {
            $nodes = $cluster->driver()->getNodes();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch nodes: '.$e->getMessage()], 500);
        }

        return response()->json($nodes->values()->all());
    }

    /**
     * Perform an action on a cluster node.
     *
     * Supported actions: drain, activate, pause, promote, demote, add-label, remove-label.
     */
    public function nodeAction(Request $request, string $uuid, string $nodeId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageNodes', $cluster);

        $validated = $request->validate([
            'action' => 'required|string|in:drain,activate,pause,promote,demote,add-label,remove-label',
            'label_key' => 'required_if:action,add-label,remove-label|nullable|string|max:255',
            'label_value' => 'nullable|string|max:255',
        ]);

        $driver = $cluster->driver();
        $action = $validated['action'];

        try {
            $result = match ($action) {
                'drain' => $driver->updateNodeAvailability($nodeId, 'drain'),
                'activate' => $driver->updateNodeAvailability($nodeId, 'active'),
                'pause' => $driver->updateNodeAvailability($nodeId, 'pause'),
                'promote' => $driver->promoteNode($nodeId),
                'demote' => $driver->demoteNode($nodeId),
                'add-label' => $driver->updateNodeLabels(
                    $nodeId,
                    add: [$validated['label_key'] => $validated['label_value'] ?? '']
                ),
                'remove-label' => $driver->updateNodeLabels(
                    $nodeId,
                    remove: [$validated['label_key']]
                ),
            };

            if (! $result) {
                return response()->json(['message' => "Action '{$action}' failed on node."], 500);
            }

            Cache::forget("cluster:{$cluster->id}:nodes");

            return response()->json(['message' => "Node action '{$action}' completed successfully."]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Node action failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Remove a node from the cluster.
     */
    public function removeNode(Request $request, string $uuid, string $nodeId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageNodes', $cluster);

        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $cluster->driver()->removeNode($nodeId, $force);

            if (! $result) {
                return response()->json(['message' => 'Failed to remove node.'], 500);
            }

            Cache::forget("cluster:{$cluster->id}:nodes");

            return response()->json(['message' => 'Node removed from cluster.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to remove node: '.$e->getMessage()], 500);
        }
    }

    /**
     * List services in the cluster via the driver.
     */
    public function services(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        try {
            $services = $cluster->driver()->getServices();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch services: '.$e->getMessage()], 500);
        }

        return response()->json($services->values()->all());
    }

    /**
     * Get tasks for a specific service.
     */
    public function serviceTasks(Request $request, string $uuid, string $serviceId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        try {
            $tasks = $cluster->driver()->getServiceTasks($serviceId);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch service tasks: '.$e->getMessage()], 500);
        }

        return response()->json($tasks->values()->all());
    }

    /**
     * Scale a service to the desired number of replicas.
     */
    public function scaleService(Request $request, string $uuid, string $serviceId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageServices', $cluster);

        $validated = $request->validate([
            'replicas' => 'required|integer|min:0|max:100',
        ]);

        try {
            $result = $cluster->driver()->scaleService($serviceId, $validated['replicas']);

            if (! $result) {
                return response()->json(['message' => 'Failed to scale service.'], 500);
            }

            Cache::forget("cluster:{$cluster->id}:services");

            return response()->json([
                'message' => "Service scaled to {$validated['replicas']} replica(s).",
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to scale service: '.$e->getMessage()], 500);
        }
    }

    /**
     * Rollback a service to its previous version.
     */
    public function rollbackService(Request $request, string $uuid, string $serviceId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageServices', $cluster);

        try {
            $result = $cluster->driver()->rollbackService($serviceId);

            if (! $result) {
                return response()->json(['message' => 'Failed to rollback service.'], 500);
            }

            Cache::forget("cluster:{$cluster->id}:services");

            return response()->json(['message' => 'Service rollback initiated.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to rollback service: '.$e->getMessage()], 500);
        }
    }

    /**
     * Force update a service (redistribute tasks).
     */
    public function forceUpdateService(Request $request, string $uuid, string $serviceId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageServices', $cluster);

        try {
            $result = $cluster->driver()->forceUpdateService($serviceId);

            if (! $result) {
                return response()->json(['message' => 'Failed to force update service.'], 500);
            }

            Cache::forget("cluster:{$cluster->id}:services");

            return response()->json(['message' => 'Service force update initiated.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to force update service: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get cluster events from the database with optional filters.
     *
     * Query params: type, since (unix timestamp), until (unix timestamp), limit (default 100, max 1000).
     */
    public function events(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        $query = ClusterEvent::where('cluster_id', $cluster->id);

        if ($type = $request->query('type')) {
            $query->where('event_type', $type);
        }

        if ($since = $request->query('since')) {
            $query->where('event_time', '>=', \Carbon\Carbon::createFromTimestamp((int) $since));
        }

        if ($until = $request->query('until')) {
            $query->where('event_time', '<=', \Carbon\Carbon::createFromTimestamp((int) $until));
        }

        $limit = min((int) ($request->query('limit', 100)), 1000);

        $events = $query->orderByDesc('event_time')
            ->limit($limit)
            ->get();

        return response()->json($events);
    }

    /**
     * Get visualizer data: all nodes and all tasks for the cluster.
     */
    public function visualizer(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        try {
            $driver = $cluster->driver();
            $nodes = $driver->getNodes();
            $tasks = $driver->getAllTasks();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch visualizer data: '.$e->getMessage()], 500);
        }

        return response()->json([
            'nodes' => $nodes->values()->all(),
            'tasks' => $tasks->values()->all(),
        ]);
    }

    /**
     * List Swarm secrets in the cluster.
     */
    public function secrets(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        try {
            $secrets = $cluster->driver()->getSecrets();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch secrets: '.$e->getMessage()], 500);
        }

        return response()->json($secrets->values()->all());
    }

    /**
     * Create a Swarm secret.
     */
    public function createSecret(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageSecrets', $cluster);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'data' => 'required|string',
            'labels' => 'nullable|array',
            'labels.*' => 'string|max:255',
        ]);

        try {
            $secretId = $cluster->driver()->createSecret(
                $validated['name'],
                $validated['data'],
                $validated['labels'] ?? []
            );

            return response()->json([
                'message' => 'Secret created.',
                'id' => $secretId,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create secret: '.$e->getMessage()], 500);
        }
    }

    /**
     * Remove a Swarm secret.
     */
    public function removeSecret(Request $request, string $uuid, string $secretId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageSecrets', $cluster);

        try {
            $result = $cluster->driver()->removeSecret($secretId);

            if (! $result) {
                return response()->json(['message' => 'Failed to remove secret.'], 500);
            }

            return response()->json(['message' => 'Secret removed.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to remove secret: '.$e->getMessage()], 500);
        }
    }

    /**
     * List Swarm configs in the cluster.
     */
    public function configs(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('view', $cluster);

        try {
            $configs = $cluster->driver()->getConfigs();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch configs: '.$e->getMessage()], 500);
        }

        return response()->json($configs->values()->all());
    }

    /**
     * Create a Swarm config.
     */
    public function createConfig(Request $request, string $uuid): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageConfigs', $cluster);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'data' => 'required|string',
            'labels' => 'nullable|array',
            'labels.*' => 'string|max:255',
        ]);

        try {
            $configId = $cluster->driver()->createConfig(
                $validated['name'],
                $validated['data'],
                $validated['labels'] ?? []
            );

            return response()->json([
                'message' => 'Config created.',
                'id' => $configId,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create config: '.$e->getMessage()], 500);
        }
    }

    /**
     * Remove a Swarm config.
     */
    public function removeConfig(Request $request, string $uuid, string $configId): JsonResponse
    {
        $cluster = $this->findCluster($request, $uuid);
        Gate::authorize('manageConfigs', $cluster);

        try {
            $result = $cluster->driver()->removeConfig($configId);

            if (! $result) {
                return response()->json(['message' => 'Failed to remove config.'], 500);
            }

            return response()->json(['message' => 'Config removed.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to remove config: '.$e->getMessage()], 500);
        }
    }

    /**
     * Find a cluster by UUID, scoped to the current team.
     */
    protected function findCluster(Request $request, string $uuid): Cluster
    {
        return Cluster::ownedByTeam($this->teamId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * Get the current team ID from the authenticated user.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 when user has no current team
     */
    protected function teamId(Request $request): int
    {
        $team = $request->user()->currentTeam();
        if (! $team) {
            abort(403, 'No team selected. Please select a team to access clusters.');
        }

        return $team->id;
    }
}

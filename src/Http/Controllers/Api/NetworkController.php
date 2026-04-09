<?php

namespace CorelixIo\Platform\Http\Controllers\Api;

use CorelixIo\Platform\Jobs\ProxyMigrationJob;
use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Models\ResourceNetwork;
use CorelixIo\Platform\Services\NetworkService;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class NetworkController extends Controller
{
    public function __construct()
    {
        if (! config('corelix-platform.enabled', false) || ! config('corelix-platform.network_management.enabled', false)) {
            abort(404);
        }
    }

    /**
     * List managed networks for a server.
     */
    public function index(Request $request, string $serverUuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();
        $networks = ManagedNetwork::forServer($server)->with('resourceNetworks')->get();

        return response()->json($networks);
    }

    /**
     * Create a shared network on a server.
     */
    public function store(Request $request, string $serverUuid)
    {
        Gate::authorize('create', ManagedNetwork::class);

        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'is_internal' => 'nullable|boolean',
            'subnet' => ['nullable', 'string', 'max:50', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/'],
            'gateway' => ['nullable', 'string', 'max:50', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/'],
        ]);

        // Check network limit
        if (NetworkService::hasReachedNetworkLimit($server)) {
            return response()->json(['error' => 'Network limit reached for this server'], 422);
        }

        $team = $request->user()->currentTeam();
        // Create DB record only (defer Docker creation to apply options first)
        $network = NetworkService::ensureSharedNetwork($validated['name'], $server, $team, createDocker: false);

        // Apply optional settings before Docker network creation (single update)
        $updates = array_filter([
            'is_internal' => $validated['is_internal'] ?? null,
            'subnet' => $validated['subnet'] ?? null,
            'gateway' => $validated['gateway'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($updates)) {
            $network->update($updates);
        }

        // Create the Docker network with all options applied
        NetworkService::createDockerNetwork($server, $network->fresh());

        return response()->json($network->fresh(), 201);
    }

    /**
     * Show network details with connected resources.
     */
    public function show(string $serverUuid, string $networkUuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();
        $network = ManagedNetwork::where('uuid', $networkUuid)
            ->where('server_id', $server->id)
            ->with('resourceNetworks')
            ->firstOrFail();

        // Also get Docker inspection data
        $dockerInfo = NetworkService::inspectNetwork($server, $network->docker_network_name);

        return response()->json([
            'network' => $network,
            'docker_info' => $dockerInfo,
        ]);
    }

    /**
     * Delete a managed network.
     */
    public function destroy(string $serverUuid, string $networkUuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();
        $network = ManagedNetwork::where('uuid', $networkUuid)
            ->where('server_id', $server->id)
            ->firstOrFail();

        Gate::authorize('delete', $network);

        // Don't allow deleting system or auto-created environment networks
        if (in_array($network->scope, ['system', 'environment'])) {
            return response()->json(['error' => 'Cannot delete system or environment networks'], 422);
        }

        NetworkService::deleteDockerNetwork($server, $network);
        $network->resourceNetworks()->delete();
        $network->delete();

        return response()->json(['message' => 'Network deleted']);
    }

    /**
     * Sync networks from Docker.
     */
    public function sync(string $serverUuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();
        $networks = NetworkService::syncFromDocker($server);
        NetworkService::reconcileServer($server);

        return response()->json([
            'message' => 'Network sync complete',
            'discovered' => $networks->count(),
        ]);
    }

    /**
     * Reconcile all existing resources on a server.
     */
    public function reconcileResources(string $serverUuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();
        $results = NetworkService::reconcileExistingServerResources($server);

        return response()->json([
            'message' => 'Existing resource reconciliation complete',
            'results' => $results,
        ]);
    }

    /**
     * Run proxy isolation migration for a server.
     */
    public function migrateProxy(string $serverUuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();

        if (! config('corelix-platform.network_management.proxy_isolation', false)) {
            return response()->json(['error' => 'Proxy isolation is not enabled'], 422);
        }

        ProxyMigrationJob::dispatch($server);

        return response()->json(['message' => 'Proxy migration job dispatched']);
    }

    /**
     * Disconnect proxy from non-proxy networks.
     */
    public function cleanupProxy(string $serverUuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $serverUuid)->firstOrFail();

        if (! config('corelix-platform.network_management.proxy_isolation', false)) {
            return response()->json(['error' => 'Proxy isolation is not enabled'], 422);
        }

        $results = NetworkService::disconnectProxyFromNonProxyNetworks($server);
        $count = count(array_filter($results));

        return response()->json([
            'message' => "Disconnected proxy from {$count} non-proxy network(s)",
            'disconnected' => array_keys(array_filter($results)),
        ]);
    }

    /**
     * List networks for a specific resource.
     */
    public function resourceNetworks(string $type, string $uuid)
    {
        $resource = $this->resolveResource($type, $uuid);
        $networks = NetworkService::getResourceNetworks($resource);

        return response()->json($networks);
    }

    /**
     * Attach a resource to a network.
     */
    public function attachResource(Request $request, string $type, string $uuid)
    {
        $resource = $this->resolveResource($type, $uuid);
        $validated = $request->validate([
            'network_uuid' => 'required|string',
        ]);

        $server = NetworkService::getServerForResource($resource);

        // Scope network lookup to the same server (prevents cross-server attachment)
        $network = ManagedNetwork::where('uuid', $validated['network_uuid'])
            ->where('server_id', $server->id)
            ->firstOrFail();

        Gate::authorize('connect', $network);

        // Connect containers
        $containerNames = NetworkService::getContainerNames($resource);
        foreach ($containerNames as $containerName) {
            NetworkService::connectContainer($server, $network->docker_network_name, $containerName, [$containerName]);
        }

        // Create pivot record
        $pivot = ResourceNetwork::updateOrCreate(
            [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id,
                'managed_network_id' => $network->id,
            ],
            [
                'is_auto_attached' => false,
                'is_connected' => true,
                'connected_at' => now(),
                'aliases' => $containerNames,
            ]
        );

        return response()->json($pivot, 201);
    }

    /**
     * Detach a resource from a network.
     */
    public function detachResource(string $type, string $uuid, string $networkUuid)
    {
        $resource = $this->resolveResource($type, $uuid);
        $server = NetworkService::getServerForResource($resource);

        // Scope network lookup to the same server
        $network = ManagedNetwork::where('uuid', $networkUuid)
            ->where('server_id', $server->id)
            ->firstOrFail();

        Gate::authorize('disconnect', $network);

        // Don't allow detaching from auto-attached environment networks
        $pivot = ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->where('managed_network_id', $network->id)
            ->first();

        if ($pivot && $pivot->is_auto_attached && $network->scope === 'environment') {
            return response()->json(['error' => 'Cannot detach from auto-attached environment network'], 422);
        }

        // Disconnect containers
        $containerNames = NetworkService::getContainerNames($resource);
        foreach ($containerNames as $containerName) {
            NetworkService::disconnectContainer($server, $network->docker_network_name, $containerName);
        }

        // Delete pivot
        if ($pivot) {
            $pivot->delete();
        }

        return response()->json(['message' => 'Resource detached from network']);
    }

    /**
     * Resolve a resource from type and UUID.
     * Scoped to the current team to prevent cross-team access.
     */
    protected function resolveResource(string $type, string $uuid)
    {
        return match ($type) {
            'application' => \App\Models\Application::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'service' => \App\Models\Service::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'postgresql' => \App\Models\StandalonePostgresql::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'mysql' => \App\Models\StandaloneMysql::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'mariadb' => \App\Models\StandaloneMariadb::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'mongodb' => \App\Models\StandaloneMongodb::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'redis' => \App\Models\StandaloneRedis::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'keydb' => \App\Models\StandaloneKeydb::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'dragonfly' => \App\Models\StandaloneDragonfly::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            'clickhouse' => \App\Models\StandaloneClickhouse::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail(),
            default => abort(404, "Unknown resource type: {$type}"),
        };
    }
}

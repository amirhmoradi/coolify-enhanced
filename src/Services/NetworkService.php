<?php

namespace AmirhMoradi\CoolifyEnhanced\Services;

use AmirhMoradi\CoolifyEnhanced\Models\ManagedNetwork;
use AmirhMoradi\CoolifyEnhanced\Models\ResourceNetwork;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Visus\Cuid2\Cuid2;

class NetworkService
{
    /**
     * Network name prefix for all managed Docker networks.
     */
    const PREFIX = 'ce';

    // ============================================================
    // Docker Operations (via instant_remote_process)
    // ============================================================

    /**
     * Create a Docker network on the server.
     *
     * Idempotent: uses 2>/dev/null || true pattern so repeated calls
     * do not fail if the network already exists.
     *
     * Adds labels for reconciliation:
     *   coolify.managed=true, coolify.scope={scope}, coolify.environment={uuid}
     */
    public static function createDockerNetwork(Server $server, ManagedNetwork $network): bool
    {
        try {
            $parts = ['docker network create'];
            $parts[] = "--driver {$network->driver}";

            if ($network->is_attachable) {
                $parts[] = '--attachable';
            }

            if ($network->is_internal) {
                $parts[] = '--internal';
            }

            if ($network->subnet) {
                $parts[] = "--subnet {$network->subnet}";
            }

            if ($network->gateway) {
                $parts[] = "--gateway {$network->gateway}";
            }

            // Add labels for reconciliation
            $parts[] = '--label coolify.managed=true';
            $parts[] = "--label coolify.scope={$network->scope}";

            if ($network->environment_id) {
                $envUuid = $network->environment?->uuid;
                if ($envUuid) {
                    $parts[] = "--label coolify.environment={$envUuid}";
                }
            }

            if ($network->project_id) {
                $projectUuid = $network->project?->uuid;
                if ($projectUuid) {
                    $parts[] = "--label coolify.project={$projectUuid}";
                }
            }

            $parts[] = $network->docker_network_name;

            $createCommand = implode(' ', $parts).' 2>/dev/null || true';

            instant_remote_process([$createCommand], $server);

            Log::info("NetworkService: Created Docker network {$network->docker_network_name} on server {$server->name}");

            // Inspect to get the docker_id
            $inspection = static::inspectNetwork($server, $network->docker_network_name);

            $network->update([
                'docker_id' => $inspection['Id'] ?? null,
                'status' => ManagedNetwork::STATUS_ACTIVE,
                'last_synced_at' => now(),
                'error_message' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to create Docker network {$network->docker_network_name}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            $network->update([
                'status' => ManagedNetwork::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete a Docker network.
     *
     * Disconnects all containers first via force-remove, then removes the network.
     */
    public static function deleteDockerNetwork(Server $server, ManagedNetwork $network): bool
    {
        try {
            $command = "docker network rm {$network->docker_network_name} 2>/dev/null || true";

            instant_remote_process([$command], $server);

            Log::info("NetworkService: Deleted Docker network {$network->docker_network_name} on server {$server->name}");

            $network->update([
                'status' => ManagedNetwork::STATUS_PENDING,
                'docker_id' => null,
                'last_synced_at' => now(),
                'error_message' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to delete Docker network {$network->docker_network_name}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Connect a container to a network.
     *
     * Idempotent: ignores "already connected" errors via 2>/dev/null || true.
     */
    public static function connectContainer(Server $server, string $networkName, string $containerName, ?array $aliases = null): bool
    {
        try {
            $parts = ['docker network connect'];

            if ($aliases) {
                foreach ($aliases as $alias) {
                    $parts[] = "--alias {$alias}";
                }
            }

            $parts[] = $networkName;
            $parts[] = $containerName;

            $command = implode(' ', $parts).' 2>/dev/null || true';

            instant_remote_process([$command], $server);

            Log::info("NetworkService: Connected container {$containerName} to network {$networkName}");

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to connect container {$containerName} to network {$networkName}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Disconnect a container from a network.
     */
    public static function disconnectContainer(Server $server, string $networkName, string $containerName, bool $force = false): bool
    {
        try {
            $forceFlag = $force ? '--force ' : '';
            $command = "docker network disconnect {$forceFlag}{$networkName} {$containerName} 2>/dev/null || true";

            instant_remote_process([$command], $server);

            Log::info("NetworkService: Disconnected container {$containerName} from network {$networkName}");

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to disconnect container {$containerName} from network {$networkName}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Inspect a Docker network and return parsed JSON.
     *
     * Returns null if the network does not exist or inspection fails.
     */
    public static function inspectNetwork(Server $server, string $networkName): ?array
    {
        try {
            $command = "docker network inspect {$networkName} --format '{{json .}}' 2>/dev/null";

            $output = instant_remote_process([$command], $server);

            if (empty($output)) {
                return null;
            }

            $parsed = json_decode($output, true);

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to inspect network {$networkName}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * List all Docker networks on a server.
     *
     * Returns a collection of arrays with: Name, ID, Driver, Scope, Labels.
     */
    public static function listDockerNetworks(Server $server): Collection
    {
        try {
            $command = "docker network ls --format '{{json .}}'";

            $output = instant_remote_process([$command], $server);

            if (empty($output)) {
                return collect();
            }

            return collect(explode("\n", trim($output)))
                ->filter(fn ($line) => ! empty(trim($line)))
                ->map(function ($line) {
                    $parsed = json_decode($line, true);

                    return is_array($parsed) ? $parsed : null;
                })
                ->filter()
                ->values();
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to list Docker networks', [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    // ============================================================
    // High-Level Operations
    // ============================================================

    /**
     * Ensure the environment network exists on the server.
     *
     * Creates both the DB record and the Docker network if needed.
     * Uses firstOrCreate with exception handling for race conditions.
     */
    public static function ensureEnvironmentNetwork(Environment $environment, Server $server): ManagedNetwork
    {
        $dockerName = static::generateNetworkName('env', $environment->uuid);
        $humanName = "{$environment->name} ({$environment->project->name})";

        try {
            $network = ManagedNetwork::firstOrCreate(
                [
                    'docker_network_name' => $dockerName,
                    'server_id' => $server->id,
                ],
                [
                    'uuid' => (string) new Cuid2,
                    'name' => $humanName,
                    'driver' => 'bridge',
                    'scope' => ManagedNetwork::SCOPE_ENVIRONMENT,
                    'team_id' => $environment->project->team_id,
                    'project_id' => $environment->project_id,
                    'environment_id' => $environment->id,
                    'is_attachable' => true,
                    'is_internal' => false,
                    'is_proxy_network' => false,
                    'status' => ManagedNetwork::STATUS_PENDING,
                ]
            );
        } catch (\Throwable $e) {
            // Race condition: another process created the record — fetch it
            $network = ManagedNetwork::where('docker_network_name', $dockerName)
                ->where('server_id', $server->id)
                ->firstOrFail();
        }

        // Create the Docker network if the record is still pending
        if ($network->status === ManagedNetwork::STATUS_PENDING) {
            static::createDockerNetwork($server, $network);
        }

        return $network;
    }

    /**
     * Ensure the proxy network exists on a server.
     *
     * The proxy network connects resources that have an FQDN to the reverse proxy,
     * enabling HTTP routing without being on the default 'coolify' network.
     */
    public static function ensureProxyNetwork(Server $server): ManagedNetwork
    {
        $dockerName = static::generateNetworkName('proxy', $server->uuid);
        $humanName = "Proxy ({$server->name})";

        try {
            $network = ManagedNetwork::firstOrCreate(
                [
                    'docker_network_name' => $dockerName,
                    'server_id' => $server->id,
                ],
                [
                    'uuid' => (string) new Cuid2,
                    'name' => $humanName,
                    'driver' => 'bridge',
                    'scope' => ManagedNetwork::SCOPE_PROXY,
                    'team_id' => $server->team_id,
                    'is_attachable' => true,
                    'is_internal' => false,
                    'is_proxy_network' => true,
                    'status' => ManagedNetwork::STATUS_PENDING,
                ]
            );
        } catch (\Throwable $e) {
            $network = ManagedNetwork::where('docker_network_name', $dockerName)
                ->where('server_id', $server->id)
                ->firstOrFail();
        }

        if ($network->status === ManagedNetwork::STATUS_PENDING) {
            static::createDockerNetwork($server, $network);
        }

        return $network;
    }

    /**
     * Create/ensure a shared network on a server.
     *
     * Shared networks are manually created and can be joined by any resource
     * on the same server, enabling cross-environment communication.
     */
    public static function ensureSharedNetwork(string $name, Server $server, Team $team): ManagedNetwork
    {
        $identifier = (string) new Cuid2;
        $dockerName = static::generateNetworkName('shared', $identifier);

        try {
            $network = ManagedNetwork::firstOrCreate(
                [
                    'docker_network_name' => $dockerName,
                    'server_id' => $server->id,
                ],
                [
                    'uuid' => $identifier,
                    'name' => $name,
                    'driver' => 'bridge',
                    'scope' => ManagedNetwork::SCOPE_SHARED,
                    'team_id' => $team->id,
                    'is_attachable' => true,
                    'is_internal' => false,
                    'is_proxy_network' => false,
                    'status' => ManagedNetwork::STATUS_PENDING,
                ]
            );
        } catch (\Throwable $e) {
            $network = ManagedNetwork::where('docker_network_name', $dockerName)
                ->where('server_id', $server->id)
                ->firstOrFail();
        }

        if ($network->status === ManagedNetwork::STATUS_PENDING) {
            static::createDockerNetwork($server, $network);
        }

        return $network;
    }

    /**
     * Get all managed networks a resource is connected to.
     */
    public static function getResourceNetworks($resource): Collection
    {
        return ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->with('managedNetwork')
            ->get();
    }

    /**
     * Get networks available for a resource to join.
     *
     * Returns shared networks on the same server that the resource is not already on.
     */
    public static function getAvailableNetworks($resource, Server $server): Collection
    {
        $existingIds = ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->pluck('managed_network_id');

        return ManagedNetwork::forServer($server)
            ->shared()
            ->active()
            ->whereNotIn('id', $existingIds)
            ->get();
    }

    // ============================================================
    // Reconciliation
    // ============================================================

    /**
     * Reconcile a single resource: ensure Docker state matches DB.
     *
     * 1. Get the resource's server
     * 2. Ensure environment network exists
     * 3. Get resource containers
     * 4. For each container: connect to environment network
     * 5. If resource has FQDN and proxy isolation enabled: connect to proxy network
     * 6. If strict isolation: disconnect from 'coolify' network
     * 7. Update resource_networks.is_connected status
     */
    public static function reconcileResource($resource): void
    {
        $server = static::getServerForResource($resource);
        if (! $server) {
            Log::warning('NetworkService: Could not resolve server for resource reconciliation', [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
            ]);

            return;
        }

        $environment = static::getEnvironmentForResource($resource);
        if (! $environment) {
            Log::warning('NetworkService: Could not resolve environment for resource reconciliation', [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
            ]);

            return;
        }

        $containerNames = static::getContainerNames($resource);
        if (empty($containerNames)) {
            return;
        }

        // Ensure the environment network exists
        $envNetwork = static::ensureEnvironmentNetwork($environment, $server);

        // Connect all containers to the environment network
        foreach ($containerNames as $containerName) {
            $connected = static::connectContainer($server, $envNetwork->docker_network_name, $containerName);

            // Update or create the resource-network pivot record
            ResourceNetwork::updateOrCreate(
                [
                    'resource_type' => get_class($resource),
                    'resource_id' => $resource->id,
                    'managed_network_id' => $envNetwork->id,
                ],
                [
                    'is_connected' => $connected,
                    'is_auto_attached' => true,
                    'connected_at' => $connected ? now() : null,
                ]
            );
        }

        // Handle proxy network if the resource has an FQDN
        $proxyIsolation = config('coolify-enhanced.network_management.proxy_isolation', false);
        if ($proxyIsolation && static::resourceHasFqdn($resource)) {
            $proxyNetwork = static::ensureProxyNetwork($server);

            foreach ($containerNames as $containerName) {
                $connected = static::connectContainer($server, $proxyNetwork->docker_network_name, $containerName);

                ResourceNetwork::updateOrCreate(
                    [
                        'resource_type' => get_class($resource),
                        'resource_id' => $resource->id,
                        'managed_network_id' => $proxyNetwork->id,
                    ],
                    [
                        'is_connected' => $connected,
                        'is_auto_attached' => true,
                        'connected_at' => $connected ? now() : null,
                    ]
                );
            }
        }

        // If strict isolation mode: disconnect from the default 'coolify' network
        $isolationMode = config('coolify-enhanced.network_management.isolation_mode', 'environment');
        if ($isolationMode === 'strict') {
            foreach ($containerNames as $containerName) {
                static::disconnectContainer($server, 'coolify', $containerName, true);
            }
        }
    }

    /**
     * Reconcile all managed networks on a server.
     *
     * Verifies Docker state matches DB state for all networks.
     * Recreates missing networks and updates status for existing ones.
     */
    public static function reconcileServer(Server $server): void
    {
        $networks = ManagedNetwork::forServer($server)->get();

        foreach ($networks as $network) {
            try {
                $inspection = static::inspectNetwork($server, $network->docker_network_name);

                if ($inspection === null) {
                    // Network doesn't exist in Docker
                    if ($network->status === ManagedNetwork::STATUS_ACTIVE) {
                        // Was active, now missing — recreate
                        Log::info("NetworkService: Recreating missing network {$network->docker_network_name} on server {$server->name}");
                        static::createDockerNetwork($server, $network);
                    }
                } else {
                    // Network exists — update docker_id and status
                    $network->update([
                        'docker_id' => $inspection['Id'] ?? null,
                        'status' => ManagedNetwork::STATUS_ACTIVE,
                        'last_synced_at' => now(),
                        'error_message' => null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("NetworkService: Failed to reconcile network {$network->docker_network_name}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sync Docker networks into the managed_networks table.
     *
     * Discovers existing Docker networks with coolify.managed labels
     * and creates/updates DB records accordingly.
     *
     * @return Collection Collection of all discovered managed networks
     */
    public static function syncFromDocker(Server $server): Collection
    {
        $dockerNetworks = static::listDockerNetworks($server);
        $discovered = collect();

        foreach ($dockerNetworks as $dockerNetwork) {
            $name = $dockerNetwork['Name'] ?? null;
            if (! $name) {
                continue;
            }

            // Inspect the network to get labels and full details
            $inspection = static::inspectNetwork($server, $name);
            if (! $inspection) {
                continue;
            }

            $labels = $inspection['Labels'] ?? [];

            // Only process networks with the coolify.managed label
            if (! isset($labels['coolify.managed']) || $labels['coolify.managed'] !== 'true') {
                continue;
            }

            $scope = $labels['coolify.scope'] ?? ManagedNetwork::SCOPE_SYSTEM;

            // Find or create the DB record
            $network = ManagedNetwork::where('docker_network_name', $name)
                ->where('server_id', $server->id)
                ->first();

            if ($network) {
                $network->update([
                    'docker_id' => $inspection['Id'] ?? null,
                    'status' => ManagedNetwork::STATUS_ACTIVE,
                    'last_synced_at' => now(),
                    'error_message' => null,
                ]);
            } else {
                Log::info("NetworkService: Discovered untracked managed network {$name} on server {$server->name}");

                $network = ManagedNetwork::create([
                    'uuid' => (string) new Cuid2,
                    'name' => $name,
                    'docker_network_name' => $name,
                    'server_id' => $server->id,
                    'team_id' => $server->team_id,
                    'driver' => $inspection['Driver'] ?? 'bridge',
                    'scope' => $scope,
                    'docker_id' => $inspection['Id'] ?? null,
                    'status' => ManagedNetwork::STATUS_ACTIVE,
                    'last_synced_at' => now(),
                    'is_attachable' => (bool) ($inspection['Attachable'] ?? false),
                    'is_internal' => (bool) ($inspection['Internal'] ?? false),
                ]);
            }

            $discovered->push($network);
        }

        return $discovered;
    }

    // ============================================================
    // Auto-Provisioning
    // ============================================================

    /**
     * Auto-attach a resource to its environment network (and proxy if applicable).
     *
     * Called after deployment completes. Checks feature flags before proceeding.
     */
    public static function autoAttachResource($resource): void
    {
        if (! config('coolify-enhanced.network_management.enabled', false)) {
            return;
        }

        $isolationMode = config('coolify-enhanced.network_management.isolation_mode', 'environment');
        if ($isolationMode === 'none') {
            return;
        }

        $server = static::getServerForResource($resource);
        if (! $server) {
            Log::warning('NetworkService: Could not resolve server for auto-attach', [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
            ]);

            return;
        }

        $environment = static::getEnvironmentForResource($resource);
        if (! $environment) {
            Log::warning('NetworkService: Could not resolve environment for auto-attach', [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
            ]);

            return;
        }

        $containerNames = static::getContainerNames($resource);
        if (empty($containerNames)) {
            return;
        }

        // Ensure the environment network and connect containers
        $envNetwork = static::ensureEnvironmentNetwork($environment, $server);

        foreach ($containerNames as $containerName) {
            $connected = static::connectContainer($server, $envNetwork->docker_network_name, $containerName);

            ResourceNetwork::updateOrCreate(
                [
                    'resource_type' => get_class($resource),
                    'resource_id' => $resource->id,
                    'managed_network_id' => $envNetwork->id,
                ],
                [
                    'is_connected' => $connected,
                    'is_auto_attached' => true,
                    'connected_at' => $connected ? now() : null,
                ]
            );
        }

        // Handle proxy network if resource has FQDN and proxy isolation is enabled
        $proxyIsolation = config('coolify-enhanced.network_management.proxy_isolation', false);
        if ($proxyIsolation && static::resourceHasFqdn($resource)) {
            $proxyNetwork = static::ensureProxyNetwork($server);

            foreach ($containerNames as $containerName) {
                $connected = static::connectContainer($server, $proxyNetwork->docker_network_name, $containerName);

                ResourceNetwork::updateOrCreate(
                    [
                        'resource_type' => get_class($resource),
                        'resource_id' => $resource->id,
                        'managed_network_id' => $proxyNetwork->id,
                    ],
                    [
                        'is_connected' => $connected,
                        'is_auto_attached' => true,
                        'connected_at' => $connected ? now() : null,
                    ]
                );
            }
        }

        // If strict isolation mode: disconnect from the default 'coolify' network
        if ($isolationMode === 'strict') {
            foreach ($containerNames as $containerName) {
                static::disconnectContainer($server, 'coolify', $containerName, true);
            }
        }

        Log::info('NetworkService: Auto-attached resource to managed networks', [
            'resource_type' => get_class($resource),
            'resource_id' => $resource->id ?? null,
            'environment' => $environment->name,
        ]);
    }

    /**
     * Auto-detach a resource from all managed networks.
     *
     * Called when a resource is deleted. Disconnects containers from all
     * managed networks and removes the pivot records.
     */
    public static function autoDetachResource($resource): void
    {
        $server = static::getServerForResource($resource);
        $resourceNetworks = ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->with('managedNetwork')
            ->get();

        if ($resourceNetworks->isEmpty()) {
            return;
        }

        $containerNames = static::getContainerNames($resource);

        foreach ($resourceNetworks as $resourceNetwork) {
            $managedNetwork = $resourceNetwork->managedNetwork;
            if (! $managedNetwork) {
                $resourceNetwork->delete();

                continue;
            }

            // Disconnect containers from Docker network
            if ($server) {
                foreach ($containerNames as $containerName) {
                    static::disconnectContainer($server, $managedNetwork->docker_network_name, $containerName, true);
                }
            }

            $resourceNetwork->delete();
        }

        Log::info('NetworkService: Auto-detached resource from all managed networks', [
            'resource_type' => get_class($resource),
            'resource_id' => $resource->id ?? null,
            'networks_removed' => $resourceNetworks->count(),
        ]);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Get the server for a resource.
     *
     * Supports Application, Service, and standalone database types.
     * Uses the same resolution pattern as ScheduledResourceBackup::server().
     */
    public static function getServerForResource($resource): ?Server
    {
        if (! $resource) {
            return null;
        }

        // Application — uses destination->server
        if ($resource instanceof Application) {
            return $resource->destination->server ?? null;
        }

        // Service — has a direct server relationship
        if ($resource instanceof Service) {
            return $resource->server ?? null;
        }

        // Standalone databases — use destination->server
        if (method_exists($resource, 'destination') && $resource->destination) {
            return $resource->destination->server ?? null;
        }

        // Direct server relationship fallback
        if (method_exists($resource, 'server') && $resource->server) {
            return $resource->server;
        }

        return null;
    }

    /**
     * Get the environment for a resource.
     */
    public static function getEnvironmentForResource($resource): ?Environment
    {
        if (! $resource) {
            return null;
        }

        // Application, Service, and standalone databases all have an environment relationship
        if (method_exists($resource, 'environment') || property_exists($resource, 'environment')) {
            return $resource->environment ?? null;
        }

        return null;
    }

    /**
     * Get container name(s) for a resource.
     *
     * Applications and standalone databases use their UUID.
     * Services list all sub-containers (applications + databases) using
     * the {name}-{service_uuid} convention.
     */
    public static function getContainerNames($resource): array
    {
        if ($resource instanceof Application) {
            return [$resource->uuid];
        }

        if ($resource instanceof Service) {
            $containers = [];
            foreach ($resource->applications as $app) {
                $containers[] = "{$app->name}-{$resource->uuid}";
            }
            foreach ($resource->databases as $db) {
                $containers[] = "{$db->name}-{$resource->uuid}";
            }

            return $containers;
        }

        // Standalone databases use uuid
        if (property_exists($resource, 'uuid')) {
            return [$resource->uuid];
        }

        return [];
    }

    /**
     * Check if a resource has an FQDN (needs proxy network).
     */
    public static function resourceHasFqdn($resource): bool
    {
        return ! empty($resource->fqdn ?? null);
    }

    /**
     * Get the proxy network name for a server.
     *
     * Returns the Docker network name of the active proxy network,
     * or null if proxy isolation is disabled or no proxy network exists.
     */
    public static function getProxyNetworkName(Server $server): ?string
    {
        if (! config('coolify-enhanced.network_management.proxy_isolation', false)
            || ! config('coolify-enhanced.network_management.enabled', false)) {
            return null;
        }

        $proxyNetwork = ManagedNetwork::where('server_id', $server->id)
            ->where('is_proxy_network', true)
            ->where('status', ManagedNetwork::STATUS_ACTIVE)
            ->first();

        return $proxyNetwork?->docker_network_name;
    }

    /**
     * Connect the proxy container (coolify-proxy) to the proxy network.
     *
     * Called during proxy isolation migration and reconciliation to ensure
     * the reverse proxy can reach resources on the proxy network.
     */
    public static function connectProxyContainer(Server $server): bool
    {
        $proxyNetwork = static::ensureProxyNetwork($server);

        return static::connectContainer($server, $proxyNetwork->docker_network_name, 'coolify-proxy');
    }

    /**
     * Disconnect proxy from networks that are NOT proxy networks.
     *
     * Used after proxy isolation migration is complete and all resources
     * have been redeployed with traefik.docker.network labels.
     * Keeps the default coolify/coolify-overlay network for safety.
     */
    public static function disconnectProxyFromNonProxyNetworks(Server $server): array
    {
        $results = [];
        $defaultNetwork = $server->isSwarm() ? 'coolify-overlay' : 'coolify';

        // Get networks the proxy is currently connected to
        $inspection = static::inspectNetwork($server, 'coolify-proxy');
        // Can't inspect a container as a network — use docker inspect instead
        try {
            $output = instant_remote_process(
                ['docker inspect --format=\'{{json .NetworkSettings.Networks}}\' coolify-proxy 2>/dev/null'],
                $server
            );

            $connectedNetworks = array_keys(json_decode($output, true) ?? []);
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to inspect proxy container networks', [
                'error' => $e->getMessage(),
            ]);

            return $results;
        }

        // Get proxy network names
        $proxyNetworkNames = ManagedNetwork::where('server_id', $server->id)
            ->where('is_proxy_network', true)
            ->pluck('docker_network_name')
            ->toArray();

        foreach ($connectedNetworks as $networkName) {
            // Keep default network and proxy networks
            if ($networkName === $defaultNetwork || in_array($networkName, $proxyNetworkNames)) {
                continue;
            }

            // Skip Docker predefined networks
            if (in_array($networkName, ['bridge', 'host', 'none'])) {
                continue;
            }

            $disconnected = static::disconnectContainer($server, $networkName, 'coolify-proxy');
            $results[$networkName] = $disconnected;
        }

        Log::info('NetworkService: Disconnected proxy from non-proxy networks', [
            'server' => $server->name,
            'disconnected' => array_keys(array_filter($results)),
        ]);

        return $results;
    }

    /**
     * Migrate a server to proxy isolation.
     *
     * Steps:
     * 1. Create/ensure the proxy network exists
     * 2. Connect the proxy container to it
     * 3. Connect all FQDN-bearing resource containers to the proxy network
     * 4. Return migration status
     *
     * Does NOT disconnect from old networks — that's a separate step
     * after all resources have been redeployed with traefik.docker.network labels.
     */
    public static function migrateToProxyIsolation(Server $server): array
    {
        $results = [
            'proxy_network' => null,
            'proxy_connected' => false,
            'resources_migrated' => 0,
            'resources_failed' => 0,
            'errors' => [],
        ];

        try {
            // 1. Ensure proxy network exists
            $proxyNetwork = static::ensureProxyNetwork($server);
            $results['proxy_network'] = $proxyNetwork->docker_network_name;

            // 2. Connect proxy container
            $results['proxy_connected'] = static::connectProxyContainer($server);

            // 3. Find all FQDN-bearing resources on this server and connect them
            $applications = Application::whereHas('destination', function ($q) use ($server) {
                $q->where('server_id', $server->id);
            })->whereNotNull('fqdn')->where('fqdn', '!=', '')->get();

            foreach ($applications as $app) {
                try {
                    $containerNames = static::getContainerNames($app);
                    foreach ($containerNames as $containerName) {
                        static::connectContainer($server, $proxyNetwork->docker_network_name, $containerName);
                    }

                    ResourceNetwork::updateOrCreate(
                        [
                            'resource_type' => get_class($app),
                            'resource_id' => $app->id,
                            'managed_network_id' => $proxyNetwork->id,
                        ],
                        [
                            'is_connected' => true,
                            'is_auto_attached' => true,
                            'connected_at' => now(),
                        ]
                    );

                    $results['resources_migrated']++;
                } catch (\Throwable $e) {
                    $results['resources_failed']++;
                    $results['errors'][] = "Application {$app->uuid}: {$e->getMessage()}";
                }
            }

            // Also handle Services with FQDNs
            $services = Service::where('server_id', $server->id)->get();
            foreach ($services as $service) {
                if (! static::resourceHasFqdn($service)) {
                    continue;
                }

                try {
                    $containerNames = static::getContainerNames($service);
                    foreach ($containerNames as $containerName) {
                        static::connectContainer($server, $proxyNetwork->docker_network_name, $containerName);
                    }

                    ResourceNetwork::updateOrCreate(
                        [
                            'resource_type' => get_class($service),
                            'resource_id' => $service->id,
                            'managed_network_id' => $proxyNetwork->id,
                        ],
                        [
                            'is_connected' => true,
                            'is_auto_attached' => true,
                            'connected_at' => now(),
                        ]
                    );

                    $results['resources_migrated']++;
                } catch (\Throwable $e) {
                    $results['resources_failed']++;
                    $results['errors'][] = "Service {$service->uuid}: {$e->getMessage()}";
                }
            }

            Log::info('NetworkService: Proxy isolation migration complete', $results);
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            Log::error('NetworkService: Proxy isolation migration failed', [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Generate the Docker network name for a given scope and identifier.
     *
     * Format: {prefix}-{scope}-{identifier}
     * Example: ce-env-clxy1234abcd
     */
    public static function generateNetworkName(string $scope, string $identifier): string
    {
        $prefix = config('coolify-enhanced.network_management.prefix', self::PREFIX);

        return "{$prefix}-{$scope}-{$identifier}";
    }

    /**
     * Check if the maximum network limit has been reached for a server.
     *
     * Prevents unbounded network creation which could exhaust Docker resources.
     */
    public static function hasReachedNetworkLimit(Server $server): bool
    {
        $limit = config('coolify-enhanced.network_management.max_networks_per_server', 200);

        return ManagedNetwork::forServer($server)->count() >= $limit;
    }
}

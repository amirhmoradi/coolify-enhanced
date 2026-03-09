<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Models\ResourceNetwork;
use CorelixIo\Platform\Services\NetworkService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Per-resource network assignment component.
 *
 * Rendered on resource configuration pages (Application, Service, Database)
 * to manage which Docker networks a resource is connected to. Allows adding
 * shared networks, removing non-auto-attached networks, and reconnecting
 * disconnected containers.
 *
 * Similar to ResourceBackupManager but for network management instead of backups.
 * Uses the same resource identification pattern (resourceId + resourceType).
 */
class ResourceNetworks extends Component
{
    use AuthorizesRequests;
    /**
     * Allowlist of valid resource model classes.
     * Prevents arbitrary class instantiation via Livewire public property tampering.
     */
    private const ALLOWED_RESOURCE_TYPES = [
        \App\Models\Application::class,
        \App\Models\Service::class,
        \App\Models\StandalonePostgresql::class,
        \App\Models\StandaloneMysql::class,
        \App\Models\StandaloneMariadb::class,
        \App\Models\StandaloneMongodb::class,
        \App\Models\StandaloneRedis::class,
        \App\Models\StandaloneKeydb::class,
        \App\Models\StandaloneDragonfly::class,
        \App\Models\StandaloneClickhouse::class,
    ];

    public $resourceId;

    public $resourceType;

    public string $resourceName = '';

    // Selected network to add
    public string $selectedNetworkId = '';

    public function mount($resourceId, $resourceType, $resourceName = ''): void
    {
        if (! config('corelix-platform.enabled', false) || ! config('corelix-platform.network_management.enabled', false)) {
            abort(404);
        }

        if (! in_array($resourceType, self::ALLOWED_RESOURCE_TYPES, true)) {
            abort(403, 'Invalid resource type.');
        }

        $this->resourceId = $resourceId;
        $this->resourceType = $resourceType;
        $this->resourceName = $resourceName;
    }

    /**
     * Resolve the resource model safely using the allowlisted type.
     */
    private function resolveResource()
    {
        if (! in_array($this->resourceType, self::ALLOWED_RESOURCE_TYPES, true)) {
            throw new \RuntimeException('Invalid resource type.');
        }

        return $this->resourceType::findOrFail($this->resourceId);
    }

    public function addToNetwork(): void
    {
        $resource = $this->resolveResource();
        $this->authorize('update', $resource);

        if (empty($this->selectedNetworkId)) {
            return;
        }

        try {
            $resource = $this->resolveResource();
            $network = ManagedNetwork::findOrFail($this->selectedNetworkId);
            $server = NetworkService::getServerForResource($resource);

            if (! $server || $network->server_id !== $server->id) {
                $this->dispatch('error', 'Network is not on the same server.');

                return;
            }

            // Connect containers
            $containerNames = NetworkService::getContainerNames($resource);
            foreach ($containerNames as $containerName) {
                NetworkService::connectContainer($server, $network->docker_network_name, $containerName, [$containerName]);
            }

            ResourceNetwork::updateOrCreate(
                [
                    'resource_type' => $this->resourceType,
                    'resource_id' => $this->resourceId,
                    'managed_network_id' => $network->id,
                ],
                [
                    'is_auto_attached' => false,
                    'is_connected' => true,
                    'connected_at' => now(),
                    'aliases' => $containerNames,
                ]
            );

            $this->selectedNetworkId = '';
            $this->dispatch('success', 'Connected to network.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to connect to network: '.$e->getMessage());
        }
    }

    public function removeFromNetwork(int $pivotId): void
    {
        try {
            $resource = $this->resolveResource();
            $this->authorize('update', $resource);

            // Verify pivot belongs to this resource (prevents cross-resource manipulation)
            $pivot = ResourceNetwork::where('id', $pivotId)
                ->where('resource_type', $this->resourceType)
                ->where('resource_id', $this->resourceId)
                ->firstOrFail();

            // Don't allow removing auto-attached environment networks
            if ($pivot->is_auto_attached && $pivot->managedNetwork->scope === 'environment') {
                $this->dispatch('error', 'Cannot remove auto-attached environment network.');

                return;
            }

            $resource = $this->resolveResource();
            $server = NetworkService::getServerForResource($resource);
            $network = $pivot->managedNetwork;

            // Disconnect containers
            $containerNames = NetworkService::getContainerNames($resource);
            foreach ($containerNames as $containerName) {
                NetworkService::disconnectContainer($server, $network->docker_network_name, $containerName);
            }

            $pivot->delete();
            $this->dispatch('success', 'Disconnected from network.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to disconnect from network: '.$e->getMessage());
        }
    }

    public function reconnect(int $pivotId): void
    {
        try {
            $resource = $this->resolveResource();
            $this->authorize('update', $resource);

            // Verify pivot belongs to this resource (prevents cross-resource manipulation)
            $pivot = ResourceNetwork::where('id', $pivotId)
                ->where('resource_type', $this->resourceType)
                ->where('resource_id', $this->resourceId)
                ->firstOrFail();
            $resource = $this->resolveResource();
            $server = NetworkService::getServerForResource($resource);
            $network = $pivot->managedNetwork;

            $containerNames = NetworkService::getContainerNames($resource);
            foreach ($containerNames as $containerName) {
                NetworkService::connectContainer($server, $network->docker_network_name, $containerName, [$containerName]);
            }

            $pivot->update(['is_connected' => true, 'connected_at' => now()]);
            $this->dispatch('success', 'Reconnected to network.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to reconnect: '.$e->getMessage());
        }
    }

    public function render()
    {
        $currentNetworks = ResourceNetwork::where('resource_type', $this->resourceType)
            ->where('resource_id', $this->resourceId)
            ->with('managedNetwork')
            ->get();

        $resource = in_array($this->resourceType, self::ALLOWED_RESOURCE_TYPES, true)
            ? $this->resourceType::find($this->resourceId)
            : null;
        $server = $resource ? NetworkService::getServerForResource($resource) : null;

        $availableNetworks = $server
            ? NetworkService::getAvailableNetworks($resource, $server)
            : collect();

        return view('corelix-platform::livewire.resource-networks', [
            'currentNetworks' => $currentNetworks,
            'availableNetworks' => $availableNetworks,
        ]);
    }
}

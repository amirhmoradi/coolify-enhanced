<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ClusterServiceViewer extends Component
{
    use AuthorizesRequests;

    public int $clusterId;

    public array $services = [];

    public ?string $expandedServiceId = null;

    public array $expandedTasks = [];

    public ?int $scaleReplicas = null;

    private ?Cluster $clusterModel = null;

    public function mount(int $clusterId): void
    {
        $this->clusterId = $clusterId;
        $this->refreshServices();
    }

    public function refreshServices(): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->services = Cache::remember(
                "cluster:{$this->clusterId}:services",
                30,
                fn () => $cluster->driver()->getServices()->toArray()
            );
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load services: '.$e->getMessage());
            $this->services = [];
        }
    }

    public function toggleServiceExpand(string $serviceId): void
    {
        if ($this->expandedServiceId === $serviceId) {
            $this->expandedServiceId = null;
            $this->expandedTasks = [];
            $this->scaleReplicas = null;

            return;
        }

        $this->expandedServiceId = $serviceId;
        $this->loadServiceTasks($serviceId);
    }

    public function scaleService(string $serviceId, int $replicas): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->authorize('manageServices', $cluster);

            if ($replicas < 0) {
                $this->dispatch('error', 'Replica count must be non-negative.');

                return;
            }

            $cluster->driver()->scaleService($serviceId, $replicas);
            Cache::forget("cluster:{$this->clusterId}:services");
            $this->scaleReplicas = null;
            $this->refreshServices();

            if ($this->expandedServiceId === $serviceId) {
                $this->loadServiceTasks($serviceId);
            }

            $this->dispatch('success', "Service scaled to {$replicas} replica(s).");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to scale service: '.$e->getMessage());
        }
    }

    public function rollbackService(string $serviceId): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->authorize('manageServices', $cluster);

            $cluster->driver()->rollbackService($serviceId);
            Cache::forget("cluster:{$this->clusterId}:services");
            $this->refreshServices();

            if ($this->expandedServiceId === $serviceId) {
                $this->loadServiceTasks($serviceId);
            }

            $this->dispatch('success', 'Service rollback initiated.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to rollback service: '.$e->getMessage());
        }
    }

    public function forceUpdate(string $serviceId): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->authorize('manageServices', $cluster);

            $cluster->driver()->forceUpdateService($serviceId);
            Cache::forget("cluster:{$this->clusterId}:services");
            $this->refreshServices();

            if ($this->expandedServiceId === $serviceId) {
                $this->loadServiceTasks($serviceId);
            }

            $this->dispatch('success', 'Service force update initiated.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to force update service: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.cluster-service-viewer');
    }

    private function loadServiceTasks(string $serviceId): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->expandedTasks = $cluster->driver()->getServiceTasks($serviceId)->toArray();

            $service = collect($this->services)->firstWhere('id', $serviceId);
            $this->scaleReplicas = $service['replicas_desired'] ?? 1;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load tasks: '.$e->getMessage());
            $this->expandedTasks = [];
        }
    }

    private function resolveCluster(): Cluster
    {
        if (! $this->clusterModel) {
            $this->clusterModel = Cluster::ownedByTeam(currentTeam()->id)->findOrFail($this->clusterId);
        }

        return $this->clusterModel;
    }
}

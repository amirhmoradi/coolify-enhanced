<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ClusterDashboard extends Component
{
    use AuthorizesRequests;

    public Cluster $cluster;

    public array $nodes = [];

    public array $services = [];

    public array $clusterInfo = [];

    public string $activeTab = 'overview';

    public string $visualizerMode = 'grid';

    public int $pollInterval = 30;

    public string $health = 'unknown';

    public function mount(string $cluster_uuid): void
    {
        if (! config('coolify-enhanced.enabled', false) || ! config('coolify-enhanced.cluster_management', false)) {
            abort(404);
        }

        $this->cluster = Cluster::ownedByTeam(currentTeam()->id)
            ->where('uuid', $cluster_uuid)
            ->with('managerServer')
            ->firstOrFail();

        $this->authorize('view', $this->cluster);
        $this->refreshData();
    }

    public function refreshData(): void
    {
        try {
            $driver = $this->cluster->driver();
            $this->clusterInfo = $driver->getClusterInfo();
            $this->nodes = $driver->getNodes()->toArray();
            $this->services = $driver->getServices()->toArray();
            $this->health = $driver->getClusterHealth();
        } catch (\Throwable $e) {
            $this->health = 'unreachable';
            $this->dispatch('error', 'Failed to refresh cluster data: '.$e->getMessage());
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function setVisualizerMode(string $mode): void
    {
        if (in_array($mode, ['grid', 'topology'])) {
            $this->visualizerMode = $mode;
        }
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.cluster-dashboard');
    }
}

<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ClusterVisualizer extends Component
{
    use AuthorizesRequests;

    public int $clusterId;

    public string $mode = 'grid';

    public array $nodes = [];

    public array $services = [];

    public array $tasksByNode = [];

    private ?Cluster $clusterModel = null;

    public function mount(int $clusterId, string $mode = 'grid'): void
    {
        $this->clusterId = $clusterId;
        $this->mode = in_array($mode, ['grid', 'topology']) ? $mode : 'grid';
        $this->refreshData();
    }

    public function refreshData(): void
    {
        try {
            $cluster = $this->resolveCluster();
            $driver = $cluster->driver();

            $this->nodes = Cache::remember(
                "cluster:{$this->clusterId}:nodes",
                30,
                fn () => $driver->getNodes()->toArray()
            );

            $this->services = Cache::remember(
                "cluster:{$this->clusterId}:services",
                30,
                fn () => $driver->getServices()->toArray()
            );

            $allTasks = $driver->getAllTasks();

            $grouped = [];
            foreach ($this->nodes as $node) {
                $grouped[$node['id']] = [];
            }

            foreach ($allTasks as $task) {
                $nodeId = $task['node_id'] ?? 'unassigned';
                if (! isset($grouped[$nodeId])) {
                    $grouped[$nodeId] = [];
                }
                $grouped[$nodeId][] = $task;
            }

            $this->tasksByNode = $grouped;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load visualizer data: '.$e->getMessage());
        }
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['grid', 'topology'])) {
            $this->mode = $mode;
        }
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.cluster-visualizer');
    }

    private function resolveCluster(): Cluster
    {
        if (! $this->clusterModel) {
            $this->clusterModel = Cluster::ownedByTeam(currentTeam()->id)->findOrFail($this->clusterId);
        }

        return $this->clusterModel;
    }
}

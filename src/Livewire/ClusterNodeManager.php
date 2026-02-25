<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ClusterNodeManager extends Component
{
    use AuthorizesRequests;

    public Cluster $cluster;

    public array $nodes = [];

    public ?string $selectedNodeId = null;

    public array $selectedNodeLabels = [];

    public string $newLabelKey = '';

    public string $newLabelValue = '';

    public function mount(int $clusterId): void
    {
        $this->cluster = Cluster::ownedByTeam(currentTeam()->id)->findOrFail($clusterId);
        $this->refreshNodes();
    }

    public function refreshNodes(): void
    {
        try {
            $this->nodes = Cache::remember(
                "cluster:{$this->cluster->id}:nodes",
                30,
                fn () => $this->cluster->driver()->getNodes()->toArray()
            );
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load nodes: '.$e->getMessage());
            $this->nodes = [];
        }
    }

    public function drainNode(string $nodeId): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);
            $this->cluster->driver()->updateNodeAvailability($nodeId, 'drain');
            $this->invalidateAndRefresh();
            $this->dispatch('success', 'Node set to drain. Tasks will be rescheduled.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to drain node: '.$e->getMessage());
        }
    }

    public function activateNode(string $nodeId): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);
            $this->cluster->driver()->updateNodeAvailability($nodeId, 'active');
            $this->invalidateAndRefresh();
            $this->dispatch('success', 'Node activated.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to activate node: '.$e->getMessage());
        }
    }

    public function pauseNode(string $nodeId): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);
            $this->cluster->driver()->updateNodeAvailability($nodeId, 'pause');
            $this->invalidateAndRefresh();
            $this->dispatch('success', 'Node paused. No new tasks will be scheduled.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to pause node: '.$e->getMessage());
        }
    }

    public function promoteNode(string $nodeId): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);
            $this->cluster->driver()->promoteNode($nodeId);
            $this->invalidateAndRefresh();
            $this->dispatch('success', 'Node promoted to manager.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to promote node: '.$e->getMessage());
        }
    }

    public function demoteNode(string $nodeId): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);
            $this->cluster->driver()->demoteNode($nodeId);
            $this->invalidateAndRefresh();
            $this->dispatch('success', 'Node demoted to worker.');
        } catch (\RuntimeException $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to demote node: '.$e->getMessage());
        }
    }

    public function removeNode(string $nodeId): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);

            $node = collect($this->nodes)->firstWhere('id', $nodeId);
            if ($node && ($node['availability'] ?? '') !== 'drain') {
                $this->dispatch('error', 'Node must be drained before removal. Drain it first.');

                return;
            }

            $this->cluster->driver()->removeNode($nodeId, force: true);
            $this->invalidateAndRefresh();

            if ($this->selectedNodeId === $nodeId) {
                $this->selectedNodeId = null;
                $this->selectedNodeLabels = [];
            }

            $this->dispatch('success', 'Node removed from cluster.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to remove node: '.$e->getMessage());
        }
    }

    public function selectNode(string $nodeId): void
    {
        if ($this->selectedNodeId === $nodeId) {
            $this->selectedNodeId = null;
            $this->selectedNodeLabels = [];

            return;
        }

        $this->selectedNodeId = $nodeId;

        try {
            $node = $this->cluster->driver()->getNode($nodeId);
            $this->selectedNodeLabels = $node['labels'] ?? [];
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load node details: '.$e->getMessage());
            $this->selectedNodeLabels = [];
        }
    }

    public function addLabel(): void
    {
        $this->validate([
            'newLabelKey' => 'required|string|max:255',
            'newLabelValue' => 'required|string|max:255',
        ]);

        try {
            $this->authorize('manageNodes', $this->cluster);

            if (! $this->selectedNodeId) {
                $this->dispatch('error', 'No node selected.');

                return;
            }

            $this->cluster->driver()->updateNodeLabels(
                $this->selectedNodeId,
                add: [$this->newLabelKey => $this->newLabelValue]
            );

            $this->newLabelKey = '';
            $this->newLabelValue = '';

            $node = $this->cluster->driver()->getNode($this->selectedNodeId);
            $this->selectedNodeLabels = $node['labels'] ?? [];
            $this->invalidateAndRefresh();

            $this->dispatch('success', 'Label added.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to add label: '.$e->getMessage());
        }
    }

    public function removeLabel(string $key): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);

            if (! $this->selectedNodeId) {
                $this->dispatch('error', 'No node selected.');

                return;
            }

            $this->cluster->driver()->updateNodeLabels(
                $this->selectedNodeId,
                remove: [$key]
            );

            $node = $this->cluster->driver()->getNode($this->selectedNodeId);
            $this->selectedNodeLabels = $node['labels'] ?? [];
            $this->invalidateAndRefresh();

            $this->dispatch('success', 'Label removed.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to remove label: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.cluster-node-manager');
    }

    private function invalidateAndRefresh(): void
    {
        Cache::forget("cluster:{$this->cluster->id}:nodes");
        $this->refreshNodes();
    }
}

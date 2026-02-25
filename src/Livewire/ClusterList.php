<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Services\ClusterDetectionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ClusterList extends Component
{
    use AuthorizesRequests;

    public array $clusters = [];

    public function mount(): void
    {
        if (! config('coolify-enhanced.enabled', false) || ! config('coolify-enhanced.cluster_management', false)) {
            abort(404);
        }

        if (! currentTeam()) {
            $this->dispatch('error', 'No team selected. Please select a team to view clusters.');

            return;
        }

        $this->loadClusters();
    }

    public function loadClusters(): void
    {
        try {
            $team = currentTeam();
            if (! $team) {
                $this->clusters = [];

                return;
            }

            $this->clusters = Cluster::ownedByTeam($team->id)
                ->with('managerServer')
                ->orderBy('name')
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load clusters: '.$e->getMessage());
            $this->clusters = [];
        }
    }

    public function autoDetect(): void
    {
        $this->authorize('create', Cluster::class);

        $team = currentTeam();
        if (! $team) {
            $this->dispatch('error', 'No team selected.');

            return;
        }

        try {
            $detection = app(ClusterDetectionService::class);
            $detected = $detection->detectClusters($team->id);

            if (count($detected) > 0) {
                $this->dispatch('success', count($detected).' cluster(s) detected and registered.');
            } else {
                $this->dispatch('info', 'No new Swarm clusters detected. Ensure at least one server is a Swarm manager.');
            }

            $this->loadClusters();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Auto-detection failed: '.$e->getMessage());
        }
    }

    public function syncCluster(string $uuid): void
    {
        try {
            $team = currentTeam();
            if (! $team) {
                $this->dispatch('error', 'No team selected.');

                return;
            }

            $cluster = Cluster::ownedByTeam($team->id)
                ->where('uuid', $uuid)
                ->firstOrFail();

            $this->authorize('view', $cluster);

            $detection = app(ClusterDetectionService::class);
            $detection->syncClusterMetadata($cluster);

            $this->dispatch('success', 'Cluster metadata synced.');
            $this->loadClusters();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Sync failed: '.$e->getMessage());
        }
    }

    public function deleteCluster(string $uuid): void
    {
        try {
            $team = currentTeam();
            if (! $team) {
                $this->dispatch('error', 'No team selected.');

                return;
            }

            $cluster = Cluster::ownedByTeam($team->id)
                ->where('uuid', $uuid)
                ->firstOrFail();

            $this->authorize('delete', $cluster);

            $cluster->servers()->update(['cluster_id' => null]);
            $cluster->events()->delete();
            $cluster->secrets()->delete();
            $cluster->configs()->delete();
            $cluster->delete();

            $this->dispatch('success', 'Cluster removed.');
            $this->loadClusters();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete cluster: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.cluster-list');
    }
}

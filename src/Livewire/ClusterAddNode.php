<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Services\ClusterDetectionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ClusterAddNode extends Component
{
    use AuthorizesRequests;

    public Cluster $cluster;

    public string $role = 'worker';

    public string $joinCommand = '';

    public bool $showWizard = false;

    public int $step = 1;

    public string $managerIp = '';

    public int $initialNodeCount = 0;

    public function mount(int $clusterId): void
    {
        $this->cluster = Cluster::ownedByTeam(currentTeam()->id)->findOrFail($clusterId);

        $managerServer = $this->cluster->managerServer;
        $this->managerIp = $managerServer?->ip ?? '';
    }

    public function openWizard(): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);
            $this->showWizard = true;
            $this->step = 1;
            $this->role = 'worker';
            $this->joinCommand = '';
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Not authorized to add nodes.');
        }
    }

    public function generateJoinCommand(): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);

            $tokens = $this->cluster->driver()->getJoinTokens();
            $token = $this->role === 'manager'
                ? ($tokens['manager'] ?? '')
                : ($tokens['worker'] ?? '');

            if (empty($token)) {
                $this->dispatch('error', 'Could not retrieve join token. Is this a Swarm manager?');

                return;
            }

            $advertiseAddr = $this->managerIp ?: ($this->cluster->managerServer?->ip ?? '');
            if (empty($advertiseAddr)) {
                $this->dispatch('error', 'Manager IP address could not be determined.');

                return;
            }

            $this->joinCommand = "docker swarm join --token {$token} {$advertiseAddr}:2377";

            $nodes = $this->cluster->driver()->getNodes();
            $this->initialNodeCount = $nodes->count();

            $this->step = 2;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to generate join command: '.$e->getMessage());
        }
    }

    public function checkNewNode(): void
    {
        try {
            $this->authorize('manageNodes', $this->cluster);

            Cache::forget("cluster:{$this->cluster->id}:nodes");
            $currentNodes = $this->cluster->driver()->getNodes();

            if ($currentNodes->count() > $this->initialNodeCount) {
                $detection = app(ClusterDetectionService::class);
                $detection->syncClusterMetadata($this->cluster);

                $this->step = 3;
                $this->dispatch('success', 'New node detected and registered.');
            } else {
                $this->dispatch('info', 'No new node detected yet. Run the join command on the target server and try again.');
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to check for new nodes: '.$e->getMessage());
        }
    }

    public function closeWizard(): void
    {
        $this->showWizard = false;
        $this->step = 1;
        $this->joinCommand = '';
        $this->role = 'worker';
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.cluster-add-node');
    }
}

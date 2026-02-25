<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Models\SwarmSecret;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ClusterSecrets extends Component
{
    use AuthorizesRequests;

    public int $clusterId;

    public array $secrets = [];

    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newValue = '';

    public array $newLabels = [];

    private ?Cluster $clusterModel = null;

    public function mount(int $clusterId): void
    {
        $this->clusterId = $clusterId;
        $this->refreshSecrets();
    }

    public function refreshSecrets(): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->secrets = $cluster->driver()->getSecrets()->toArray();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load secrets: '.$e->getMessage());
            $this->secrets = [];
        }
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;

        if (! $this->showCreateForm) {
            $this->resetCreateForm();
        }
    }

    public function createSecret(): void
    {
        $this->validate([
            'newName' => 'required|string|max:255|regex:/^[a-zA-Z0-9_\-\.]+$/',
            'newValue' => 'required|string',
        ]);

        try {
            $cluster = $this->resolveCluster();
            $this->authorize('manageSecrets', $cluster);

            $labels = $this->parseLabels($this->newLabels);

            $dockerId = $cluster->driver()->createSecret($this->newName, $this->newValue, $labels);

            SwarmSecret::create([
                'docker_id' => $dockerId,
                'cluster_id' => $this->clusterId,
                'name' => $this->newName,
                'labels' => $labels,
            ]);

            $name = $this->newName;
            $this->resetCreateForm();
            $this->showCreateForm = false;
            $this->refreshSecrets();
            $this->dispatch('success', "Secret '{$name}' created.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create secret: '.$e->getMessage());
        }
    }

    public function removeSecret(string $secretId): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->authorize('manageSecrets', $cluster);

            $cluster->driver()->removeSecret($secretId);

            SwarmSecret::where('cluster_id', $this->clusterId)
                ->where('docker_id', $secretId)
                ->delete();

            $this->refreshSecrets();
            $this->dispatch('success', 'Secret removed.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to remove secret: '.$e->getMessage());
        }
    }

    public function addLabelRow(): void
    {
        $this->newLabels[] = ['key' => '', 'value' => ''];
    }

    public function removeLabelRow(int $index): void
    {
        unset($this->newLabels[$index]);
        $this->newLabels = array_values($this->newLabels);
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.cluster-secrets');
    }

    private function resolveCluster(): Cluster
    {
        if (! $this->clusterModel) {
            $this->clusterModel = Cluster::ownedByTeam(currentTeam()->id)->findOrFail($this->clusterId);
        }

        return $this->clusterModel;
    }

    private function resetCreateForm(): void
    {
        $this->newName = '';
        $this->newValue = '';
        $this->newLabels = [];
    }

    private function parseLabels(array $labelRows): array
    {
        $labels = [];
        foreach ($labelRows as $row) {
            $key = trim($row['key'] ?? '');
            $value = trim($row['value'] ?? '');
            if ($key !== '') {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }
}

<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Models\SwarmConfig;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ClusterConfigs extends Component
{
    use AuthorizesRequests;

    public int $clusterId;

    public array $configs = [];

    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newData = '';

    public array $newLabels = [];

    public ?string $viewingConfigId = null;

    public ?string $viewingConfigData = null;

    private ?Cluster $clusterModel = null;

    public function mount(int $clusterId): void
    {
        $this->clusterId = $clusterId;
        $this->refreshConfigs();
    }

    public function refreshConfigs(): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->configs = $cluster->driver()->getConfigs()->toArray();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load configs: '.$e->getMessage());
            $this->configs = [];
        }
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;

        if (! $this->showCreateForm) {
            $this->resetCreateForm();
        }
    }

    public function createConfig(): void
    {
        $this->validate([
            'newName' => 'required|string|max:255|regex:/^[a-zA-Z0-9_\-\.]+$/',
            'newData' => 'required|string',
        ]);

        try {
            $cluster = $this->resolveCluster();
            $this->authorize('manageConfigs', $cluster);

            $labels = $this->parseLabels($this->newLabels);

            $dockerId = $cluster->driver()->createConfig($this->newName, $this->newData, $labels);

            SwarmConfig::create([
                'docker_id' => $dockerId,
                'cluster_id' => $this->clusterId,
                'name' => $this->newName,
                'data' => $this->newData,
                'labels' => $labels,
            ]);

            $name = $this->newName;
            $this->resetCreateForm();
            $this->showCreateForm = false;
            $this->refreshConfigs();
            $this->dispatch('success', "Config '{$name}' created.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create config: '.$e->getMessage());
        }
    }

    public function viewConfig(string $configId): void
    {
        if ($this->viewingConfigId === $configId) {
            $this->viewingConfigId = null;
            $this->viewingConfigData = null;

            return;
        }

        try {
            $cluster = $this->resolveCluster();
            $allConfigs = $cluster->driver()->getConfigs();
            $config = $allConfigs->firstWhere('id', $configId);

            $this->viewingConfigId = $configId;
            $this->viewingConfigData = $config['data'] ?? '(unable to read config data)';
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to read config: '.$e->getMessage());
        }
    }

    public function removeConfig(string $configId): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->authorize('manageConfigs', $cluster);

            $cluster->driver()->removeConfig($configId);

            SwarmConfig::where('cluster_id', $this->clusterId)
                ->where('docker_id', $configId)
                ->delete();

            if ($this->viewingConfigId === $configId) {
                $this->viewingConfigId = null;
                $this->viewingConfigData = null;
            }

            $this->refreshConfigs();
            $this->dispatch('success', 'Config removed.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to remove config: '.$e->getMessage());
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
        return view('coolify-enhanced::livewire.cluster-configs');
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
        $this->newData = '';
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

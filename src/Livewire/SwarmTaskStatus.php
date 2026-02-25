<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class SwarmTaskStatus extends Component
{
    use AuthorizesRequests;

    public $resource;

    public array $tasks = [];

    public ?int $clusterId = null;

    public ?string $serviceName = null;

    public bool $isSwarmResource = false;

    public function mount($resource): void
    {
        $this->resource = $resource;
        $this->loadTasks();
    }

    public function loadTasks(): void
    {
        $this->tasks = [];
        $this->isSwarmResource = false;

        try {
            $server = $this->resolveServer();
            if (! $server) {
                return;
            }

            $isSwarm = method_exists($server, 'isSwarm') && $server->isSwarm();
            if (! $isSwarm) {
                return;
            }

            $this->isSwarmResource = true;

            $cluster = Cluster::where('team_id', currentTeam()->id)
                ->where(function ($q) use ($server) {
                    $q->whereHas('servers', fn ($q2) => $q2->where('servers.id', $server->id))
                      ->orWhere('manager_server_id', $server->id);
                })
                ->first();

            if (! $cluster) {
                return;
            }

            $this->clusterId = $cluster->id;
            $driver = $cluster->driver();

            $serviceName = $this->resolveServiceName();
            if (! $serviceName) {
                return;
            }

            $this->serviceName = $serviceName;

            $services = $driver->getServices();
            $service = $services->first(fn ($s) => str_contains($s['name'] ?? '', $serviceName));

            if (! $service) {
                return;
            }

            $this->tasks = $driver->getServiceTasks($service['id'])->toArray();
        } catch (\Throwable) {
            $this->tasks = [];
        }
    }

    public function refreshTasks(): void
    {
        $this->loadTasks();
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.swarm-task-status');
    }

    private function resolveServer()
    {
        if ($this->resource instanceof Application) {
            $destination = $this->resource->destination;

            return $destination?->server ?? null;
        }

        if ($this->resource instanceof Service) {
            return $this->resource->server;
        }

        if (method_exists($this->resource, 'service')) {
            $service = $this->resource->service;

            return $service?->server ?? null;
        }

        if (property_exists($this->resource, 'server') || method_exists($this->resource, 'server')) {
            return $this->resource->server ?? null;
        }

        return null;
    }

    private function resolveServiceName(): ?string
    {
        if ($this->resource instanceof Application) {
            return $this->resource->uuid;
        }

        if ($this->resource instanceof Service) {
            return $this->resource->uuid;
        }

        if (property_exists($this->resource, 'uuid')) {
            return $this->resource->uuid;
        }

        return null;
    }
}

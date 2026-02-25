<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Models\ClusterEvent;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ClusterEvents extends Component
{
    use AuthorizesRequests;

    public int $clusterId;

    public array $events = [];

    public ?string $filterType = null;

    public ?string $filterAction = null;

    public int $perPage = 50;

    private ?Cluster $clusterModel = null;

    public function mount(int $clusterId): void
    {
        $this->clusterId = $clusterId;
        $this->loadEvents();
    }

    public function loadEvents(): void
    {
        try {
            $cluster = $this->resolveCluster();
            $query = ClusterEvent::where('cluster_id', $cluster->id)
                ->orderByDesc('event_time');

            if ($this->filterType) {
                $query->where('event_type', $this->filterType);
            }

            if ($this->filterAction) {
                $query->where('action', $this->filterAction);
            }

            $this->events = $query->limit($this->perPage)->get()->toArray();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load events: '.$e->getMessage());
            $this->events = [];
        }
    }

    public function setFilter(?string $type, ?string $action): void
    {
        $this->filterType = $type ?: null;
        $this->filterAction = $action ?: null;
        $this->loadEvents();
    }

    public function clearFilters(): void
    {
        $this->filterType = null;
        $this->filterAction = null;
        $this->loadEvents();
    }

    public function collectEvents(): void
    {
        try {
            $cluster = $this->resolveCluster();
            $this->authorize('view', $cluster);

            $driver = $cluster->driver();
            $since = ClusterEvent::where('cluster_id', $cluster->id)
                ->max('event_time');

            $sinceTimestamp = $since ? strtotime($since) : (time() - 3600);

            $rawEvents = $driver->getEvents($sinceTimestamp);

            $created = 0;
            foreach ($rawEvents as $event) {
                ClusterEvent::firstOrCreate(
                    [
                        'cluster_id' => $cluster->id,
                        'event_type' => $event['type'] ?? 'unknown',
                        'action' => $event['action'] ?? 'unknown',
                        'event_time' => \Carbon\Carbon::createFromTimestamp($event['time']),
                        'actor_id' => $event['actor_id'] ?? null,
                    ],
                    [
                        'actor_name' => $event['actor_name'] ?? null,
                        'attributes' => $event['attributes'] ?? [],
                        'scope' => $event['scope'] ?? null,
                    ]
                );
                $created++;
            }

            $this->dispatch('success', "{$created} event(s) collected.");
            $this->loadEvents();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to collect events: '.$e->getMessage());
        }
    }

    public function render()
    {
        $cluster = $this->resolveCluster();
        $verifiedId = $cluster->id;

        $availableTypes = ClusterEvent::where('cluster_id', $verifiedId)
            ->distinct()
            ->pluck('event_type')
            ->toArray();

        $availableActions = ClusterEvent::where('cluster_id', $verifiedId)
            ->distinct()
            ->pluck('action')
            ->toArray();

        return view('coolify-enhanced::livewire.cluster-events', [
            'availableTypes' => $availableTypes,
            'availableActions' => $availableActions,
        ]);
    }

    private function resolveCluster(): Cluster
    {
        if (! $this->clusterModel) {
            $this->clusterModel = Cluster::ownedByTeam(currentTeam()->id)->findOrFail($this->clusterId);
        }

        return $this->clusterModel;
    }
}

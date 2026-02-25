<?php

namespace AmirhMoradi\CoolifyEnhanced\Jobs;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Models\ClusterEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClusterEventCollectorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    public function __construct(
        public int $clusterId,
        public int $sinceSec = 300,
    ) {}

    public function handle(): void
    {
        if (! config('coolify-enhanced.enabled', false)) {
            return;
        }
        if (! config('coolify-enhanced.cluster_management', false)) {
            return;
        }

        $cluster = Cluster::find($this->clusterId);
        if (! $cluster || ! $cluster->managerServer) {
            return;
        }

        try {
            $since = time() - $this->sinceSec;
            $events = $cluster->driver()->getEvents($since);

            foreach ($events as $event) {
                $eventTime = data_get($event, 'time', 0);
                if ($eventTime <= 0) {
                    continue;
                }

                ClusterEvent::updateOrCreate(
                    [
                        'cluster_id' => $cluster->id,
                        'event_type' => data_get($event, 'type', ''),
                        'action' => data_get($event, 'action', ''),
                        'actor_id' => data_get($event, 'actor_id'),
                        'event_time' => \Carbon\Carbon::createFromTimestamp($eventTime),
                    ],
                    [
                        'actor_name' => data_get($event, 'actor_name'),
                        'attributes' => data_get($event, 'attributes', []),
                        'scope' => data_get($event, 'scope'),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('ClusterEventCollector: Failed', [
                'cluster_id' => $this->clusterId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ClusterEventCollectorJob failed', [
            'cluster_id' => $this->clusterId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

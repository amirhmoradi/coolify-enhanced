<?php

namespace AmirhMoradi\CoolifyEnhanced\Jobs;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Services\ClusterDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClusterSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function __construct(
        public int $clusterId,
    ) {}

    public function handle(ClusterDetectionService $service): void
    {
        if (! config('coolify-enhanced.enabled', false)) {
            return;
        }
        if (! config('coolify-enhanced.cluster_management', false)) {
            return;
        }

        $cluster = Cluster::find($this->clusterId);
        if (! $cluster) {
            return;
        }

        $service->syncClusterMetadata($cluster);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ClusterSyncJob failed', [
            'cluster_id' => $this->clusterId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

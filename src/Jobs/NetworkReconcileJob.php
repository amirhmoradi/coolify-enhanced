<?php

namespace AmirhMoradi\CoolifyEnhanced\Jobs;

use AmirhMoradi\CoolifyEnhanced\Models\ManagedNetwork;
use AmirhMoradi\CoolifyEnhanced\Models\ResourceNetwork;
use AmirhMoradi\CoolifyEnhanced\Services\NetworkService;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NetworkReconcileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [5, 15, 30];

    public $timeout = 120;

    public function __construct(
        public $resource,
        public bool $fullReconcile = false
    ) {}

    /**
     * Prevent overlapping reconciliation for the same resource.
     */
    public function middleware(): array
    {
        $key = $this->fullReconcile
            ? 'network-reconcile-server'
            : 'network-reconcile-'.get_class($this->resource).'-'.$this->resource->id;

        return [
            (new WithoutOverlapping($key))->releaseAfter(60)->dontRelease(),
        ];
    }

    public function handle(): void
    {
        // Safety: exit if feature disabled
        if (! config('coolify-enhanced.enabled', false) || ! config('coolify-enhanced.network_management.enabled', false)) {
            return;
        }

        try {
            if ($this->fullReconcile) {
                $this->reconcileServer();
            } else {
                $this->reconcileResource();
            }
        } catch (\Throwable $e) {
            Log::error('NetworkReconcileJob: Failed', [
                'resource' => get_class($this->resource).'#'.$this->resource->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Let the queue retry
        }
    }

    protected function reconcileResource(): void
    {
        $server = NetworkService::getServerForResource($this->resource);
        if (! $server || ! $server->isFunctional()) {
            Log::warning('NetworkReconcileJob: Server not functional, skipping');

            return;
        }

        $environment = NetworkService::getEnvironmentForResource($this->resource);
        if (! $environment) {
            Log::warning('NetworkReconcileJob: No environment found for resource');

            return;
        }

        $isolationMode = config('coolify-enhanced.network_management.isolation_mode', 'environment');
        if ($isolationMode === 'none') {
            return;
        }

        // 1. Ensure environment network exists on this server
        $envNetwork = NetworkService::ensureEnvironmentNetwork($environment, $server);

        // 2. Get container names for this resource
        $containerNames = NetworkService::getContainerNames($this->resource);
        if (empty($containerNames)) {
            Log::info('NetworkReconcileJob: No containers found for resource');

            return;
        }

        // 3. Connect each container to the environment network
        foreach ($containerNames as $containerName) {
            $connected = NetworkService::connectContainer(
                $server,
                $envNetwork->docker_network_name,
                $containerName,
                [$containerName] // Use container name as alias for DNS
            );

            if ($connected) {
                // Create or update the pivot record
                ResourceNetwork::updateOrCreate(
                    [
                        'resource_type' => get_class($this->resource),
                        'resource_id' => $this->resource->id,
                        'managed_network_id' => $envNetwork->id,
                    ],
                    [
                        'is_auto_attached' => true,
                        'is_connected' => true,
                        'connected_at' => now(),
                        'aliases' => [$containerName],
                    ]
                );
            }
        }

        // 4. If proxy isolation is enabled and resource has FQDN, connect to proxy network
        if (config('coolify-enhanced.network_management.proxy_isolation', false)
            && NetworkService::resourceHasFqdn($this->resource)) {
            $proxyNetwork = NetworkService::ensureProxyNetwork($server);

            foreach ($containerNames as $containerName) {
                NetworkService::connectContainer(
                    $server,
                    $proxyNetwork->docker_network_name,
                    $containerName
                );

                ResourceNetwork::updateOrCreate(
                    [
                        'resource_type' => get_class($this->resource),
                        'resource_id' => $this->resource->id,
                        'managed_network_id' => $proxyNetwork->id,
                    ],
                    [
                        'is_auto_attached' => true,
                        'is_connected' => true,
                        'connected_at' => now(),
                    ]
                );
            }
        }

        // 5. If strict mode: disconnect from the default 'coolify' network
        if ($isolationMode === 'strict') {
            foreach ($containerNames as $containerName) {
                NetworkService::disconnectContainer($server, 'coolify', $containerName);
            }
        }

        Log::info('NetworkReconcileJob: Reconciled resource', [
            'resource' => get_class($this->resource).'#'.$this->resource->id,
            'containers' => $containerNames,
            'network' => $envNetwork->docker_network_name,
        ]);
    }

    protected function reconcileServer(): void
    {
        $server = NetworkService::getServerForResource($this->resource);
        if (! $server) {
            return;
        }

        NetworkService::reconcileServer($server);

        // Ensure proxy container is connected to proxy network if proxy isolation is enabled
        if (config('coolify-enhanced.network_management.proxy_isolation', false)) {
            NetworkService::connectProxyContainer($server);
        }

        Log::info('NetworkReconcileJob: Full server reconciliation complete', [
            'server' => $server->name,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('NetworkReconcileJob permanently failed', [
            'job' => 'NetworkReconcileJob',
            'resource' => get_class($this->resource).'#'.$this->resource->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}

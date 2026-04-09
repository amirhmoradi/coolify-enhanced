<?php

namespace CorelixIo\Platform\Jobs;

use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Models\ResourceNetwork;
use CorelixIo\Platform\Services\NetworkService;
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
            ? 'network-reconcile-server-'.get_class($this->resource).'-'.$this->resource->id
            : 'network-reconcile-'.get_class($this->resource).'-'.$this->resource->id;

        return [
            (new WithoutOverlapping($key))->releaseAfter(60)->dontRelease(),
        ];
    }

    public function handle(): void
    {
        // Safety: exit if feature disabled
        if (! config('corelix-platform.enabled', false) || ! config('corelix-platform.network_management.enabled', false)) {
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

        if (config('corelix-platform.network_management.isolation_mode', 'none') === 'none') {
            return;
        }

        // Determine if this is a Swarm server
        $isSwarm = NetworkService::isSwarmServer($server);

        // 1. Ensure environment network exists on this server
        $envNetwork = NetworkService::ensureEnvironmentNetwork($environment, $server);

        // 2. Determine proxy network if applicable
        $proxyNetwork = null;
        $proxyIsolation = config('corelix-platform.network_management.proxy_isolation', false);
        if ($proxyIsolation && NetworkService::resourceHasFqdn($this->resource)) {
            $proxyNetwork = NetworkService::ensureProxyNetwork($server);
        }

        if ($isSwarm) {
            // Swarm mode: use docker service update --network-add (batched)
            $this->reconcileSwarmResource($server, $envNetwork, $proxyNetwork);
        } else {
            // Standalone mode: use docker network connect (per-container)
            $this->reconcileStandaloneResource($server, $envNetwork, $proxyNetwork);
        }

        Log::info('NetworkReconcileJob: Reconciled resource', [
            'resource' => get_class($this->resource).'#'.$this->resource->id,
            'network' => $envNetwork->docker_network_name,
            'swarm' => $isSwarm,
        ]);
    }

    /**
     * Reconcile a resource on a standalone Docker server.
     * Uses docker network connect per container.
     */
    protected function reconcileStandaloneResource(
        Server $server,
        ManagedNetwork $envNetwork,
        ?ManagedNetwork $proxyNetwork
    ): void {
        $containerNames = NetworkService::getContainerNames($this->resource);
        if (empty($containerNames)) {
            Log::info('NetworkReconcileJob: No containers found for resource');

            return;
        }

        // Connect each container to the environment network
        foreach ($containerNames as $containerName) {
            $connected = NetworkService::connectContainer(
                $server,
                $envNetwork->docker_network_name,
                $containerName,
                [$containerName]
            );

            if ($connected) {
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

        // Connect to proxy network if applicable
        if ($proxyNetwork) {
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

        // Strict mode: disconnect from default network
        if (NetworkService::shouldDisconnectFromDefaultNetwork($this->resource)) {
            $defaultNetwork = NetworkService::getDefaultNetworkName($server);
            foreach ($containerNames as $containerName) {
                NetworkService::disconnectContainer($server, $defaultNetwork, $containerName);
            }
        }
    }

    /**
     * Reconcile a resource on a Docker Swarm server.
     * Uses docker service update --network-add (batched into single command).
     *
     * Key difference from standalone: Swarm tasks cannot use `docker network connect`.
     * Network membership is controlled via the service spec, so we use
     * `docker service update --network-add` which triggers a rolling update.
     */
    protected function reconcileSwarmResource(
        Server $server,
        ManagedNetwork $envNetwork,
        ?ManagedNetwork $proxyNetwork
    ): void {
        $serviceNames = NetworkService::getSwarmServiceNames($this->resource, $server);
        if (empty($serviceNames)) {
            Log::info('NetworkReconcileJob: No Swarm services found for resource');

            return;
        }

        // Collect networks to add and remove
        $networksToAdd = [$envNetwork->docker_network_name];
        if ($proxyNetwork) {
            $networksToAdd[] = $proxyNetwork->docker_network_name;
        }

        $networksToRemove = [];
        if (NetworkService::shouldDisconnectFromDefaultNetwork($this->resource)) {
            $networksToRemove[] = NetworkService::getDefaultNetworkName($server);
        }

        // Apply to each Swarm service
        foreach ($serviceNames as $serviceName) {
            $success = NetworkService::updateSwarmServiceNetworks(
                $server,
                $serviceName,
                $networksToAdd,
                $networksToRemove
            );

            if ($success) {
                // Update pivot records for environment network
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
                        'aliases' => [$serviceName],
                    ]
                );

                // Update pivot records for proxy network
                if ($proxyNetwork) {
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
        }
    }

    protected function reconcileServer(): void
    {
        $server = NetworkService::getServerForResource($this->resource);
        if (! $server) {
            return;
        }

        NetworkService::reconcileServer($server);

        // Ensure proxy container is connected to proxy network if proxy isolation is enabled
        if (config('corelix-platform.network_management.proxy_isolation', false)) {
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

<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class NetworkManagementProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'NETWORK_MANAGEMENT';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        \Livewire\Livewire::component('enhanced::network-manager', \CorelixIo\Platform\Livewire\NetworkManager::class);
        \Livewire\Livewire::component('enhanced::network-manager-page', \CorelixIo\Platform\Livewire\NetworkManagerPage::class);
        \Livewire\Livewire::component('enhanced::resource-networks', \CorelixIo\Platform\Livewire\ResourceNetworks::class);
        \Livewire\Livewire::component('enhanced::network-settings', \CorelixIo\Platform\Livewire\NetworkSettings::class);
    }

    public function booted(Application $app): void
    {
        $delay = config('corelix-platform.network_management.post_deploy_delay', 3);

        if (class_exists(\App\Models\ApplicationDeploymentQueue::class)) {
            \App\Models\ApplicationDeploymentQueue::updated(function ($queue) use ($delay) {
                if ($queue->isDirty('status') && $queue->status === 'finished') {
                    try {
                        $application = $queue->application;
                        if ($application) {
                            \CorelixIo\Platform\Jobs\NetworkReconcileJob::dispatch($application)
                                ->delay(now()->addSeconds($delay));
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for deployment', [
                            'deployment_uuid' => $queue->deployment_uuid ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        }

        if (class_exists('App\Events\ServiceStatusChanged')) {
            \Illuminate\Support\Facades\Event::listen('App\Events\ServiceStatusChanged', function ($event) use ($delay) {
                $teamId = $event->teamId ?? null;
                if (!$teamId) {
                    return;
                }
                try {
                    $services = \App\Models\Service::whereHas('environment.project.team', function ($q) use ($teamId) {
                        $q->where('id', $teamId);
                    })->get();
                    foreach ($services as $service) {
                        \CorelixIo\Platform\Jobs\NetworkReconcileJob::dispatch($service)
                            ->delay(now()->addSeconds($delay));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for services', [
                        'team_id' => $teamId, 'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        if (class_exists('App\Events\DatabaseStatusChanged')) {
            \Illuminate\Support\Facades\Event::listen('App\Events\DatabaseStatusChanged', function ($event) use ($delay) {
                $userId = $event->userId ?? null;
                if (!$userId) {
                    return;
                }
                try {
                    $user = \App\Models\User::find($userId);
                    $team = $user?->currentTeam();
                    if (!$team) {
                        return;
                    }
                    $databaseClasses = [
                        \App\Models\StandalonePostgresql::class, \App\Models\StandaloneMysql::class,
                        \App\Models\StandaloneMariadb::class, \App\Models\StandaloneMongodb::class,
                        \App\Models\StandaloneRedis::class, \App\Models\StandaloneKeydb::class,
                        \App\Models\StandaloneDragonfly::class, \App\Models\StandaloneClickhouse::class,
                    ];
                    foreach ($databaseClasses as $dbClass) {
                        if (!class_exists($dbClass)) {
                            continue;
                        }
                        $databases = $dbClass::whereHas('environment.project.team', function ($q) use ($team) {
                            $q->where('id', $team->id);
                        })->get();
                        foreach ($databases as $database) {
                            \CorelixIo\Platform\Jobs\NetworkReconcileJob::dispatch($database)
                                ->delay(now()->addSeconds($delay));
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for databases', [
                        'user_id' => $userId, 'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        if (class_exists(\App\Models\Application::class)) {
            \App\Models\Application::deleting(function ($application) {
                \CorelixIo\Platform\Services\NetworkService::autoDetachResource($application);
            });
        }
        if (class_exists(\App\Models\Service::class)) {
            \App\Models\Service::deleting(function ($service) {
                \CorelixIo\Platform\Services\NetworkService::autoDetachResource($service);
            });
        }
        $databaseModels = [
            \App\Models\StandalonePostgresql::class, \App\Models\StandaloneMysql::class,
            \App\Models\StandaloneMariadb::class, \App\Models\StandaloneMongodb::class,
            \App\Models\StandaloneRedis::class, \App\Models\StandaloneKeydb::class,
            \App\Models\StandaloneDragonfly::class, \App\Models\StandaloneClickhouse::class,
        ];
        foreach ($databaseModels as $modelClass) {
            if (class_exists($modelClass)) {
                $modelClass::deleting(function ($database) {
                    \CorelixIo\Platform\Services\NetworkService::autoDetachResource($database);
                });
            }
        }
    }

    public function apiRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/networks-api.php';
    }

    public function webRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/networks-web.php';
    }
}

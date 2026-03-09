<?php

namespace CorelixIo\Platform;

use CorelixIo\Platform\Http\Middleware\InjectPermissionsUI;
use CorelixIo\Platform\Jobs\NetworkReconcileJob;
use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Scopes\EnvironmentPermissionScope;
use CorelixIo\Platform\Scopes\ProjectPermissionScope;
use CorelixIo\Platform\Services\NetworkService;
use CorelixIo\Platform\Services\PermissionService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class CorelixPlatformServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/features.php', 'features');
        $this->mergeConfigFrom(__DIR__.'/../config/coolify-enhanced.php', 'corelix-platform');

        // Load web routes during register() so they are registered before Coolify's
        // RouteServiceProvider::boot() adds its catch-all Route::any('/{any}', ...).
        // Laravel matches routes in registration order; if our routes are added after
        // the catch-all, GET /clusters and GET /cluster/{uuid} would match the
        // catch-all and redirect to HOME, causing "too many redirects" when cluster
        // management is enabled. See docs/features/cluster-management/REDIRECT_LOOP_INVESTIGATION.md
        if (config('corelix-platform.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerBladeDirectives();
        $this->app['router']->aliasMiddleware('feature', \CorelixIo\Platform\Http\Middleware\FeatureMiddleware::class);

        // Only load if feature is enabled
        if (! config('corelix-platform.enabled', false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'corelix-platform');

        // Load routes
        // Load API routes (web routes are loaded in register() to precede Coolify's catch-all)
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Register Livewire components
        $this->registerLivewireComponents();

        // Register middleware for UI injection into Coolify pages
        // (access matrix on team admin page, backup tabs on resource pages)
        $this->registerMiddleware();

        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/coolify-enhanced.php' => config_path('coolify-enhanced.php'),
        ], 'corelix-platform-config');

        // Publish views for customization
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/corelix-platform'),
        ], 'corelix-platform-views');

        $this->publishes([
            __DIR__.'/../resources/assets/themes' => public_path('vendor/corelix-platform/themes'),
        ], 'corelix-platform-theme');

        // Register global scopes to filter resources based on permissions
        $this->registerScopes();

        // Register resource backup scheduler
        $this->registerResourceBackupScheduler();

        // Register template source auto-sync scheduler
        $this->registerTemplateSyncScheduler();

        // Register network management if enabled
        if (config('corelix-platform.network_management.enabled', false)) {
            $this->registerNetworkManagement();
        }



        // Defer policy registration to AFTER all service providers have booted.
        //
        // Laravel boots package providers BEFORE application providers.
        // Coolify's AuthServiceProvider (an app provider) registers its own
        // policies via its $policies property, which calls Gate::policy()
        // internally. If we register policies during our boot(), Coolify's
        // AuthServiceProvider boots afterwards and overwrites our policies
        // with its permissive defaults (all return true).
        //
        // By deferring to the 'booted' callback, our Gate::policy() calls
        // execute after ALL providers have booted, ensuring we get the last
        // word and our permission-aware policies take effect.
        $this->app->booted(function () {
            $this->registerPolicies();
            $this->registerUserMacros();
            $this->extendS3StorageModel();
        });
    }

    /**
     * Register Livewire components.
     */
    protected function registerLivewireComponents(): void
    {
        Livewire::component(
            'enhanced::access-matrix',
            \CorelixIo\Platform\Livewire\AccessMatrix::class
        );

        Livewire::component(
            'enhanced::storage-encryption-form',
            \CorelixIo\Platform\Livewire\StorageEncryptionForm::class
        );

        Livewire::component(
            'enhanced::resource-backup-manager',
            \CorelixIo\Platform\Livewire\ResourceBackupManager::class
        );

        Livewire::component(
            'enhanced::resource-backup-page',
            \CorelixIo\Platform\Livewire\ResourceBackupPage::class
        );

        Livewire::component(
            'enhanced::restore-backup',
            \CorelixIo\Platform\Livewire\RestoreBackup::class
        );

        Livewire::component(
            'enhanced::custom-template-sources',
            \CorelixIo\Platform\Livewire\CustomTemplateSources::class
        );

        // Network management components
        Livewire::component(
            'enhanced::network-manager',
            \CorelixIo\Platform\Livewire\NetworkManager::class
        );

        Livewire::component(
            'enhanced::network-manager-page',
            \CorelixIo\Platform\Livewire\NetworkManagerPage::class
        );

        Livewire::component(
            'enhanced::resource-networks',
            \CorelixIo\Platform\Livewire\ResourceNetworks::class
        );

        Livewire::component(
            'enhanced::network-settings',
            \CorelixIo\Platform\Livewire\NetworkSettings::class
        );

        Livewire::component(
            'enhanced::appearance-settings',
            \CorelixIo\Platform\Livewire\AppearanceSettings::class
        );


    }

    /**
     * Register the UI injection middleware.
     */
    protected function registerMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(InjectPermissionsUI::class);
    }

    /**
     * Register custom Blade directives for feature flag checks.
     */
    protected function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::if('feature', function (string $key) {
            return \CorelixIo\Platform\Support\Feature::enabled($key);
        });
    }

    /**
     * Register Eloquent global scopes to filter projects and environments
     * based on the authenticated user's permissions.
     */
    protected function registerScopes(): void
    {
        if (class_exists(\App\Models\Project::class)) {
            \App\Models\Project::addGlobalScope(new ProjectPermissionScope);
        }

        if (class_exists(\App\Models\Environment::class)) {
            \App\Models\Environment::addGlobalScope(new EnvironmentPermissionScope);
        }
    }

    /**
     * Register the scheduler for resource backups.
     *
     * Queries enabled resource backup schedules and dispatches jobs
     * according to their cron expressions.
     */
    protected function registerResourceBackupScheduler(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            // Run every minute; check which resource backups are due
            $schedule->call(function () {
                $backups = \CorelixIo\Platform\Models\ScheduledResourceBackup::where('enabled', true)->get();

                foreach ($backups as $backup) {
                    // Use Laravel's CronExpression to check if this backup is due
                    try {
                        $cron = new \Cron\CronExpression($backup->frequency);
                        $timezone = $backup->timezone ?? config('app.timezone', 'UTC');
                        $now = now()->setTimezone($timezone);

                        if ($cron->isDue($now)) {
                            \CorelixIo\Platform\Jobs\ResourceBackupJob::dispatch($backup);
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('ResourceBackup: Invalid cron for backup '.$backup->uuid, [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            })->everyMinute()->name('corelix-platform:resource-backups')->withoutOverlapping();
        });
    }

    /**
     * Register the scheduler for auto-syncing custom template sources.
     *
     * Uses the configured cron expression (default: every 6 hours)
     * to keep custom templates up to date.
     */
    protected function registerTemplateSyncScheduler(): void
    {
        $this->app->booted(function () {
            $frequency = config('corelix-platform.custom_templates.sync_frequency');
            if (! $frequency) {
                return;
            }

            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            $schedule->call(function () {
                $sources = \CorelixIo\Platform\Models\CustomTemplateSource::where('enabled', true)->get();

                foreach ($sources as $source) {
                    \CorelixIo\Platform\Jobs\SyncTemplateSourceJob::dispatch($source);
                }
            })->cron($frequency)->name('corelix-platform:template-sync')->withoutOverlapping();
        });
    }

    /**
     * Register network management: deployment observers for post-deployment
     * network assignment and resource deletion cleanup.
     *
     * Phase 3: Uses ApplicationDeploymentQueue model observer for precise
     * application reconciliation, and team-based lookup for services/databases
     * since Coolify's events only carry teamId/userId (not the resource).
     */
    protected function registerNetworkManagement(): void
    {
        $this->app->booted(function () {
            $delay = config('corelix-platform.network_management.post_deploy_delay', 3);

            // Application deployment: observe ApplicationDeploymentQueue status changes
            // This is the most precise trigger — fires when a specific deployment completes.
            if (class_exists(\App\Models\ApplicationDeploymentQueue::class)) {
                \App\Models\ApplicationDeploymentQueue::updated(function ($queue) use ($delay) {
                    // Only trigger on status transition to 'finished'
                    if ($queue->isDirty('status') && $queue->status === 'finished') {
                        try {
                            $application = $queue->application;
                            if ($application) {
                                NetworkReconcileJob::dispatch($application)
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

            // Service status changes: Coolify's event carries only teamId
            // Find all services in the team and reconcile them
            if (class_exists('App\Events\ServiceStatusChanged')) {
                Event::listen('App\Events\ServiceStatusChanged', function ($event) use ($delay) {
                    $teamId = $event->teamId ?? null;
                    if (! $teamId) {
                        return;
                    }

                    try {
                        $services = \App\Models\Service::whereHas('environment.project.team', function ($q) use ($teamId) {
                            $q->where('id', $teamId);
                        })->get();

                        foreach ($services as $service) {
                            NetworkReconcileJob::dispatch($service)
                                ->delay(now()->addSeconds($delay));
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for services', [
                            'team_id' => $teamId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }

            // Database status changes: Coolify's event carries only userId
            // Find the user's team and reconcile all databases
            if (class_exists('App\Events\DatabaseStatusChanged')) {
                Event::listen('App\Events\DatabaseStatusChanged', function ($event) use ($delay) {
                    $userId = $event->userId ?? null;
                    if (! $userId) {
                        return;
                    }

                    try {
                        $user = \App\Models\User::find($userId);
                        $team = $user?->currentTeam();
                        if (! $team) {
                            return;
                        }

                        // Reconcile all standalone database types in this team
                        $databaseClasses = [
                            \App\Models\StandalonePostgresql::class,
                            \App\Models\StandaloneMysql::class,
                            \App\Models\StandaloneMariadb::class,
                            \App\Models\StandaloneMongodb::class,
                            \App\Models\StandaloneRedis::class,
                            \App\Models\StandaloneKeydb::class,
                            \App\Models\StandaloneDragonfly::class,
                            \App\Models\StandaloneClickhouse::class,
                        ];

                        foreach ($databaseClasses as $dbClass) {
                            if (! class_exists($dbClass)) {
                                continue;
                            }

                            $databases = $dbClass::whereHas('environment.project.team', function ($q) use ($team) {
                                $q->where('id', $team->id);
                            })->get();

                            foreach ($databases as $database) {
                                NetworkReconcileJob::dispatch($database)
                                    ->delay(now()->addSeconds($delay));
                            }
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for databases', [
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }

            // Cleanup on resource deletion
            if (class_exists(\App\Models\Application::class)) {
                \App\Models\Application::deleting(function ($application) {
                    NetworkService::autoDetachResource($application);
                });
            }

            if (class_exists(\App\Models\Service::class)) {
                \App\Models\Service::deleting(function ($service) {
                    NetworkService::autoDetachResource($service);
                });
            }

            // Standalone database deletion cleanup
            $databaseModels = [
                \App\Models\StandalonePostgresql::class,
                \App\Models\StandaloneMysql::class,
                \App\Models\StandaloneMariadb::class,
                \App\Models\StandaloneMongodb::class,
                \App\Models\StandaloneRedis::class,
                \App\Models\StandaloneKeydb::class,
                \App\Models\StandaloneDragonfly::class,
                \App\Models\StandaloneClickhouse::class,
            ];
            foreach ($databaseModels as $modelClass) {
                if (class_exists($modelClass)) {
                    $modelClass::deleting(function ($database) {
                        NetworkService::autoDetachResource($database);
                    });
                }
            }
        });
    }

    /**
     * Override Coolify's default policies with permission-aware versions.
     *
     * Coolify's own policies (as of v4) return true for all operations.
     * We override them to enforce granular project/environment permissions.
     */
    protected function registerPolicies(): void
    {
        $policies = [
            // Core resource policies
            \App\Models\Application::class => \CorelixIo\Platform\Policies\ApplicationPolicy::class,
            \App\Models\Project::class => \CorelixIo\Platform\Policies\ProjectPolicy::class,
            \App\Models\Environment::class => \CorelixIo\Platform\Policies\EnvironmentPolicy::class,
            \App\Models\Server::class => \CorelixIo\Platform\Policies\ServerPolicy::class,
            \App\Models\Service::class => \CorelixIo\Platform\Policies\ServicePolicy::class,

            // Database policies (all types)
            \App\Models\StandalonePostgresql::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMysql::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMariadb::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMongodb::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneRedis::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneKeydb::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneDragonfly::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneClickhouse::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,

            // Sub-resource policies (Coolify's defaults return true for everything)
            \App\Models\EnvironmentVariable::class => \CorelixIo\Platform\Policies\EnvironmentVariablePolicy::class,

            // Network management policy
            ManagedNetwork::class => \CorelixIo\Platform\Policies\NetworkPolicy::class,


        ];

        foreach ($policies as $model => $policy) {
            if (class_exists($model)) {
                Gate::policy($model, $policy);
            }
        }
    }

    /**
     * Extend the S3Storage model with encryption support.
     *
     * Adds encrypted casts for encryption_password and encryption_salt columns
     * so they are stored encrypted at rest in the database (like key/secret).
     * Also adds boolean casts for encryption_enabled and directory_name_encryption.
     */
    protected function extendS3StorageModel(): void
    {
        if (! class_exists(\App\Models\S3Storage::class)) {
            return;
        }

        $encryptionCasts = [
            'encryption_enabled' => 'boolean',
            'encryption_password' => 'encrypted',
            'encryption_salt' => 'encrypted',
            'directory_name_encryption' => 'boolean',
        ];

        // Add casts when models are retrieved from database
        \App\Models\S3Storage::retrieved(function (\App\Models\S3Storage $storage) use ($encryptionCasts) {
            $storage->mergeCasts($encryptionCasts);
        });

        // Add casts before saving so encrypted cast encrypts the values
        \App\Models\S3Storage::saving(function (\App\Models\S3Storage $storage) use ($encryptionCasts) {
            $storage->mergeCasts($encryptionCasts);

            // Trim encryption password whitespace (same pattern as key/secret)
            if ($storage->encryption_password !== null) {
                $storage->encryption_password = trim($storage->encryption_password);
            }
            if ($storage->encryption_salt !== null) {
                $storage->encryption_salt = trim($storage->encryption_salt);
            }
        });
    }

    /**
     * Register permission-checking macros on the User model.
     *
     * Adds canPerform() to Coolify's User model so policies and Blade
     * templates can call $user->canPerform($action, $resource).
     */
    protected function registerUserMacros(): void
    {
        if (! class_exists(\App\Models\User::class)) {
            return;
        }

        // Only add macro if the User model supports it (uses Macroable trait)
        // and the method doesn't already exist
        $userClass = \App\Models\User::class;

        if (method_exists($userClass, 'macro') && ! method_exists($userClass, 'canPerform')) {
            $userClass::macro('canPerform', function (string $action, $resource): bool {
                return PermissionService::canPerform($this, $action, $resource);
            });
        }
    }

    /**
     * Register Docker registry management: ECR token refresh scheduler
     * and server creation listener for auto-syncing registries.
     */

}

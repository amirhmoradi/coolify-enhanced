<?php

namespace AmirhMoradi\CoolifyEnhanced;

use AmirhMoradi\CoolifyEnhanced\Http\Middleware\InjectPermissionsUI;
use AmirhMoradi\CoolifyEnhanced\Scopes\EnvironmentPermissionScope;
use AmirhMoradi\CoolifyEnhanced\Scopes\ProjectPermissionScope;
use AmirhMoradi\CoolifyEnhanced\Services\PermissionService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class CoolifyEnhancedServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/coolify-enhanced.php', 'coolify-enhanced');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only load if feature is enabled
        if (! config('coolify-enhanced.enabled', false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'coolify-enhanced');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Register Livewire components
        $this->registerLivewireComponents();

        // Register middleware for UI injection into Coolify pages
        // (access matrix on team admin page, backup tabs on resource pages)
        $this->registerMiddleware();

        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/coolify-enhanced.php' => config_path('coolify-enhanced.php'),
        ], 'coolify-enhanced-config');

        // Publish views for customization
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/coolify-enhanced'),
        ], 'coolify-enhanced-views');

        // Register global scopes to filter resources based on permissions
        $this->registerScopes();

        // Register resource backup scheduler
        $this->registerResourceBackupScheduler();

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
            \AmirhMoradi\CoolifyEnhanced\Livewire\AccessMatrix::class
        );

        Livewire::component(
            'enhanced::storage-encryption-form',
            \AmirhMoradi\CoolifyEnhanced\Livewire\StorageEncryptionForm::class
        );

        Livewire::component(
            'enhanced::resource-backup-manager',
            \AmirhMoradi\CoolifyEnhanced\Livewire\ResourceBackupManager::class
        );

        Livewire::component(
            'enhanced::resource-backup-page',
            \AmirhMoradi\CoolifyEnhanced\Livewire\ResourceBackupPage::class
        );

        Livewire::component(
            'enhanced::restore-backup',
            \AmirhMoradi\CoolifyEnhanced\Livewire\RestoreBackup::class
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
                $backups = \AmirhMoradi\CoolifyEnhanced\Models\ScheduledResourceBackup::where('enabled', true)->get();

                foreach ($backups as $backup) {
                    // Use Laravel's CronExpression to check if this backup is due
                    try {
                        $cron = new \Cron\CronExpression($backup->frequency);
                        $timezone = $backup->timezone ?? config('app.timezone', 'UTC');
                        $now = now()->setTimezone($timezone);

                        if ($cron->isDue($now)) {
                            \AmirhMoradi\CoolifyEnhanced\Jobs\ResourceBackupJob::dispatch($backup);
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('ResourceBackup: Invalid cron for backup '.$backup->uuid, [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            })->everyMinute()->name('coolify-enhanced:resource-backups')->withoutOverlapping();
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
            \App\Models\Application::class => \AmirhMoradi\CoolifyEnhanced\Policies\ApplicationPolicy::class,
            \App\Models\Project::class => \AmirhMoradi\CoolifyEnhanced\Policies\ProjectPolicy::class,
            \App\Models\Environment::class => \AmirhMoradi\CoolifyEnhanced\Policies\EnvironmentPolicy::class,
            \App\Models\Server::class => \AmirhMoradi\CoolifyEnhanced\Policies\ServerPolicy::class,
            \App\Models\Service::class => \AmirhMoradi\CoolifyEnhanced\Policies\ServicePolicy::class,

            // Database policies (all types)
            \App\Models\StandalonePostgresql::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMysql::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMariadb::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMongodb::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,
            \App\Models\StandaloneRedis::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,
            \App\Models\StandaloneKeydb::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,
            \App\Models\StandaloneDragonfly::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,
            \App\Models\StandaloneClickhouse::class => \AmirhMoradi\CoolifyEnhanced\Policies\DatabasePolicy::class,

            // Sub-resource policies (Coolify's defaults return true for everything)
            \App\Models\EnvironmentVariable::class => \AmirhMoradi\CoolifyEnhanced\Policies\EnvironmentVariablePolicy::class,
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
}

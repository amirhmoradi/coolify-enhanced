<?php

namespace AmirhMoradi\CoolifyPermissions;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class CoolifyPermissionsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/coolify-permissions.php', 'coolify-permissions');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only load if feature is enabled
        if (! config('coolify-permissions.enabled', false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'coolify-permissions');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Register Livewire components with unique names to avoid conflicts
        $this->registerLivewireComponents();

        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/coolify-permissions.php' => config_path('coolify-permissions.php'),
        ], 'coolify-permissions-config');

        // Publish views for customization
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/coolify-permissions'),
        ], 'coolify-permissions-views');

        // Override policies with permission-aware versions
        $this->registerPolicies();

        // Register model traits dynamically
        $this->extendUserModel();
    }

    /**
     * Register Livewire components.
     */
    protected function registerLivewireComponents(): void
    {
        Livewire::component(
            'coolify-permissions::admin.users',
            \AmirhMoradi\CoolifyPermissions\Livewire\Admin\Users::class
        );

        Livewire::component(
            'coolify-permissions::project.access',
            \AmirhMoradi\CoolifyPermissions\Livewire\Project\Access::class
        );
    }

    /**
     * Override Coolify's default policies with permission-aware versions.
     */
    protected function registerPolicies(): void
    {
        $policies = [
            \App\Models\Application::class => \AmirhMoradi\CoolifyPermissions\Policies\ApplicationPolicy::class,
            \App\Models\Project::class => \AmirhMoradi\CoolifyPermissions\Policies\ProjectPolicy::class,
            \App\Models\Environment::class => \AmirhMoradi\CoolifyPermissions\Policies\EnvironmentPolicy::class,
            \App\Models\Server::class => \AmirhMoradi\CoolifyPermissions\Policies\ServerPolicy::class,
            \App\Models\Service::class => \AmirhMoradi\CoolifyPermissions\Policies\ServicePolicy::class,
            \App\Models\StandalonePostgresql::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMysql::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMariadb::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMongodb::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneRedis::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            if (class_exists($model)) {
                Gate::policy($model, $policy);
            }
        }
    }

    /**
     * Extend the User model with permission traits at runtime.
     */
    protected function extendUserModel(): void
    {
        // Register a macro to check project permissions
        \App\Models\User::macro('hasProjectPermission', function ($project, string $permission): bool {
            return \AmirhMoradi\CoolifyPermissions\Services\PermissionService::hasProjectPermission($this, $project, $permission);
        });

        \App\Models\User::macro('hasEnvironmentPermission', function ($environment, string $permission): bool {
            return \AmirhMoradi\CoolifyPermissions\Services\PermissionService::hasEnvironmentPermission($this, $environment, $permission);
        });

        \App\Models\User::macro('canPerform', function (string $action, $resource): bool {
            return \AmirhMoradi\CoolifyPermissions\Services\PermissionService::canPerform($this, $action, $resource);
        });

        \App\Models\User::macro('getProjectAccess', function ($project) {
            return \AmirhMoradi\CoolifyPermissions\Models\ProjectUser::where('project_id', $project->id)
                ->where('user_id', $this->id)
                ->first();
        });

        \App\Models\User::macro('getEnvironmentAccess', function ($environment) {
            return \AmirhMoradi\CoolifyPermissions\Models\EnvironmentUser::where('environment_id', $environment->id)
                ->where('user_id', $this->id)
                ->first();
        });
    }
}

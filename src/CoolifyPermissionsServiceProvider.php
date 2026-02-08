<?php

namespace AmirhMoradi\CoolifyPermissions;

use AmirhMoradi\CoolifyPermissions\Http\Middleware\InjectPermissionsUI;
use AmirhMoradi\CoolifyPermissions\Scopes\EnvironmentPermissionScope;
use AmirhMoradi\CoolifyPermissions\Scopes\ProjectPermissionScope;
use Illuminate\Contracts\Http\Kernel;
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

        // Load API routes only (web UI is injected via middleware)
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Register Livewire components
        $this->registerLivewireComponents();

        // Register middleware for UI injection into Coolify pages
        $this->registerMiddleware();

        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/coolify-permissions.php' => config_path('coolify-permissions.php'),
        ], 'coolify-permissions-config');

        // Publish views for customization
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/coolify-permissions'),
        ], 'coolify-permissions-views');

        // Register global scopes to filter resources based on permissions
        $this->registerScopes();

        // Override policies with permission-aware versions
        $this->registerPolicies();
    }

    /**
     * Register Livewire components.
     */
    protected function registerLivewireComponents(): void
    {
        Livewire::component(
            'permissions::access-matrix',
            \AmirhMoradi\CoolifyPermissions\Livewire\AccessMatrix::class
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
}

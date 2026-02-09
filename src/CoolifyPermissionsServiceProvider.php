<?php

namespace AmirhMoradi\CoolifyPermissions;

use AmirhMoradi\CoolifyPermissions\Http\Middleware\InjectPermissionsUI;
use AmirhMoradi\CoolifyPermissions\Scopes\EnvironmentPermissionScope;
use AmirhMoradi\CoolifyPermissions\Scopes\ProjectPermissionScope;
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
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
        });
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
     *
     * Coolify's own policies (as of v4) return true for all operations.
     * We override them to enforce granular project/environment permissions.
     */
    protected function registerPolicies(): void
    {
        $policies = [
            // Core resource policies
            \App\Models\Application::class => \AmirhMoradi\CoolifyPermissions\Policies\ApplicationPolicy::class,
            \App\Models\Project::class => \AmirhMoradi\CoolifyPermissions\Policies\ProjectPolicy::class,
            \App\Models\Environment::class => \AmirhMoradi\CoolifyPermissions\Policies\EnvironmentPolicy::class,
            \App\Models\Server::class => \AmirhMoradi\CoolifyPermissions\Policies\ServerPolicy::class,
            \App\Models\Service::class => \AmirhMoradi\CoolifyPermissions\Policies\ServicePolicy::class,

            // Database policies (all types)
            \App\Models\StandalonePostgresql::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMysql::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMariadb::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMongodb::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneRedis::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneKeydb::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneDragonfly::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,
            \App\Models\StandaloneClickhouse::class => \AmirhMoradi\CoolifyPermissions\Policies\DatabasePolicy::class,

            // Sub-resource policies (Coolify's defaults return true for everything)
            \App\Models\EnvironmentVariable::class => \AmirhMoradi\CoolifyPermissions\Policies\EnvironmentVariablePolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            if (class_exists($model)) {
                Gate::policy($model, $policy);
            }
        }
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

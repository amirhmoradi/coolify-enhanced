<?php

namespace AmirhMoradi\CoolifyPermissions\Services;

use AmirhMoradi\CoolifyPermissions\Models\EnvironmentUser;
use AmirhMoradi\CoolifyPermissions\Models\ProjectUser;
use App\Models\User;

class PermissionService
{
    /**
     * Action to permission mapping.
     */
    protected static array $actionMap = [
        // View actions
        'view' => 'view',
        'viewAny' => 'view',

        // Deploy actions
        'deploy' => 'deploy',
        'start' => 'deploy',
        'stop' => 'deploy',
        'restart' => 'deploy',

        // Manage actions
        'update' => 'manage',
        'create' => 'manage',
        'manage' => 'manage',

        // Delete actions
        'delete' => 'delete',
        'forceDelete' => 'delete',
        'restore' => 'delete',
    ];

    /**
     * Check if granular permissions are enabled.
     */
    public static function isEnabled(): bool
    {
        return config('coolify-permissions.enabled', false);
    }

    /**
     * Check if user has a specific permission on a project.
     */
    public static function hasProjectPermission(User $user, $project, string $permission): bool
    {
        // Bypass for privileged roles
        if (static::hasRoleBypass($user)) {
            return true;
        }

        $projectAccess = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $projectAccess) {
            return false;
        }

        return $projectAccess->hasPermission($permission);
    }

    /**
     * Check if user has a specific permission on an environment.
     */
    public static function hasEnvironmentPermission(User $user, $environment, string $permission): bool
    {
        // Bypass for privileged roles
        if (static::hasRoleBypass($user)) {
            return true;
        }

        // Check environment-level override first
        $envAccess = EnvironmentUser::where('environment_id', $environment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($envAccess) {
            return $envAccess->hasPermission($permission);
        }

        // Fall back to project-level permission if cascade is enabled
        if (config('coolify-permissions.cascade_permissions', true)) {
            return static::hasProjectPermission($user, $environment->project, $permission);
        }

        return false;
    }

    /**
     * Check if user can perform an action on a resource.
     */
    public static function canPerform(User $user, string $action, $resource): bool
    {
        $permission = static::mapActionToPermission($action);

        // Determine resource type and check permission
        return match (true) {
            $resource instanceof \App\Models\Application => static::checkApplicationPermission($user, $resource, $permission),
            $resource instanceof \App\Models\Service => static::checkServicePermission($user, $resource, $permission),
            $resource instanceof \App\Models\Project => static::hasProjectPermission($user, $resource, $permission),
            $resource instanceof \App\Models\Environment => static::hasEnvironmentPermission($user, $resource, $permission),
            static::isDatabase($resource) => static::checkDatabasePermission($user, $resource, $permission),
            default => static::hasRoleBypass($user),
        };
    }

    /**
     * Check permission for an application.
     */
    protected static function checkApplicationPermission(User $user, $application, string $permission): bool
    {
        $environment = $application->environment;
        if (! $environment) {
            return static::hasRoleBypass($user);
        }

        return static::hasEnvironmentPermission($user, $environment, $permission);
    }

    /**
     * Check permission for a service.
     */
    protected static function checkServicePermission(User $user, $service, string $permission): bool
    {
        $environment = $service->environment;
        if (! $environment) {
            return static::hasRoleBypass($user);
        }

        return static::hasEnvironmentPermission($user, $environment, $permission);
    }

    /**
     * Check permission for a database.
     */
    protected static function checkDatabasePermission(User $user, $database, string $permission): bool
    {
        $environment = $database->environment;
        if (! $environment) {
            return static::hasRoleBypass($user);
        }

        return static::hasEnvironmentPermission($user, $environment, $permission);
    }

    /**
     * Check if resource is a database model.
     */
    protected static function isDatabase($resource): bool
    {
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

        foreach ($databaseModels as $model) {
            if ($resource instanceof $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has a role that bypasses permission checks.
     */
    public static function hasRoleBypass(User $user): bool
    {
        $bypassRoles = config('coolify-permissions.bypass_roles', ['owner', 'admin']);
        $userRole = $user->teams()->where('teams.id', currentTeam()?->id)->first()?->pivot?->role;

        return in_array($userRole, $bypassRoles);
    }

    /**
     * Map an action to its corresponding permission.
     */
    protected static function mapActionToPermission(string $action): string
    {
        return static::$actionMap[$action] ?? 'view';
    }

    /**
     * Grant project access to a user.
     */
    public static function grantProjectAccess(User $user, $project, string $level = 'view_only'): ProjectUser
    {
        return ProjectUser::updateOrCreate(
            [
                'project_id' => $project->id,
                'user_id' => $user->id,
            ],
            [
                'permissions' => ProjectUser::getPermissionsForLevel($level),
            ]
        );
    }

    /**
     * Revoke project access from a user.
     */
    public static function revokeProjectAccess(User $user, $project): bool
    {
        return ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->delete() > 0;
    }

    /**
     * Grant environment access to a user (override).
     */
    public static function grantEnvironmentAccess(User $user, $environment, string $level = 'view_only'): EnvironmentUser
    {
        return EnvironmentUser::updateOrCreate(
            [
                'environment_id' => $environment->id,
                'user_id' => $user->id,
            ],
            [
                'permissions' => EnvironmentUser::getPermissionsForLevel($level),
            ]
        );
    }

    /**
     * Revoke environment access from a user.
     */
    public static function revokeEnvironmentAccess(User $user, $environment): bool
    {
        return EnvironmentUser::where('environment_id', $environment->id)
            ->where('user_id', $user->id)
            ->delete() > 0;
    }
}

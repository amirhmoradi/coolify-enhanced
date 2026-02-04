<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Granular Permissions
    |--------------------------------------------------------------------------
    |
    | When enabled, the granular permission system will be active, requiring
    | explicit project access for members and viewers. When disabled, all
    | team members have access to all projects (default Coolify behavior).
    |
    */
    'enabled' => env('COOLIFY_GRANULAR_PERMISSIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Permission Levels
    |--------------------------------------------------------------------------
    |
    | Define the available permission levels and their capabilities.
    | These can be customized but the keys should remain unchanged.
    |
    */
    'levels' => [
        'view_only' => [
            'view' => true,
            'deploy' => false,
            'manage' => false,
            'delete' => false,
        ],
        'deploy' => [
            'view' => true,
            'deploy' => true,
            'manage' => false,
            'delete' => false,
        ],
        'full_access' => [
            'view' => true,
            'deploy' => true,
            'manage' => true,
            'delete' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Bypass
    |--------------------------------------------------------------------------
    |
    | Roles that bypass granular permission checks entirely.
    | These users have full access to all resources in their team.
    |
    */
    'bypass_roles' => ['owner', 'admin'],

    /*
    |--------------------------------------------------------------------------
    | Permission Cascade
    |--------------------------------------------------------------------------
    |
    | When enabled, project permissions cascade to all environments within.
    | Environment-level overrides can still be set for fine-tuning.
    |
    */
    'cascade_permissions' => true,

    /*
    |--------------------------------------------------------------------------
    | Auto-Grant Access
    |--------------------------------------------------------------------------
    |
    | When a new project is created, automatically grant access to these roles.
    | Set to empty array to require explicit access grants for all users.
    |
    */
    'auto_grant_roles' => ['owner', 'admin'],

    /*
    |--------------------------------------------------------------------------
    | Default Permission Level
    |--------------------------------------------------------------------------
    |
    | The default permission level when granting access without specifying one.
    |
    */
    'default_level' => 'view_only',
];

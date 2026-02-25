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
    'enabled' => env('COOLIFY_ENHANCED', env('COOLIFY_GRANULAR_PERMISSIONS', false)),

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

    /*
    |--------------------------------------------------------------------------
    | Backup Encryption
    |--------------------------------------------------------------------------
    |
    | Configuration for the S3 backup encryption feature.
    | Uses rclone's crypt backend (NaCl SecretBox) for at-rest encryption.
    |
    */
    'backup_encryption' => [
        // The rclone Docker image used for encrypted backup operations
        'rclone_image' => env('COOLIFY_RCLONE_IMAGE', 'rclone/rclone:latest'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Network Management
    |--------------------------------------------------------------------------
    |
    | Configuration for Docker network isolation and management.
    | Provides per-environment network isolation, shared networks,
    | dedicated proxy networks, and server-level network management.
    |
    */
    'network_management' => [
        // Enable network management feature
        'enabled' => env('COOLIFY_NETWORK_MANAGEMENT', false),

        // Isolation mode: 'none', 'environment', 'strict'
        // - none: no auto-provisioning, manual network management only
        // - environment: auto-create per-environment networks, resources auto-join
        // - strict: same as environment + disconnect from default coolify network
        'isolation_mode' => env('COOLIFY_NETWORK_ISOLATION', 'environment'),

        // Whether to use a dedicated proxy network (opt-in)
        // When enabled, only resources with FQDNs join the proxy network
        'proxy_isolation' => env('COOLIFY_PROXY_ISOLATION', false),

        // Maximum managed networks per server (safety limit)
        'max_networks_per_server' => (int) env('COOLIFY_MAX_NETWORKS', 200),

        // Network name prefix (avoid collisions with Coolify's naming)
        'prefix' => env('COOLIFY_NETWORK_PREFIX', 'ce'),

        // Delay before post-deployment network assignment (seconds)
        'post_deploy_delay' => (int) env('COOLIFY_NETWORK_POST_DEPLOY_DELAY', 3),

        // Enable inter-node encryption for Swarm overlay networks
        // Uses Docker's --opt encrypted flag (IPsec between Swarm nodes)
        // Only applies to Swarm servers; ignored for standalone Docker
        'swarm_overlay_encryption' => env('COOLIFY_SWARM_OVERLAY_ENCRYPTION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Template Sources
    |--------------------------------------------------------------------------
    |
    | Configuration for custom GitHub template sources.
    | Allows adding external repositories with docker-compose templates
    | that appear in the one-click service list.
    |
    */
    'custom_templates' => [
        // Auto-sync interval (cron expression). Set to null to disable auto-sync.
        'sync_frequency' => env('COOLIFY_TEMPLATE_SYNC_FREQUENCY', '0 */6 * * *'),

        // Cache directory for fetched templates
        // Follows Coolify's pattern: host /data/coolify/custom-templates is mounted
        // to container /var/www/html/storage/app/custom-templates via docker-compose.custom.yml
        'cache_dir' => env('COOLIFY_TEMPLATE_CACHE_DIR', storage_path('app/custom-templates')),

        // Maximum templates per source (safety limit)
        'max_templates_per_source' => 500,

        // GitHub API timeout in seconds
        'github_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cluster Management
    |--------------------------------------------------------------------------
    |
    | Configuration for Docker Swarm cluster management and monitoring.
    | Provides cluster dashboard, node management, service monitoring,
    | and cluster visualization.
    |
    */
    'cluster_management' => env('COOLIFY_CLUSTER_MANAGEMENT', false),
    'cluster_sync_interval' => (int) env('COOLIFY_CLUSTER_SYNC_INTERVAL', 60),
    'cluster_cache_ttl' => (int) env('COOLIFY_CLUSTER_CACHE_TTL', 30),
    'cluster_event_retention_days' => (int) env('COOLIFY_CLUSTER_EVENT_RETENTION', 7),

    /*
    |--------------------------------------------------------------------------
    | Enhanced UI Theme
    |--------------------------------------------------------------------------
    |
    | Optional corporate-grade modern UI theme (CSS + minimal JS only).
    | When enabled in Settings > Appearance, applies a refined color palette
    | and light/dark modes. Disabled by default; runtime value from DB.
    |
    */
    'ui_theme' => [
        'enabled' => env('COOLIFY_ENHANCED_UI_THEME', false),
    ],
];

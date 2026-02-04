# Architecture

This document describes the architecture of the Coolify Granular Permissions package.

## Overview

The package extends Coolify's authorization system without modifying core files. It uses Laravel's service provider system to:

1. Override default policies with permission-aware versions
2. Add new database tables for permission storage
3. Provide UI components for permission management
4. Expose API endpoints for programmatic access

## System Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Coolify Application                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │   Livewire   │    │  Controllers │    │     API      │      │
│  │  Components  │    │              │    │  Endpoints   │      │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘      │
│         │                   │                   │               │
│         └───────────────────┼───────────────────┘               │
│                             │                                    │
│                             ▼                                    │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                    Laravel Gate                          │   │
│  │                 (Policy Resolution)                      │   │
│  └─────────────────────────┬───────────────────────────────┘   │
│                             │                                    │
├─────────────────────────────┼────────────────────────────────────┤
│                             │                                    │
│  ┌─────────────────────────▼───────────────────────────────┐   │
│  │              Granular Permissions Package                │   │
│  │  ┌────────────────────────────────────────────────────┐ │   │
│  │  │              Policy Overrides                       │ │   │
│  │  │  (ApplicationPolicy, ProjectPolicy, etc.)           │ │   │
│  │  └────────────────────────┬───────────────────────────┘ │   │
│  │                           │                              │   │
│  │  ┌────────────────────────▼───────────────────────────┐ │   │
│  │  │              PermissionService                      │ │   │
│  │  │  - isEnabled()                                      │ │   │
│  │  │  - hasProjectPermission()                           │ │   │
│  │  │  - hasEnvironmentPermission()                       │ │   │
│  │  │  - canPerform()                                     │ │   │
│  │  │  - hasRoleBypass()                                  │ │   │
│  │  └────────────────────────┬───────────────────────────┘ │   │
│  │                           │                              │   │
│  │  ┌────────────────────────▼───────────────────────────┐ │   │
│  │  │              Database Tables                        │ │   │
│  │  │  - project_user (project access)                    │ │   │
│  │  │  - environment_user (environment overrides)         │ │   │
│  │  └────────────────────────────────────────────────────┘ │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Permission Flow

### Authorization Check Flow

```
Request → Gate::allows('view', $application)
              │
              ▼
        ApplicationPolicy::view()
              │
              ▼
        Feature enabled? ─────────────────┐
              │ yes                        │ no
              ▼                            ▼
        PermissionService::canPerform()   return true
              │
              ▼
        User has role bypass? ────────────┐
              │ no                         │ yes
              ▼                            ▼
        Get resource's project            return true
              │
              ▼
        hasProjectPermission()
              │
              ├── ProjectUser exists? ────┐
              │ yes                        │ no
              ▼                            ▼
        Check permission flag         return false
              │
              ├── has permission? ────────┐
              │ yes                        │ no
              ▼                            ▼
        return true                   return false
```

### Environment Permission Cascade

Environment permissions cascade from project permissions, with optional overrides:

```
Check environment permission
        │
        ▼
  EnvironmentUser exists for this user/environment?
        │
        ├── yes ──► Use EnvironmentUser permissions
        │
        └── no ──► Fall back to ProjectUser permissions
```

## Database Schema

### project_user Table

```sql
CREATE TABLE project_user (
    id BIGINT PRIMARY KEY,
    project_id BIGINT REFERENCES projects(id) ON DELETE CASCADE,
    user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
    can_view BOOLEAN DEFAULT true,
    can_deploy BOOLEAN DEFAULT false,
    can_manage BOOLEAN DEFAULT false,
    can_delete BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(project_id, user_id)
);
```

### environment_user Table

```sql
CREATE TABLE environment_user (
    id BIGINT PRIMARY KEY,
    environment_id BIGINT REFERENCES environments(id) ON DELETE CASCADE,
    user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
    can_view BOOLEAN DEFAULT true,
    can_deploy BOOLEAN DEFAULT false,
    can_manage BOOLEAN DEFAULT false,
    can_delete BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(environment_id, user_id)
);
```

### User Table Extensions

```sql
ALTER TABLE users ADD COLUMN is_global_admin BOOLEAN DEFAULT false;
ALTER TABLE users ADD COLUMN status VARCHAR(255) DEFAULT 'active';
```

## Component Architecture

### Service Provider Lifecycle

```
Application Boot
      │
      ▼
CoolifyPermissionsServiceProvider::register()
      │
      ├── Merge configuration
      ├── Register PermissionService singleton
      │
      ▼
CoolifyPermissionsServiceProvider::boot()
      │
      ├── Load migrations
      ├── Load views with namespace
      ├── Load routes (web & api)
      ├── Register Livewire components
      └── Override policies via Gate::policy()
```

### Policy Override Mechanism

The package uses Laravel's `Gate::policy()` to override Coolify's default policies:

```php
// In CoolifyPermissionsServiceProvider::bootPolicies()
Gate::policy(Application::class, ApplicationPolicy::class);
Gate::policy(Project::class, ProjectPolicy::class);
// ... etc
```

This ensures that whenever Coolify checks authorization, our permission-aware policies are used instead.

## Caching Strategy

### Team Role Caching

The package leverages Coolify's existing team role caching via `currentTeam()`:

```php
$team = $user->currentTeam();
$role = $user->teams->where('id', $team->id)->first()?->pivot->role;
```

### Permission Caching (Future Enhancement)

For high-traffic installations, permission lookups can be cached:

```php
// Potential future implementation
$cacheKey = "user:{$user->id}:project:{$project->id}:permissions";
$permissions = Cache::remember($cacheKey, 300, function () use ($user, $project) {
    return ProjectUser::where('project_id', $project->id)
        ->where('user_id', $user->id)
        ->first();
});
```

## Docker Integration

### Image Build Process

```
Official Coolify Image
        │
        ▼
   COPY package files
        │
        ▼
   Configure composer repository
        │
        ▼
   composer require package
        │
        ▼
   Setup s6-overlay service
        │
        ▼
   Custom Coolify Image with Granular Permissions
```

### S6-Overlay Service

The package includes an s6-overlay service that runs migrations on container startup:

```
Container Start
      │
      ▼
S6-Overlay Init
      │
      ├── Start PHP-FPM
      ├── Start Nginx
      └── Start addon-migration service
              │
              ▼
         Run package migrations
              │
              ▼
         Service completes (oneshot)
```

## Security Considerations

### Defense in Depth

1. **Policy Layer**: All authorization goes through Laravel policies
2. **Service Layer**: PermissionService provides centralized logic
3. **Database Layer**: Foreign key constraints ensure data integrity
4. **UI Layer**: Form components check authorization before rendering

### Role Bypass Security

Owner and Admin roles bypass all permission checks. This is intentional:

- Owners need full control to manage their team
- Admins are trusted team administrators
- Restricting them would break expected Coolify behavior

### API Security

API endpoints are protected by:

1. Bearer token authentication (Sanctum)
2. Team membership verification
3. Policy-based authorization

## Extensibility Points

### Adding New Permission Types

1. Add column to migration
2. Update ProjectUser/EnvironmentUser models
3. Update PermissionService methods
4. Update policies to check new permission
5. Update UI components

### Adding New Resource Types

1. Create new policy extending base pattern
2. Register policy in service provider
3. Add resource-specific logic to PermissionService
4. Update tests

### Custom Permission Logic

Override PermissionService in your own service provider:

```php
$this->app->singleton(PermissionService::class, function ($app) {
    return new CustomPermissionService();
});
```

## Performance Considerations

### Query Optimization

- Indexes on `project_user(project_id, user_id)`
- Indexes on `environment_user(environment_id, user_id)`
- Single query per permission check

### Scaling

For large installations:

1. Enable query caching for permissions
2. Use read replicas for permission lookups
3. Consider denormalizing permissions to resource tables

## Future Considerations

### Coolify v5 Migration

When Coolify v5 releases with built-in permission management:

1. Export permission data from package tables
2. Map to v5's permission structure
3. Import into v5's native system
4. Remove package

A migration script will be provided when v5's permission API is finalized.

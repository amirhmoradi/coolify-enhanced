# AGENTS.md

Detailed instructions for AI assistants working with the Coolify Granular Permissions package.

## Package Context

This is a **Laravel package** that extends Coolify v4 with granular user role and project-level access management. It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system.

### Key Characteristics

1. **Addon, not core modification** - All code lives in a separate package
2. **Feature-flagged** - Controlled by `COOLIFY_GRANULAR_PERMISSIONS=true`
3. **Backward compatible** - When disabled, Coolify behaves normally
4. **Docker-deployed** - Installed via custom Docker image extending official Coolify

## Architecture

### Permission Hierarchy

```
Team Role (owner/admin) → Bypasses all checks
         ↓
  Project Access → Defined in project_user table
         ↓
Environment Override → Optional overrides in environment_user table
```

### Permission Levels

| Level | View | Deploy | Manage | Delete |
|-------|------|--------|--------|--------|
| `view_only` | ✓ | ✗ | ✗ | ✗ |
| `deploy` | ✓ | ✓ | ✗ | ✗ |
| `full_access` | ✓ | ✓ | ✓ | ✓ |

### Role Bypass Rules

- **Owner**: Full access to everything (bypasses all permission checks)
- **Admin**: Full access to everything (bypasses all permission checks)
- **Member**: Requires explicit project access when feature is enabled
- **Viewer**: Read-only access when feature is enabled, requires project access

## Code Organization

### Service Provider (`CoolifyPermissionsServiceProvider.php`)

The main entry point that:
- Loads package configuration, migrations, views, and routes
- Registers Livewire components with namespaced prefixes
- Overrides Coolify's default policies with permission-aware versions
- Extends User model with permission-checking macros

**Key methods:**
- `register()` - Merges config, registers PermissionService singleton
- `boot()` - Loads all package resources, registers policies
- `bootPolicies()` - Overrides Gate policies for all resource types

### Permission Service (`Services/PermissionService.php`)

Central permission checking logic. All permission decisions flow through here.

**Key methods:**
```php
// Check if feature is enabled
isEnabled(): bool

// Check project-level permission
hasProjectPermission(User $user, Project $project, string $permission): bool

// Check environment-level permission (with cascade from project)
hasEnvironmentPermission(User $user, Environment $environment, string $permission): bool

// Main entry point for all permission checks
canPerform(User $user, $resource, string $permission): bool

// Check if user has owner/admin bypass
hasRoleBypass(User $user, Team $team): bool
```

### Models

**ProjectUser** (`Models/ProjectUser.php`)
- Pivot model for project access
- Stores permission flags (can_view, can_deploy, can_manage, can_delete)
- Helper methods: `getPermissionsForLevel()`, `getPermissionLevel()`

**EnvironmentUser** (`Models/EnvironmentUser.php`)
- Optional environment-level permission overrides
- Same permission flags as ProjectUser
- When present, overrides project-level permissions for that environment

### Policies

All policies follow the same pattern:

```php
public function view(User $user, Model $resource): bool
{
    if (!$this->permissionService->isEnabled()) {
        return true; // Feature disabled, allow
    }
    return $this->permissionService->canPerform($user, $resource, 'view');
}
```

**Implemented policies:**
- `ApplicationPolicy` - Controls application resources
- `ProjectPolicy` - Controls project resources
- `EnvironmentPolicy` - Controls environment resources
- `ServerPolicy` - Controls server resources
- `ServicePolicy` - Controls service resources
- `DatabasePolicy` - Controls database resources

## Development Guidelines

### Adding New Permission Checks

1. **Add method to PermissionService:**
```php
public function canDoNewThing(User $user, Model $resource): bool
{
    // Implement permission logic
}
```

2. **Add policy method:**
```php
public function newThing(User $user, Model $resource): bool
{
    if (!$this->permissionService->isEnabled()) {
        return true;
    }
    return $this->permissionService->canDoNewThing($user, $resource);
}
```

3. **Use in controllers/components:**
```php
$this->authorize('newThing', $resource);
// or
Gate::allows('newThing', $resource);
```

### Adding New Resource Types

1. Create policy in `src/Policies/`
2. Register in service provider's `bootPolicies()` method
3. Add permission checking logic to PermissionService
4. Update documentation

### Modifying Permission Levels

Permission levels are defined in `ProjectUser::getPermissionsForLevel()`:

```php
public static function getPermissionsForLevel(string $level): array
{
    return match ($level) {
        'full_access' => self::FULL_ACCESS_PERMISSIONS,
        'deploy' => self::DEPLOY_PERMISSIONS,
        'view_only' => self::VIEW_ONLY_PERMISSIONS,
        default => self::VIEW_ONLY_PERMISSIONS,
    };
}
```

To add a new level:
1. Add constant: `public const NEW_LEVEL_PERMISSIONS = [...]`
2. Add case to `getPermissionsForLevel()` match
3. Add case to `getPermissionLevel()` match
4. Update UI components to include new option
5. Update API validation rules
6. Update documentation

## API Development

### Controller Pattern

Follow Coolify's API conventions:

```php
public function store(Request $request): JsonResponse
{
    $allowedFields = ['project_uuid', 'user_id', 'permission_level'];

    $validator = Validator::make(
        $request->all(),
        [
            'project_uuid' => 'required|string',
            'user_id' => 'required|integer',
            'permission_level' => 'required|in:view_only,deploy,full_access',
        ]
    );

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    // Implementation...
}
```

### OpenAPI Documentation

All API endpoints must have OpenAPI annotations:

```php
/**
 * @OA\Post(
 *     path="/api/v1/permissions/project",
 *     summary="Grant project access",
 *     tags={"Permissions"},
 *     security={{"bearerAuth": {}}},
 *     @OA\RequestBody(...),
 *     @OA\Response(...)
 * )
 */
```

## Livewire Components

### Component Structure

```php
namespace AmirhMoradi\CoolifyPermissions\Livewire\Project;

use Livewire\Component;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Access extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('update', $this->project);
        // Load data...
    }

    public function render(): View
    {
        return view('coolify-permissions::livewire.project.access');
    }
}
```

### View Requirements

- **Single root element** - Livewire requires exactly one root element
- **Use Coolify's form components** - `<x-forms.input>`, `<x-forms.select>`, etc.
- **Authorization in UI** - Use `canGate` and `canResource` attributes

## Testing

### Running Tests

Tests must be run inside the Docker container:

```bash
# Enter container
docker exec -it coolify bash

# Run package tests
./vendor/bin/pest packages/coolify-granular-permissions/tests
```

### Test Structure

```
tests/
├── Unit/
│   ├── PermissionServiceTest.php
│   └── ProjectUserTest.php
└── Feature/
    ├── ProjectAccessTest.php
    └── ApiPermissionsTest.php
```

### Mocking in Tests

```php
use Mockery;

// Mock the permission service
$permissionService = Mockery::mock(PermissionService::class);
$permissionService->shouldReceive('isEnabled')->andReturn(true);
$permissionService->shouldReceive('canPerform')->andReturn(true);

$this->app->instance(PermissionService::class, $permissionService);
```

## Docker Build

### Build Process

The Dockerfile:
1. Starts FROM official Coolify image
2. Copies package files to `/tmp/coolify-granular-permissions`
3. Configures composer to use local path repository
4. Installs package via composer
5. Runs composer dump-autoload
6. Sets up s6-overlay for migrations

### Migration Service

The s6-overlay service (`addon-migration`) runs migrations on container startup:

```bash
#!/bin/bash
cd /var/www/html
php artisan migrate --force --path=vendor/amirhmoradi/coolify-granular-permissions/database/migrations
```

## Common Tasks

### Grant user access to project

```php
use AmirhMoradi\CoolifyPermissions\Models\ProjectUser;

ProjectUser::create([
    'project_id' => $project->id,
    'user_id' => $user->id,
    'can_view' => true,
    'can_deploy' => true,
    'can_manage' => false,
    'can_delete' => false,
]);
```

### Check permission programmatically

```php
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;

$service = app(PermissionService::class);

if ($service->canPerform($user, $application, 'deploy')) {
    // Allow deployment
}
```

### Override environment permissions

```php
use AmirhMoradi\CoolifyPermissions\Models\EnvironmentUser;

EnvironmentUser::create([
    'environment_id' => $environment->id,
    'user_id' => $user->id,
    'can_view' => true,
    'can_deploy' => false, // Override: no deploy on this env
    'can_manage' => false,
    'can_delete' => false,
]);
```

## Troubleshooting

### Permissions not being enforced

1. Check feature flag: `config('coolify-permissions.enabled')` should be `true`
2. Verify environment variable: `COOLIFY_GRANULAR_PERMISSIONS=true`
3. Check user's team role (owner/admin bypasses all checks)

### Migrations not running

1. Check s6 service logs: `cat /var/log/s6-rc/addon-migration/current`
2. Run manually: `php artisan migrate --path=vendor/amirhmoradi/coolify-granular-permissions/database/migrations`

### Livewire components not loading

1. Clear view cache: `php artisan view:clear`
2. Check component registration in service provider
3. Verify namespaced component name: `<livewire:coolify-permissions::project.access />`

## Version Compatibility

| Package Version | Coolify Version | PHP Version |
|-----------------|-----------------|-------------|
| 1.x | v4.x | 8.2+ |

**Note:** Coolify v5 may include similar built-in features. A migration guide will be provided when v5 is released.

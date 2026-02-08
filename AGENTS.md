# AGENTS.md

Detailed instructions for AI assistants working with the Coolify Granular Permissions package.

## Package Context

This is a **Laravel package** that extends Coolify v4 with granular user role and project-level access management. It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system.

### Key Characteristics

1. **Addon, not core modification** - All code lives in a separate package
2. **Feature-flagged** - Controlled by `COOLIFY_GRANULAR_PERMISSIONS=true`
3. **Backward compatible** - When disabled, Coolify behaves normally
4. **Docker-deployed** - Installed via custom Docker image extending official Coolify
5. **UI injection** - Access Matrix is injected into Coolify's `/team/admin` page via middleware

## Architecture

### Permission Hierarchy

```
Team Role (owner/admin) → Bypasses all checks
         ↓
  Project Access → Defined in project_user table
         ↓
Environment Override → Optional overrides in environment_user table (inherits from project by default)
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
- Loads package configuration, migrations, and views
- Loads API routes (web routes removed in favor of middleware injection)
- Registers the `AccessMatrix` Livewire component
- Registers `InjectPermissionsUI` middleware for UI injection
- Overrides Coolify's default policies with permission-aware versions
- Extends User model with permission-checking macros

**Key methods:**
- `register()` - Merges config
- `boot()` - Loads all package resources, registers policies
- `registerLivewireComponents()` - Registers `permissions::access-matrix` component
- `registerMiddleware()` - Pushes `InjectPermissionsUI` as global middleware
- `registerPolicies()` - Overrides Gate policies for all resource types

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
hasRoleBypass(User $user): bool
```

### Models

**ProjectUser** (`Models/ProjectUser.php`)
- Pivot model for project access
- Stores permission flags as JSON: `{"view":bool,"deploy":bool,"manage":bool,"delete":bool}`
- Helper methods: `getPermissionsForLevel()`, `getPermissionLevel()`

**EnvironmentUser** (`Models/EnvironmentUser.php`)
- Optional environment-level permission overrides
- Same permission structure as ProjectUser
- When present, overrides project-level permissions for that environment
- When absent, project-level permissions cascade down

### Middleware

**InjectPermissionsUI** (`Http/Middleware/InjectPermissionsUI.php`)
- Global HTTP middleware that intercepts responses
- On `/team` and `/team/*` pages, injects the AccessMatrix Livewire component
- Only injects for authenticated users with admin/owner roles
- Uses `Blade::render()` to server-side render the Livewire component
- Includes a positioning script to place the UI within the existing page structure

### Livewire Components

**AccessMatrix** (`Livewire/AccessMatrix.php`)
- Unified permission management component
- Displays a matrix of users × (projects + environments)
- Each cell is a permission level dropdown
- Supports bulk operations: All/None per row and per column
- Environment cells show "inherited" when using project-level cascade
- Users with owner/admin role show "bypass" (non-editable)
- Search/filter for users

### Policies

All policies follow the same pattern:

```php
public function view(User $user, Model $resource): bool
{
    if (!PermissionService::isEnabled()) {
        return true; // Feature disabled, allow
    }
    return PermissionService::canPerform($user, 'view', $resource);
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
public static function canDoNewThing(User $user, $resource): bool
{
    // Implement permission logic
}
```

2. **Add policy method:**
```php
public function newThing(User $user, Model $resource): bool
{
    if (!PermissionService::isEnabled()) {
        return true;
    }
    return PermissionService::canDoNewThing($user, $resource);
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
2. Register in service provider's `registerPolicies()` method
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
4. Update AccessMatrix component to include new option
5. Update API validation rules
6. Update documentation

## API Development

### Controller Pattern

Follow Coolify's API conventions:

```php
public function store(Request $request): JsonResponse
{
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

## Installation & Deployment

### Setup Script (`install.sh`)

Menu-driven setup script that also supports CLI arguments for automation:

**Interactive mode** (no args): Displays a menu with options:
1. Install Coolify from official repository (fresh server setup)
2. Install Granular Permissions addon (requires Coolify)
3. Uninstall Granular Permissions addon (delegates to `uninstall.sh`)
4. Check installation status
5. Full setup (Coolify + addon in sequence)

**CLI mode** (with args):
- `--install-coolify` - Downloads and runs the official Coolify installer
- `--install-addon` - Installs the permissions addon (GHCR or `--local`)
- `--uninstall` - Uninstalls the addon
- `--status` - Shows system/Coolify/addon status
- `--local` - Build image locally instead of pulling from GHCR
- `--unattended` - Non-interactive, accepts all defaults
- Args can be combined: `--install-coolify --install-addon --unattended`

The addon install action:
1. Checks prerequisites (root, Docker, Docker Compose)
2. Detects Coolify installation at `/data/coolify/source/`
3. Supports both GHCR image pull and local build
4. Creates `docker-compose.custom.yml` (Coolify natively supports this file)
5. Sets `COOLIFY_GRANULAR_PERMISSIONS=true` in `.env`
6. Restarts Coolify via `upgrade.sh`
7. Verifies installation

### Uninstall Script (`uninstall.sh`)

Automated uninstaller that:
1. Optionally cleans database tables (prompted, or `--clean-db`/`--keep-db` flags)
2. Removes `docker-compose.custom.yml` (with backup)
3. Removes environment variable from `.env`
4. Restarts Coolify via `upgrade.sh`

### Docker Build

The Dockerfile:
1. Starts FROM official Coolify image
2. Copies package files to `/tmp/coolify-granular-permissions`
3. Configures composer to use local path repository
4. Installs package via composer
5. Runs composer dump-autoload
6. Sets up s6-overlay for migrations

## Common Tasks

### Grant user access to project

```php
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;

PermissionService::grantProjectAccess($user, $project, 'deploy');
```

### Check permission programmatically

```php
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;

if (PermissionService::canPerform($user, 'deploy', $application)) {
    // Allow deployment
}
```

### Override environment permissions

```php
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;

PermissionService::grantEnvironmentAccess($user, $environment, 'view_only');
```

## Troubleshooting

### Permissions not being enforced

1. Check feature flag: `config('coolify-permissions.enabled')` should be `true`
2. Verify environment variable: `COOLIFY_GRANULAR_PERMISSIONS=true`
3. Check user's team role (owner/admin bypasses all checks)

### Migrations not running

1. Check s6 service logs: `cat /var/log/s6-rc/addon-migration/current`
2. Run manually: `php artisan migrate --path=vendor/amirhmoradi/coolify-granular-permissions/database/migrations`

### Access Matrix not showing

1. Verify user has admin/owner role (only admins/owners see the matrix)
2. Clear view cache: `php artisan view:clear`
3. Check container logs for middleware errors
4. Verify you are on the `/team/admin` or `/team` page

## Version Compatibility

| Package Version | Coolify Version | PHP Version |
|-----------------|-----------------|-------------|
| 1.x | v4.x | 8.2+ |

**Note:** Coolify v5 may include similar built-in features. A migration guide will be provided when v5 is released.

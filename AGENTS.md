# AGENTS.md

Detailed instructions for AI assistants working with the Coolify Granular Permissions package.

## Mandatory Rules

1. **Keep docs updated** - After every significant code change, update CLAUDE.md and AGENTS.md with new learnings, patterns, and pitfalls.
2. **Pull Coolify source** - At the start of each session, run `git -C docs/coolify-source pull` to update the Coolify reference source. If missing, clone it: `git clone --depth 1 https://github.com/coollabsio/coolify.git docs/coolify-source`.
3. **Reference Coolify source** - When working on policies, authorization, or UI integration, browse `docs/coolify-source/` to understand Coolify's native implementation.
4. **Read before writing** - Always read existing files before modifying them.

## Package Context

This is a **Laravel package** that extends Coolify v4 with granular user role and project-level access management. It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system.

### Key Characteristics

1. **Addon, not core modification** - All code lives in a separate package
2. **Feature-flagged** - Controlled by `COOLIFY_GRANULAR_PERMISSIONS=true`
3. **Backward compatible** - When disabled, Coolify behaves normally
4. **Docker-deployed** - Installed via custom Docker image extending official Coolify
5. **UI injection** - Access Matrix is injected into Coolify's `/team/admin` page via middleware

## Critical Architecture Knowledge

### Service Provider Boot Order (CRITICAL)

Laravel boots **package providers BEFORE application providers**. This means:

1. Our `CoolifyPermissionsServiceProvider::boot()` runs FIRST
2. Coolify's `AuthServiceProvider::boot()` runs AFTER us
3. Coolify's `$policies` property calls `Gate::policy()` internally, **overwriting** our policies

**Solution:** Defer policy registration using `$this->app->booted()`:

```php
// In CoolifyPermissionsServiceProvider::boot()
$this->app->booted(function () {
    $this->registerPolicies();    // Runs AFTER Coolify's AuthServiceProvider
    $this->registerUserMacros();
});
```

**Never** register policies directly in `boot()`. Always use `$this->app->booted()`.

### Coolify's Authorization Mechanisms

Coolify uses three ways to check authorization. Our policies must support all of them:

1. **Blade component attributes**: `canGate="update" :canResource="$application"`
   - Internally calls `Gate::allows('update', $application)`
   - Requires our policy to have an `update()` method

2. **Blade `@can` directives**: `@can('manageEnvironment', $resource)`
   - Used to gate environment variable management UI
   - Requires our policy to have a `manageEnvironment()` method

3. **Livewire `$this->authorize()`**: `$this->authorize('update', $resource)`
   - Server-side checks in Livewire component methods
   - Same policy methods as Gate::allows()

Reference: `docs/coolify-source/app/Providers/AuthServiceProvider.php` for full policy list.

### Coolify's Native Policies

Coolify's own policies (v4) return `true` for ALL operations — authorization is effectively disabled. There is no `Gate::before()` callback. Our package overrides these policies with permission-aware versions.

### Permission Resolution Flow

```
1. Check role bypass (owner/admin → return true immediately)
2. Check environment-level override (environment_user table)
   - If record exists → use its permissions
   - If no record → fall through to project level
3. Check project-level permission (project_user table)
   - If record exists → use its permissions
   - If no record → deny
4. Deny (no access)
```

**Key insight:** Environment-level overrides are checked FIRST and take precedence. When no environment override exists, the project-level permission cascades down.

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
| `view_only` | yes | no | no | no |
| `deploy` | yes | yes | no | no |
| `full_access` | yes | yes | yes | yes |

### Role Bypass Rules

- **Owner**: Full access to everything (bypasses all permission checks)
- **Admin**: Full access to everything (bypasses all permission checks)
- **Member**: Requires explicit project access when feature is enabled
- **Viewer**: Read-only access when feature is enabled, requires project access

### Coolify URL Patterns

These patterns are used by `resolveProjectFromRequest()` and `resolveEnvironmentFromRequest()`:

- Project page: `/project/{uuid}`
- Environment: `/project/{uuid}/{env_name}`
- New resource: `/project/{uuid}/{env_name}/new`
- Application: `/project/{uuid}/{env_name}/application/{app_uuid}`

## Code Organization

### Service Provider (`CoolifyPermissionsServiceProvider.php`)

The main entry point that:
- Loads package configuration, migrations, and views
- Loads API routes (web routes removed in favor of middleware injection)
- Registers the `AccessMatrix` Livewire component
- Registers `InjectPermissionsUI` middleware for UI injection
- Registers Eloquent global scopes for filtering projects/environments
- **Defers** policy registration and User macros to `$this->app->booted()` callback

**Key methods:**
- `register()` - Merges config
- `boot()` - Loads all package resources, sets up booted callback
- `registerLivewireComponents()` - Registers `permissions::access-matrix` component
- `registerMiddleware()` - Pushes `InjectPermissionsUI` as global middleware
- `registerScopes()` - Adds ProjectPermissionScope and EnvironmentPermissionScope
- `registerPolicies()` - Overrides Gate policies for all resource types (called via booted callback)
- `registerUserMacros()` - Adds `canPerform()` macro to User model (called via booted callback)

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
canPerform(User $user, string $action, $resource): bool

// Check if user has owner/admin bypass
hasRoleBypass(User $user): bool

// Resolve project from current request URL
resolveProjectFromRequest(): ?Project

// Resolve environment from current request URL
resolveEnvironmentFromRequest(): ?Environment

// Check create permission based on URL context (env first, then project)
canCreateInCurrentContext(User $user): bool

// Check permission via polymorphic parent (for sub-resources like EnvironmentVariable)
checkResourceablePermission(User $user, $resourceable, string $permission): bool
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
- Extends `Pivot` (defaults `$timestamps=false`); migration creates timestamp columns (harmless)

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
- Displays a matrix of users x (projects + environments)
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
    if (! PermissionService::isEnabled()) {
        return true; // Feature disabled, allow
    }
    return PermissionService::canPerform($user, 'view', $resource);
}

public function create(User $user): bool
{
    if (! PermissionService::isEnabled()) {
        return true;
    }
    return PermissionService::canCreateInCurrentContext($user);
}

public function manageEnvironment(User $user, Model $resource): bool
{
    if (! PermissionService::isEnabled()) {
        return true;
    }
    return PermissionService::canPerform($user, 'manage', $resource);
}
```

**Implemented policies:**
- `ApplicationPolicy` - Controls application resources
- `ProjectPolicy` - Controls project resources
- `EnvironmentPolicy` - Controls environment resources
- `ServerPolicy` - Controls server resources (view always returns true for team members)
- `ServicePolicy` - Controls service resources
- `DatabasePolicy` - Controls all database types (Postgresql, Mysql, Mariadb, Mongodb, Redis, Keydb, Dragonfly, Clickhouse)
- `EnvironmentVariablePolicy` - Controls env vars via polymorphic parent traversal

**Registered policy mappings (in registerPolicies()):**
```
Application → ApplicationPolicy
Project → ProjectPolicy
Environment → EnvironmentPolicy
Server → ServerPolicy
Service → ServicePolicy
StandalonePostgresql → DatabasePolicy
StandaloneMysql → DatabasePolicy
StandaloneMariadb → DatabasePolicy
StandaloneMongodb → DatabasePolicy
StandaloneRedis → DatabasePolicy
StandaloneKeydb → DatabasePolicy
StandaloneDragonfly → DatabasePolicy
StandaloneClickhouse → DatabasePolicy
EnvironmentVariable → EnvironmentVariablePolicy
```

### Scopes

**ProjectPermissionScope** - Filters projects based on user permissions
**EnvironmentPermissionScope** - Filters environments based on user permissions

### Sub-resource Policy Pattern

For resources like `EnvironmentVariable` that don't directly belong to a project/environment, use the polymorphic parent traversal:

```php
protected function checkViaParent(User $user, EnvironmentVariable $envVar, string $permission): bool
{
    $parent = $envVar->resourceable;  // Application, Service, or Database
    return PermissionService::checkResourceablePermission($user, $parent, $permission);
}
```

`checkResourceablePermission()` resolves the parent's environment and checks permissions accordingly.

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
    if (! PermissionService::isEnabled()) {
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
2. Register in service provider's `registerPolicies()` method (inside the `$this->app->booted()` callback)
3. Add permission checking logic to PermissionService
4. Update documentation (CLAUDE.md and AGENTS.md)

### Adding New Sub-resource Types

For resources with polymorphic parents:

1. Create policy using `checkViaParent()` pattern (see `EnvironmentVariablePolicy`)
2. Register in `registerPolicies()`
3. Add `checkResourceablePermission()` logic if parent type isn't already handled
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

## Common Pitfalls & Lessons Learned

1. **Boot order kills policies** — Our policies registered in `boot()` were silently overwritten by Coolify's `AuthServiceProvider`. Always use `$this->app->booted()`.
2. **`create()` receives no model** — Laravel's `create()` policy method only gets the User. Must resolve project/environment from request URL.
3. **Sub-resources need explicit overrides** — Coolify's EnvironmentVariable policy returns `true` for everything. We must register our own override.
4. **All database types must be registered** — Easy to forget StandaloneKeydb, StandaloneDragonfly, StandaloneClickhouse when listing database policies.
5. **Use static PermissionService methods in policies** — Don't call `$user->canPerform()` macro in policies. The macro may not be registered yet. Use `PermissionService::canPerform()` directly.
6. **Environment overrides checked first** — `hasEnvironmentPermission()` checks environment_user FIRST, then falls back to project_user. This is intentional: a user with full_access at project level can be restricted to view_only on a specific environment.
7. **`EnvironmentVariable` uses polymorphic `resourceable()`** — morphTo relationship to parent Application/Service/Database. Must traverse to find the environment.
8. **No database changes needed for permission fixes** — The `project_user` and `environment_user` tables store permissions correctly. Issues are always in the code logic, not the data.
9. **Coolify has no `Gate::before()` callback** — Don't assume one exists. All authorization goes through policies.
10. **`Pivot` model defaults** — `EnvironmentUser` extends `Pivot` which sets `$timestamps = false` by default, but migration creates timestamp columns. This is harmless.

## Coolify Source Reference

The Coolify source code is cloned at `docs/coolify-source/` (gitignored). Key reference files:

| File | What to check |
|------|---------------|
| `app/Providers/AuthServiceProvider.php` | All registered policies and gates |
| `app/Policies/` | Default policy implementations (all return true) |
| `resources/views/` | Blade templates using `canGate`, `@can` directives |
| `app/Livewire/` | Components using `$this->authorize()` |
| `app/Models/` | Model relationships and structure |

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
4. Verify policies are registered: check `Gate::getPolicyFor(Application::class)` returns our policy class
5. Check boot order: ensure `$this->app->booted()` is used for policy registration

### Environment override not working

1. Verify `environment_user` record exists for the user+environment
2. Check that `hasEnvironmentPermission()` is called (not just `hasProjectPermission()`)
3. Ensure `canCreateInCurrentContext()` checks environment level first

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

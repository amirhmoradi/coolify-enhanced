# CLAUDE.md

This file provides guidance to **Claude Code** and other AI assistants when working with this codebase.

> **For detailed AI agent instructions, see [AGENTS.md](AGENTS.md)**

## Mandatory Rules for AI Agents

1. **Keep documentation updated** - After every significant code change, update CLAUDE.md and AGENTS.md with new learnings, patterns, and pitfalls discovered during implementation.
2. **Pull Coolify source on each prompt** - At the start of each session, run `git -C docs/coolify-source pull` to ensure the Coolify reference source is up to date. If the directory doesn't exist, clone it: `git clone --depth 1 https://github.com/coollabsio/coolify.git docs/coolify-source`.
3. **Browse Coolify source for context** - When working on policies, authorization, or UI integration, always reference the Coolify source under `docs/coolify-source/` to understand how Coolify implements things natively.
4. **Read before writing** - Always read existing files before modifying them. Understand the current state before making changes.

## Project Overview

This is a Laravel package that extends Coolify v4 with granular user role and project-level access management. It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system.

## Critical Architecture Knowledge

### Service Provider Boot Order (CRITICAL)

Laravel boots **package providers BEFORE application providers**. Coolify's `AuthServiceProvider` (an app provider) registers its own policies via its `$policies` property, which calls `Gate::policy()` internally. If we register our policies during our `boot()` method, Coolify's `AuthServiceProvider` boots afterwards and **overwrites our policies** with its permissive defaults (all return `true`).

**Solution:** We defer policy registration to `$this->app->booted()` callback, which executes AFTER all providers have booted. This ensures our `Gate::policy()` calls get the last word.

```php
// In CoolifyPermissionsServiceProvider::boot()
$this->app->booted(function () {
    $this->registerPolicies();
    $this->registerUserMacros();
});
```

### Coolify's Authorization Patterns

Coolify uses three authorization mechanisms that our policies must support:

1. **`canGate`/`:canResource` Blade component attributes** - e.g., `canGate="update" :canResource="$application"` — internally calls `Gate::allows()`
2. **`@can('manageEnvironment', $resource)` Blade directives** - Gates environment variable management UI elements
3. **`$this->authorize()` in Livewire components** - Server-side checks in component methods

Our policies must implement methods matching all three patterns (e.g., `view`, `update`, `delete`, `manageEnvironment`).

### Coolify's Native Policies

Coolify's own policies (v4) return `true` for ALL operations — authorization is effectively disabled. There is no `Gate::before()` callback. See `docs/coolify-source/app/Providers/AuthServiceProvider.php` for the full list of Coolify's registered policies.

### Permission Resolution Order

```
1. Role bypass check (owner/admin → allow everything)
2. Environment-level override (if exists in environment_user table)
3. Project-level fallback (from project_user table)
4. Deny (no access record found)
```

Environment-level overrides take precedence over project-level permissions. When no environment override exists, the project-level permission cascades down.

### Policy `create()` Limitation

Laravel's `create()` policy method does **not** receive a model instance — only the User. To determine context, we resolve the project/environment from the current request URL:

- `resolveProjectFromRequest()` — extracts project from URL pattern `/project/{uuid}/...`
- `resolveEnvironmentFromRequest()` — extracts environment from URL pattern `/project/{uuid}/{env_name}/...`
- `canCreateInCurrentContext()` — checks environment-level first (most specific), then project-level

### Sub-resource Policies

Resources like `EnvironmentVariable` use polymorphic relationships (`resourceable()` morphTo) to their parent (Application/Service/Database). Our `EnvironmentVariablePolicy` traverses this relationship to find the environment and check permissions via `PermissionService::checkResourceablePermission()`.

## Quick Reference

### Package Structure

```
coolify-granular-permissions/
├── src/
│   ├── CoolifyPermissionsServiceProvider.php  # Main service provider
│   ├── Services/
│   │   └── PermissionService.php              # Core permission logic
│   ├── Models/
│   │   ├── ProjectUser.php                    # Project access pivot
│   │   └── EnvironmentUser.php                # Environment override pivot
│   ├── Policies/                              # Laravel policies (override Coolify's)
│   │   ├── ApplicationPolicy.php
│   │   ├── DatabasePolicy.php
│   │   ├── EnvironmentPolicy.php
│   │   ├── EnvironmentVariablePolicy.php
│   │   ├── ProjectPolicy.php
│   │   ├── ServerPolicy.php
│   │   └── ServicePolicy.php
│   ├── Scopes/                                # Eloquent global scopes
│   │   ├── ProjectPermissionScope.php
│   │   └── EnvironmentPermissionScope.php
│   ├── Http/
│   │   ├── Controllers/Api/                   # API controllers
│   │   └── Middleware/
│   │       └── InjectPermissionsUI.php        # UI injection middleware
│   └── Livewire/
│       └── AccessMatrix.php                   # Access matrix component
├── database/migrations/                        # Database migrations
├── resources/views/livewire/
│   └── access-matrix.blade.php                # Matrix table view
├── routes/                                     # API routes
├── config/                                     # Package configuration
├── docker/                                     # Docker build files
├── docs/
│   └── coolify-source/                        # Cloned Coolify source (gitignored)
├── install.sh                                  # Automated installer
└── uninstall.sh                                # Automated uninstaller
```

### Key Files

| File | Purpose |
|------|---------|
| `src/CoolifyPermissionsServiceProvider.php` | Main service provider, policy registration |
| `src/Services/PermissionService.php` | All permission checking logic |
| `src/Policies/EnvironmentVariablePolicy.php` | Sub-resource policy via polymorphic parent |
| `src/Livewire/AccessMatrix.php` | Unified access management UI |
| `src/Http/Middleware/InjectPermissionsUI.php` | Injects UI into Coolify pages |
| `src/Models/ProjectUser.php` | Permission levels and helpers |
| `config/coolify-permissions.php` | Configuration options |
| `docs/coolify-source/` | Coolify source reference (gitignored) |
| `docker/Dockerfile` | Custom Coolify image build |
| `docker/docker-compose.custom.yml` | Compose override template |
| `install.sh` | Setup script (menu + CLI args) |
| `uninstall.sh` | Standalone uninstall script |

### Development Commands

```bash
# No local development - this is deployed via Docker
# Build custom image
docker build --build-arg COOLIFY_VERSION=latest -t coolify-custom:latest -f docker/Dockerfile .

# Setup menu (interactive)
sudo bash install.sh

# Install Coolify on a fresh server
sudo bash install.sh --install-coolify

# Install the permissions addon
sudo bash install.sh --install-addon

# Full setup (Coolify + addon) non-interactive
sudo bash install.sh --install-coolify --install-addon --unattended

# Check installation status
sudo bash install.sh --status

# Uninstall addon (via menu or standalone)
sudo bash install.sh --uninstall
sudo bash uninstall.sh

# Update Coolify reference source
git -C docs/coolify-source pull
```

### Permission Levels

- `view_only`: Can view resources only
- `deploy`: Can view and deploy
- `full_access`: Can view, deploy, manage, and delete

### Role Bypass

Owners and Admins bypass all permission checks. Only Members and Viewers need explicit project access.

### Coolify URL Patterns

- Project page: `/project/{uuid}`
- Environment: `/project/{uuid}/{env_name}`
- New resource: `/project/{uuid}/{env_name}/new`
- Application: `/project/{uuid}/{env_name}/application/{app_uuid}`

### UI Injection

The Access Matrix UI is injected into Coolify's `/team/admin` page via the `InjectPermissionsUI` middleware. No standalone web routes exist — the UI is always part of the existing Coolify page.

## Common Pitfalls

1. **Boot order** — Never register policies directly in `boot()`. Always use `$this->app->booted()`.
2. **`create()` has no model** — Must resolve context from request URL, not from a model instance.
3. **Sub-resources need explicit policies** — Coolify's defaults return `true`; we must override them.
4. **All database types must be registered** — StandaloneKeydb, StandaloneDragonfly, StandaloneClickhouse are easy to miss.
5. **Use `PermissionService::canPerform()` directly** — Don't rely on `$user->canPerform()` macro in policies; use the static method instead.
6. **Environment overrides are checked first** — `hasEnvironmentPermission()` checks environment_user table first, falls back to project_user.
7. **`EnvironmentVariable` uses `resourceable()`** — Polymorphic morphTo relationship to parent Application/Service/Database.

## Important Notes

1. **This is an addon** - It doesn't modify Coolify core files
2. **Feature flag** - Set `COOLIFY_GRANULAR_PERMISSIONS=true` to enable
3. **docker-compose.custom.yml** - Coolify natively supports this file for overrides
4. **v5 compatibility** - Coolify v5 may include similar features; migration guide will be provided
5. **Backward compatible** - When disabled, behaves like standard Coolify

## See Also

- [AGENTS.md](AGENTS.md) - Detailed AI agent instructions
- [docs/coolify-source/](docs/coolify-source/) - Coolify source code reference
- [docs/architecture.md](docs/architecture.md) - Architecture details
- [docs/api.md](docs/api.md) - API documentation
- [docs/installation.md](docs/installation.md) - Installation guide

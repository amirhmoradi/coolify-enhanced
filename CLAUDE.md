# CLAUDE.md

This file provides guidance to **Claude Code** and other AI assistants when working with this codebase.

> **For detailed AI agent instructions, see [AGENTS.md](AGENTS.md)**

## Mandatory Rules for AI Agents

1. **Keep documentation updated** - After every significant code change, update CLAUDE.md and AGENTS.md with new learnings, patterns, and pitfalls discovered during implementation.
2. **Pull Coolify source on each prompt** - At the start of each session, run `git -C docs/coolify-source pull` to ensure the Coolify reference source is up to date. If the directory doesn't exist, clone it: `git clone --depth 1 https://github.com/coollabsio/coolify.git docs/coolify-source`.
3. **Browse Coolify source for context** - When working on policies, authorization, or UI integration, always reference the Coolify source under `docs/coolify-source/` to understand how Coolify implements things natively.
4. **Read before writing** - Always read existing files before modifying them. Understand the current state before making changes.

## Project Overview

This is a Laravel package that extends Coolify v4 with three main features:

1. **Granular Permissions** — Project-level and environment-level access management with role-based overrides
2. **Encrypted S3 Backups** — Transparent encryption at rest for all backups using rclone's crypt backend (NaCl SecretBox: XSalsa20 + Poly1305)
3. **Resource Backups** — Volume, configuration, and full backups for Applications, Services, and Databases (beyond Coolify's database-only backup)

It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system. For encryption and backup features, modified Coolify files are overlaid in the Docker image.

## Critical Architecture Knowledge

### Service Provider Boot Order (CRITICAL)

Laravel boots **package providers BEFORE application providers**. Coolify's `AuthServiceProvider` (an app provider) registers its own policies via its `$policies` property, which calls `Gate::policy()` internally. If we register our policies during our `boot()` method, Coolify's `AuthServiceProvider` boots afterwards and **overwrites our policies** with its permissive defaults (all return `true`).

**Solution:** We defer policy registration to `$this->app->booted()` callback, which executes AFTER all providers have booted. This ensures our `Gate::policy()` calls get the last word.

```php
// In CoolifyEnhancedServiceProvider::boot()
$this->app->booted(function () {
    $this->registerPolicies();
    $this->registerUserMacros();
    $this->extendS3StorageModel();
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

### Encrypted S3 Backups Architecture

The encryption feature uses rclone's crypt backend (NaCl SecretBox) to transparently encrypt database backups before uploading to S3. Key design decisions:

- **File overlay approach**: Modified versions of Coolify files (`DatabaseBackupJob.php`, `Import.php`, `databases.php`) are copied over the originals in the Docker image
- **Environment-variable rclone config**: No config file needed — uses `RCLONE_CONFIG_<REMOTE>_<OPTION>` pattern
- **Password obscuring**: Implements rclone's obscure algorithm in PHP (AES-256-CTR with well-known fixed key)
- **Env file for Docker**: Base64-encoded env file written to server, passed via `--env-file` to rclone container
- **Backward compatible**: Tracks `is_encrypted` per backup execution; uses mc for unencrypted, rclone for encrypted
- **Filename encryption**: Default `off`, optional `standard`/`obfuscate` modes; when enabled, all S3 ops go through rclone
- **S3 path prefix**: Optional per-storage path prefix for multi-instance separation (from Coolify PR #7776)

### Resource Backups Architecture

Extends Coolify's database-only backups to support Docker volumes, configuration, and full backups for any resource:

- **Separate model/table**: `ScheduledResourceBackup` and `ScheduledResourceBackupExecution` — parallel to Coolify's `ScheduledDatabaseBackup`
- **Backup types**: `volume` (tar.gz Docker volumes), `configuration` (JSON export of settings, env vars, compose), `full` (both), `coolify_instance` (full `/data/coolify` installation minus backups)
- **Volume backup approach**: Uses `docker inspect` to discover mounts, then `tar czf` via helper Alpine container for named volumes or direct tar for bind mounts
- **Configuration export**: Serializes resource model, environment variables, persistent storages, docker-compose, custom labels to JSON
- **Same S3 infrastructure**: Reuses `RcloneService` for encrypted uploads; uses mc for unencrypted uploads (same pattern as database backups)
- **Encryption support**: All resource backup types support the same per-S3-storage encryption as database backups
- **Independent scheduling**: Each resource can have its own backup schedule via cron expressions
- **Retention policies**: Same local/S3 retention by amount, days, or storage as database backups
- **Backup directory structure**: `/data/coolify/backups/resources/{team-slug}-{team-id}/{resource-name}-{uuid}/`

## Quick Reference

### Package Structure

```
coolify-enhanced/
├── src/
│   ├── CoolifyEnhancedServiceProvider.php     # Main service provider
│   ├── Services/
│   │   ├── PermissionService.php              # Core permission logic
│   │   └── RcloneService.php                  # Rclone encryption commands
│   ├── Models/
│   │   ├── ProjectUser.php                    # Project access pivot
│   │   ├── EnvironmentUser.php                # Environment override pivot
│   │   ├── ScheduledResourceBackup.php        # Resource backup schedule model
│   │   └── ScheduledResourceBackupExecution.php # Resource backup execution model
│   ├── Traits/
│   │   └── HasS3Encryption.php                # S3 encryption helpers for model
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
│   ├── Overrides/                             # Modified Coolify files (overlay)
│   │   ├── Jobs/
│   │   │   └── DatabaseBackupJob.php          # Encryption-aware backup job
│   │   ├── Livewire/Project/Database/
│   │   │   └── Import.php                     # Encryption-aware restore
│   │   ├── Views/livewire/storage/
│   │   │   └── show.blade.php                 # Storage page with encryption form
│   │   └── Helpers/
│   │       └── databases.php                  # Encryption-aware S3 delete
│   ├── Jobs/
│   │   └── ResourceBackupJob.php              # Volume/config/full backup job
│   ├── Http/
│   │   ├── Controllers/Api/                   # API controllers
│   │   │   ├── PermissionsController.php      # Permission management API
│   │   │   └── ResourceBackupController.php   # Resource backup API
│   │   └── Middleware/
│   │       └── InjectPermissionsUI.php        # UI injection middleware
│   └── Livewire/
│       ├── AccessMatrix.php                   # Access matrix component
│       ├── StorageEncryptionForm.php          # S3 path prefix + encryption settings
│       └── ResourceBackupManager.php          # Resource backup management UI
├── database/migrations/                        # Database migrations
├── resources/views/livewire/
│   ├── access-matrix.blade.php                # Matrix table view
│   ├── storage-encryption-form.blade.php      # Path prefix + encryption form view
│   └── resource-backup-manager.blade.php      # Resource backup management view
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
| `src/CoolifyEnhancedServiceProvider.php` | Main service provider, policy registration |
| `src/Services/PermissionService.php` | All permission checking logic |
| `src/Services/RcloneService.php` | Rclone Docker commands for encrypted S3 ops |
| `src/Traits/HasS3Encryption.php` | Encryption helpers for S3Storage model |
| `src/Policies/EnvironmentVariablePolicy.php` | Sub-resource policy via polymorphic parent |
| `src/Livewire/AccessMatrix.php` | Unified access management UI |
| `src/Livewire/StorageEncryptionForm.php` | S3 path prefix + encryption settings UI |
| `src/Livewire/ResourceBackupManager.php` | Resource backup scheduling and management UI |
| `src/Jobs/ResourceBackupJob.php` | Volume/config/full backup job |
| `src/Models/ScheduledResourceBackup.php` | Resource backup schedule model |
| `src/Models/ScheduledResourceBackupExecution.php` | Resource backup execution tracking |
| `src/Http/Controllers/Api/ResourceBackupController.php` | Resource backup REST API |
| `src/Overrides/Jobs/DatabaseBackupJob.php` | Encryption + path prefix aware backup job overlay |
| `src/Overrides/Livewire/Project/Database/Import.php` | Encryption-aware restore overlay |
| `src/Overrides/Helpers/databases.php` | Encryption-aware S3 delete overlay |
| `src/Overrides/Views/livewire/storage/show.blade.php` | Storage page with encryption form |
| `src/Http/Middleware/InjectPermissionsUI.php` | Injects access matrix into team admin page |
| `src/Models/ProjectUser.php` | Permission levels and helpers |
| `config/coolify-enhanced.php` | Configuration options |
| `docs/coolify-source/` | Coolify source reference (gitignored) |
| `docker/Dockerfile` | Custom Coolify image build |
| `docker/docker-compose.custom.yml` | Compose override template |
| `install.sh` | Setup script (menu + CLI args) |
| `uninstall.sh` | Standalone uninstall script |

### Development Commands

```bash
# No local development - this is deployed via Docker
# Build custom image
docker build --build-arg COOLIFY_VERSION=latest -t coolify-enhanced:latest -f docker/Dockerfile .

# Setup menu (interactive)
sudo bash install.sh

# Install Coolify on a fresh server
sudo bash install.sh --install-coolify

# Install the enhanced addon
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

### UI Integration

Two approaches are used to add UI components to Coolify pages:

- **Access Matrix** — injected via middleware into `/team/admin` page (for admin/owner users). Uses `Blade::render()` + JavaScript DOM positioning.
- **Storage Encryption Form** — added via view overlay (`src/Overrides/Views/livewire/storage/show.blade.php`). The overlay adds `@livewire('enhanced::storage-encryption-form')` directly in the Blade template, ensuring proper Livewire hydration.

**Why two approaches?** The access matrix is read-only (no interactive Livewire bindings needed), so middleware injection works fine. The encryption form has interactive toggles and form inputs that require proper Livewire hydration — middleware injection + DOM moves break Alpine.js/Livewire bindings. The view overlay renders the component natively in the page lifecycle.

## Common Pitfalls

1. **Boot order** — Never register policies directly in `boot()`. Always use `$this->app->booted()`.
2. **`create()` has no model** — Must resolve context from request URL, not from a model instance.
3. **Sub-resources need explicit policies** — Coolify's defaults return `true`; we must override them.
4. **All database types must be registered** — StandaloneKeydb, StandaloneDragonfly, StandaloneClickhouse are easy to miss.
5. **Use `PermissionService::canPerform()` directly** — Don't rely on `$user->canPerform()` macro in policies; use the static method instead.
6. **Environment overrides are checked first** — `hasEnvironmentPermission()` checks environment_user table first, falls back to project_user.
7. **`EnvironmentVariable` uses `resourceable()`** — Polymorphic morphTo relationship to parent Application/Service/Database.
8. **Rclone password obscuring** — Uses AES-256-CTR with a well-known fixed key from rclone source. The PHP implementation must match exactly (base64url encoding, no padding).
9. **Env file cleanup** — Always clean up the base64-encoded env file and rclone container after operations to avoid credential leaks.
10. **Filename encryption and S3 operations** — When `filename_encryption != 'off'`, S3 filenames are encrypted; must use rclone (not Laravel Storage) for listing/deleting files.
11. **Middleware injection breaks Livewire interactivity** — Components rendered via `Blade::render()` in middleware and moved via JavaScript `appendChild()` lose Livewire/Alpine.js bindings. Use view overlays for interactive components (toggles, forms, buttons).
12. **Use Coolify's native form components** — Custom Tailwind CSS classes (e.g., `peer-checked:bg-blue-600`, `after:content-['']`) are NOT compiled into Coolify's CSS bundle. Always use `<x-forms.checkbox>`, `<x-forms.input>`, `<x-forms.select>`, `<x-forms.button>` instead of custom HTML. For reactive checkbox toggles, use `instantSave="methodName"`.
13. **Adding casts to S3Storage model** — Can't apply traits dynamically. Use `S3Storage::retrieved()` and `S3Storage::saving()` events with `$model->mergeCasts()` to add `encrypted`/`boolean` casts for new columns.
14. **S3 path prefix must be applied everywhere** — When `$s3->path` is set, it must be prepended in uploads (mc and rclone), deletes (S3 driver and rclone), restores (mc stat/cp and rclone download), and file existence checks.
15. **Volume backup uses helper Alpine container** — For Docker named volumes, use `docker run --rm -v volume:/source:ro alpine tar czf` rather than attempting to access `/var/lib/docker/volumes` directly.
16. **Resource backup scheduling** — Uses `$app->booted()` to register a scheduler callback that checks cron expressions every minute via `CronExpression::isDue()`.
17. **Resource backup directory layout** — Uses `/data/coolify/backups/resources/` (not `/databases/`) to avoid conflicts with Coolify's native database backup paths.
18. **Coolify instance backup excludes backups/** — `backupCoolifyInstance()` uses `--exclude=./backups --exclude=./metrics` to prevent backup-of-backups duplication.
19. **Feature flag safety** — `ResourceBackupJob::handle()` checks `config('coolify-enhanced.enabled')` at runtime so queued jobs exit silently if the feature is disabled. API controller also guards in constructor.

## Important Notes

1. **This is an addon** - It extends Coolify via overlay files and service provider
2. **Feature flag** - Set `COOLIFY_ENHANCED=true` to enable (backward compat: `COOLIFY_GRANULAR_PERMISSIONS=true` also works)
3. **docker-compose.custom.yml** - Coolify natively supports this file for overrides
4. **v5 compatibility** - Coolify v5 may include similar features; migration guide will be provided
5. **Backward compatible** - When disabled, behaves like standard Coolify
6. **Encryption is per-storage** - Each S3 storage destination can independently enable encryption
7. **S3 path prefix** - Configurable per-storage path prefix for multi-instance bucket sharing
8. **Resource backups** - Volume, configuration, and full backups via `enhanced::resource-backup-manager` component

## See Also

- [AGENTS.md](AGENTS.md) - Detailed AI agent instructions
- [docs/coolify-source/](docs/coolify-source/) - Coolify source code reference
- [docs/architecture.md](docs/architecture.md) - Architecture details
- [docs/api.md](docs/api.md) - API documentation
- [docs/installation.md](docs/installation.md) - Installation guide

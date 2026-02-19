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
4. **Custom Template Sources** — Add external GitHub repositories as sources for docker-compose service templates, extending Coolify's one-click service list
5. **Enhanced Database Classification** — Expanded database image detection list and `coolify.database` label/`# type: database` comment convention for explicit service classification

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
- **Restore/Import**: Settings page with JSON backup viewer, env var bulk import into existing resources, step-by-step restoration guide
- **Backup directory structure**: `/data/coolify/backups/resources/{team-slug}-{team-id}/{resource-name}-{uuid}/`

### Custom Template Sources Architecture

Extends Coolify's built-in service template system to support external GitHub repositories as additional template sources:

- **Single integration point**: Overrides `get_service_templates()` in `bootstrap/helpers/shared.php` to merge custom templates alongside built-in ones
- **GitHub API fetch**: Uses Contents API (falls back to Trees API for large dirs) to discover and download YAML template files
- **Same format as Coolify**: Templates use identical YAML format with `# key: value` metadata headers — parsed using Coolify's own `Generate/Services.php` logic
- **Cached to disk**: Fetched templates are cached as JSON at `/data/coolify/custom-templates/{source-uuid}/templates.json`
- **Name collision handling**: Built-in templates take precedence; custom templates with same name get a `-{source-slug}` suffix
- **Revert safe**: After deployment, services store `docker_compose_raw` in DB — removing a template source has zero impact on running services
- **Auth support**: Optional GitHub PAT (encrypted in DB) for private repositories
- **Auto-sync**: Configurable cron schedule (default: every 6 hours) for automatic template updates
- **Settings UI**: "Templates" tab in Settings with source management, sync controls, and template preview

### Enhanced Database Classification Architecture

Coolify classifies service containers as `ServiceDatabase` or `ServiceApplication` based on the `isDatabaseImage()` function in `bootstrap/helpers/docker.php`, which checks against a hardcoded `DATABASE_DOCKER_IMAGES` constant. Many database images (memgraph, milvus, qdrant, cassandra, etc.) are missing from this list. This feature solves the classification problem through three complementary mechanisms:

- **Expanded `DATABASE_DOCKER_IMAGES`**: Overlay of `constants.php` adds ~50 additional database images covering graph, vector, time-series, document, search, column-family, NewSQL, and OLAP databases
- **`coolify.database` Docker label**: The `isDatabaseImageEnhanced()` wrapper in `shared.php` checks for a `coolify.database=true|false` label in service config before falling back to `isDatabaseImage()`. Works in both template YAML and arbitrary docker-compose files
- **`# type: database` comment convention**: Template authors can add `# type: database` (or `# type: application`) as a metadata header. During parsing, this injects `coolify.database` labels into all services in the compose YAML (unless a service already has the label explicitly)
- **Per-service granularity**: The `coolify.database` label is per-container, so multi-service templates can have mixed classifications (e.g., memgraph=database + memgraph-lab=application)
- **No docker.php overlay needed**: The wrapper approach in `shared.php` covers the two critical call sites (service import and deployment) without overlaying the 1483-line `docker.php` file. The `is_migrated` flag preserves the initial classification for re-parses
- **StartDatabaseProxy overlay**: Expanded port mapping for ~50 database types in `StartDatabaseProxy.php`. Falls back to extracting port from compose config for truly unknown types. Fixes "Unsupported database type" error when toggling "Make Publicly Available"
- **DatabaseBackupJob overlay**: Replaces silent skips and generic exceptions with meaningful error messages for unsupported database types, guiding users to set `custom_type` or use Resource Backups
- **ServiceDatabase model overlay**: Maps wire-compatible databases to their parent backup type in `databaseType()`: YugabyteDB→postgresql, TiDB→mysql, FerretDB→mongodb, Percona→mysql, Apache AGE→postgresql. This automatically enables backup UI, dump-based backups, import UI, and correct port mapping for these databases
- **parsers.php NOT overlaid**: The 2484-line parsers.php is not overlaid. Its `isDatabaseImage()` calls don't use our label check, but existing ServiceDatabase records are preserved during re-parse (code checks for existing records before creating new ones). The expanded DATABASE_DOCKER_IMAGES covers most cases at the `isDatabaseImage()` level anyway

## Quick Reference

### Package Structure

```
coolify-enhanced/
├── src/
│   ├── CoolifyEnhancedServiceProvider.php     # Main service provider
│   ├── Services/
│   │   ├── PermissionService.php              # Core permission logic
│   │   ├── RcloneService.php                  # Rclone encryption commands
│   │   └── TemplateSourceService.php          # GitHub template fetch & parse
│   ├── Models/
│   │   ├── ProjectUser.php                    # Project access pivot
│   │   ├── EnvironmentUser.php                # Environment override pivot
│   │   ├── ScheduledResourceBackup.php        # Resource backup schedule model
│   │   ├── ScheduledResourceBackupExecution.php # Resource backup execution model
│   │   └── CustomTemplateSource.php           # Custom GitHub template source
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
│   │   ├── Actions/Database/
│   │   │   └── StartDatabaseProxy.php         # Expanded database port mapping
│   │   ├── Models/
│   │   │   └── ServiceDatabase.php            # Wire-compatible type mappings
│   │   ├── Jobs/
│   │   │   └── DatabaseBackupJob.php          # Encryption + classification-aware backup
│   │   ├── Livewire/Project/Database/
│   │   │   └── Import.php                     # Encryption-aware restore
│   │   ├── Views/
│   │   │   ├── livewire/storage/
│   │   │   │   └── show.blade.php             # Storage page with encryption form
│   │   │   ├── livewire/settings-backup.blade.php   # Settings backup with instance file backup
│   │   │   ├── livewire/project/application/
│   │   │   │   └── configuration.blade.php    # App config + Resource Backups sidebar
│   │   │   ├── livewire/project/database/
│   │   │   │   └── configuration.blade.php    # DB config + Resource Backups sidebar
│   │   │   ├── livewire/project/service/
│   │   │   │   └── configuration.blade.php    # Service config + Resource Backups sidebar
│   │   │   ├── livewire/project/new/
│   │   │   │   └── select.blade.php         # New Resource page + custom source labels
│   │   │   ├── components/settings/
│   │   │   │   └── navbar.blade.php          # Settings navbar + Restore tab
│   │   │   └── components/server/
│   │   │       └── sidebar.blade.php          # Server sidebar + Resource Backups item
│   │   └── Helpers/
│   │       ├── constants.php                   # Expanded DATABASE_DOCKER_IMAGES
│   │       ├── databases.php                  # Encryption-aware S3 delete
│   │       └── shared.php                     # Custom templates in get_service_templates()
│   ├── Jobs/
│   │   ├── ResourceBackupJob.php              # Volume/config/full backup job
│   │   └── SyncTemplateSourceJob.php          # Background GitHub template sync
│   ├── Http/
│   │   ├── Controllers/Api/                   # API controllers
│   │   │   ├── CustomTemplateSourceController.php # Template source management API
│   │   │   ├── PermissionsController.php      # Permission management API
│   │   │   └── ResourceBackupController.php   # Resource backup API
│   │   └── Middleware/
│   │       └── InjectPermissionsUI.php        # UI injection middleware
│   └── Livewire/
│       ├── AccessMatrix.php                   # Access matrix component
│       ├── StorageEncryptionForm.php          # S3 path prefix + encryption settings
│       ├── ResourceBackupManager.php          # Resource backup management UI
│       ├── ResourceBackupPage.php             # Server backup page component
│       ├── RestoreBackup.php                  # Settings restore/import page
│       └── CustomTemplateSources.php          # Custom template sources management
├── database/migrations/                        # Database migrations
├── resources/views/livewire/
│   ├── access-matrix.blade.php                # Matrix table view
│   ├── storage-encryption-form.blade.php      # Path prefix + encryption form view
│   ├── resource-backup-manager.blade.php      # Resource backup management view
│   ├── resource-backup-page.blade.php         # Full-page backup view
│   ├── restore-backup.blade.php              # Restore/import backup view
│   └── custom-template-sources.blade.php     # Template sources management view
├── routes/                                     # API and web routes
├── config/                                     # Package configuration
├── docker/                                     # Docker build files
├── docs/
│   ├── custom-templates.md                    # Custom template creation guide
│   ├── examples/
│   │   └── whoami.yaml                        # Example custom template
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
| `src/Overrides/Jobs/DatabaseBackupJob.php` | Encryption + path prefix + classification-aware backup job overlay |
| `src/Overrides/Actions/Database/StartDatabaseProxy.php` | Expanded database port mapping for "Make Publicly Available" |
| `src/Overrides/Models/ServiceDatabase.php` | Wire-compatible database type mappings in databaseType() |
| `src/Overrides/Livewire/Project/Database/Import.php` | Encryption-aware restore overlay |
| `src/Overrides/Helpers/constants.php` | Expanded DATABASE_DOCKER_IMAGES with 50+ additional database images |
| `src/Overrides/Helpers/databases.php` | Encryption-aware S3 delete overlay |
| `src/Overrides/Views/livewire/storage/show.blade.php` | Storage page with encryption form |
| `src/Livewire/ResourceBackupPage.php` | Server resource backups page component |
| `src/Livewire/RestoreBackup.php` | Settings restore/import page with env var bulk import |
| `src/Overrides/Views/components/settings/navbar.blade.php` | Settings navbar with Restore + Templates tabs |
| `src/Overrides/Views/livewire/project/new/select.blade.php` | New Resource page with source labels, source filter, untested badges |
| `src/Overrides/Helpers/shared.php` | Override get_service_templates() to merge custom + ignored templates |
| `src/Services/TemplateSourceService.php` | GitHub API fetch, YAML parsing, template caching |
| `src/Models/CustomTemplateSource.php` | Custom template source model (repo URL, auth, cache) |
| `src/Livewire/CustomTemplateSources.php` | Settings page for managing template sources |
| `src/Jobs/SyncTemplateSourceJob.php` | Background job for syncing templates from GitHub |
| `src/Http/Controllers/Api/CustomTemplateSourceController.php` | REST API for template sources |
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
- Resource Backups: `.../{resource_type}/{resource_uuid}/resource-backups`
- Restore Backups: `/settings/restore-backup`

### UI Integration

Two approaches are used to add UI components to Coolify pages:

- **Access Matrix** — injected via middleware into `/team/admin` page (for admin/owner users). Uses `Blade::render()` + JavaScript DOM positioning.
- **View overlays** — modified copies of Coolify's Blade views. Used for:
  - **Storage Encryption Form** (`storage/show.blade.php`) — S3 path prefix + encryption settings
  - **Settings Backup** (`settings-backup.blade.php`) — instance file backup section below Coolify's native DB backup
  - **Resource Configuration** (`project/application/configuration.blade.php`, etc.) — adds "Resource Backups" sidebar item + `@elseif` content section that renders `enhanced::resource-backup-manager`
  - **Server Sidebar** (`components/server/sidebar.blade.php`) — adds "Resource Backups" sidebar item
  - **Settings Navbar** (`components/settings/navbar.blade.php`) — adds "Restore" tab linking to restore/import page
  - **New Resource Select** (`project/new/select.blade.php`) — adds custom template source name labels on service cards

**Why view overlays for backups?** The configuration pages use `$currentRoute` to conditionally render content. Adding a sidebar item requires both the `<a>` link in the sidebar AND an `@elseif` branch in the content area. This can only be done in the Blade view — not via middleware or JavaScript. The backup manager component (`enhanced::resource-backup-manager`) needs proper Livewire hydration, which requires native rendering in the view.

**ResourceBackupManager modes:** The component supports two modes:
- `resource` (default): Per-resource backups (volume, configuration, full) — used on resource configuration pages
- `global`: Coolify instance file backups — used on settings backup page and server resource backups page

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
20. **Resource backup sidebar integration** — Resource backup sidebar items are added via view overlays on Coolify's configuration pages (not middleware injection). Each overlay adds an `<a>` sidebar link and an `@elseif` content branch. The routes point to Coolify's own Configuration components (e.g., `App\Livewire\Project\Application\Configuration`), so the overlay view's `$currentRoute` check renders the backup manager.
21. **Resource backup route registration** — Web routes for resource backups point to Coolify's existing Configuration Livewire components (not our own). The overlay views detect the route name via `$currentRoute` and render the appropriate content. The server backup route uses our own `ResourceBackupPage` component since servers don't use the Configuration pattern.
22. **Settings backup overlay** — The settings backup page overlay adds an "Instance File Backup" section below Coolify's native database backup. It uses the `global` mode of ResourceBackupManager which only shows `coolify_instance` backup schedules.
23. **Configuration overlay maintenance** — Overlay views must be kept in sync with upstream Coolify changes. Each overlay is a full copy of the original with minimal additions (sidebar link + content branch). Mark enhanced additions with `{{-- Coolify Enhanced: ... --}}` comments for easy diffing.
24. **shared.php overlay is the largest** — `bootstrap/helpers/shared.php` is 3500+ lines. The overlay modifies ONLY `get_service_templates()`. Mark changes with `[CUSTOM TEMPLATES OVERLAY]` comments. When syncing with upstream, diff carefully.
25. **Custom templates are write-once** — After a service is deployed from a custom template, the compose YAML lives in the DB (`Service.docker_compose_raw`). No runtime operations re-read the template. Removing a source has zero impact on deployed services.
26. **Template name collisions** — Built-in templates always take precedence. Custom templates with matching names get a `-{source-slug}` suffix. Custom-to-custom collisions also get the source slug suffix.
27. **GitHub API rate limits** — Unauthenticated: 60 requests/hour. Authenticated: 5000/hour. The sync service uses retry logic but large sources with many files can hit limits without a token.
28. **Template cache directory** — Custom templates are cached at `/data/coolify/custom-templates/{source-uuid}/templates.json`. This directory must be writable by the www-data user.
29. **validateDockerComposeForInjection()** — Custom templates are validated using Coolify's injection validator during sync. Templates that fail validation are skipped (not fatal to the sync).
30. **Custom template `_source` field** — `parseTemplateContent()` adds `_source` (source name) and `_source_uuid` (source UUID) to every custom template. These fields pass through `loadServices()` to the frontend via `+ (array) $service`, enabling the select.blade.php overlay to show source labels on custom template cards.
31. **Select.blade.php overlay** — The New Resource page overlay adds a source label badge (top-right corner) on service cards from custom template sources. The doc icon position shifts down when a label is present via the `'top-6': service._source` Alpine.js class binding.
32. **Ignored/untested templates (`_ignored` flag)** — Coolify's `generate:services` command skips templates with `# ignore: true`, so they never appear in the JSON. The shared.php overlay loads these directly from YAML files on disk (`templates/compose/*.yaml`) and includes them with `_ignored: true`. Custom templates from `TemplateSourceService` also preserve `_ignored` instead of skipping. The select.blade.php overlay shows an amber "Untested" badge and requires user confirmation via `confirm()` before proceeding.
33. **Doc icon stacking with badges** — When a service card has both `_source` and `_ignored` badges, the doc icon shifts down further (`top: 2.25rem`). With only one badge, it shifts to `top: 1.25rem`. The `_ignored` badge itself shifts down (`top: 1.05rem`) when a `_source` label is also present.
34. **Source filter dropdown** — The New Resource page has a "Filter by source" dropdown next to the category filter. Uses `selectedSource` state: empty string = all, `__official__` = built-in only, or a specific source name. The dropdown only appears when `sources.length > 0`. Sources are extracted from `_source` fields after `loadServices()`.
35. **`isDatabaseImageEnhanced()` wrapper** — Defined in `shared.php` overlay, not in `docker.php`. Checks `coolify.database` label in both map format (`coolify.database: "true"`) and array format (`- coolify.database=true`) before delegating to Coolify's `isDatabaseImage()`. Only covers the 2 call sites in shared.php (service import + deployment), not the 4 in parsers.php (application deployments). This is intentional: parsers.php calls handle Application compose, not Service templates.
36. **`constants.php` overlay maintenance** — Keep the expanded `DATABASE_DOCKER_IMAGES` list in sync with Coolify upstream. The overlay is a full copy of the original file with additional entries grouped by database category. New entries should be added to the appropriate category section.
37. **`# type: database` injects labels into compose** — The comment header modifies the actual YAML (adds `coolify.database` label to all services), which is then base64-encoded. This means the label persists into `docker_compose_raw` in the DB, ensuring classification survives re-parses. Per-service labels take precedence over the template-level `# type:` header.
38. **Label check is case-insensitive** — `isDatabaseImageEnhanced()` lowercases the label key before matching. Boolean parsing uses PHP's `filter_var(FILTER_VALIDATE_BOOLEAN)`, which accepts `true/false/1/0/yes/no/on/off`.
39. **StartDatabaseProxy port resolution** — The overlay first tries Coolify's built-in match, then looks up the base image name in `DATABASE_PORT_MAP`, then tries partial string matching, then extracts port from the service's compose config. Only throws if all methods fail. The error message guides users to set `custom_type`.
40. **`ServiceDatabase::databaseType()` includes full image path** — For `memgraph/memgraph-mage`, it returns `standalone-memgraph/memgraph-mage` (not just `standalone-memgraph-mage`). The port resolution in `StartDatabaseProxy` handles this by extracting the base name via `afterLast('/')`.
41. **DatabaseBackupJob unsupported types** — Dump-based backups only work for postgres, mysql, mariadb, and mongodb. For other ServiceDatabase types (memgraph, redis, clickhouse, etc.), the job now throws a meaningful exception instead of silently returning. Users should either set `custom_type` (if the DB is wire-compatible) or use Resource Backups for volume-level backups.
42. **Wire-compatible mapping is conservative** — Only databases where standard dump tools produce CORRECT backups are mapped: YugabyteDB (pg_dump), TiDB (mysqldump), FerretDB (mongodump), Percona (mysqldump), Apache AGE (pg_dump). CockroachDB is NOT mapped despite speaking pgwire because `pg_dump` fails on its catalog functions. Vitess is NOT mapped because `mysqldump` needs extra flags and isn't reliable for sharded setups. For unmapped types, `isBackupSolutionAvailable()` correctly returns false. Users can set `custom_type` if they know their DB is compatible, or use Resource Backups.
43. **ServiceDatabase.php overlay maintenance** — The model is small (170 lines) but critical. The wire-compatible mappings in `databaseType()` use `$image->contains()` checks — be careful with substring false positives (e.g., `age` matching `garage` or `image` — the AGE check excludes these).
44. **parsers.php preserves existing records** — Even without our label check, `updateCompose()` in parsers.php first checks for existing ServiceApplication/ServiceDatabase records and preserves them. Re-classification only affects truly NEW services added during a compose update, and the expanded DATABASE_DOCKER_IMAGES handles most of those.

## Important Notes

1. **This is an addon** - It extends Coolify via overlay files and service provider
2. **Feature flag** - Set `COOLIFY_ENHANCED=true` to enable (backward compat: `COOLIFY_GRANULAR_PERMISSIONS=true` also works)
3. **docker-compose.custom.yml** - Coolify natively supports this file for overrides
4. **v5 compatibility** - Coolify v5 may include similar features; migration guide will be provided
5. **Backward compatible** - When disabled, behaves like standard Coolify
6. **Encryption is per-storage** - Each S3 storage destination can independently enable encryption
7. **S3 path prefix** - Configurable per-storage path prefix for multi-instance bucket sharing
8. **Resource backups** - Volume, configuration, and full backups via `enhanced::resource-backup-manager` component
9. **Custom templates** - External GitHub repos as template sources, managed via Settings > Templates page
10. **Database classification** - Expanded image list + `coolify.database` label + `# type: database` comment for explicit service classification

## See Also

- [AGENTS.md](AGENTS.md) - Detailed AI agent instructions
- [docs/custom-templates.md](docs/custom-templates.md) - Custom template creation guide
- [docs/coolify-source/](docs/coolify-source/) - Coolify source code reference
- [docs/architecture.md](docs/architecture.md) - Architecture details
- [docs/api.md](docs/api.md) - API documentation
- [docs/installation.md](docs/installation.md) - Installation guide

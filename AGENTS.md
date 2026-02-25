# AGENTS.md

Detailed instructions for AI assistants working with the Coolify Enhanced package.

## Mandatory Rules

1. **Keep docs updated** - After every significant code change, update CLAUDE.md and AGENTS.md with new learnings, patterns, and pitfalls.
2. **Update all documentation on every feature/modification** - Every new feature, modification, or bug fix **must** include updates to: (a) **README.md** — user-facing documentation so users know how to use and configure the feature, (b) **AGENTS.md** — technical details for AI agents including architecture, overlay files, and pitfalls, (c) **CLAUDE.md** — architecture knowledge, package structure, key files, and common pitfalls, (d) **docs/** files — relevant documentation files (e.g., `docs/custom-templates.md` for template-related changes). Do not consider a feature complete until documentation is updated.
3. **Create feature documentation** - Every new feature **must** have a dedicated folder under `docs/features/<feature-name>/` containing at minimum: (a) **PRD.md** — Product Requirements Document with problem statement, goals, solution design, technical decisions with rationale, user experience, files modified, risks, and testing checklist, (b) **plan.md** — Technical implementation plan with code snippets, file changes, and architecture details, (c) **README.md** — Feature overview, components, file list, and links to related docs. Feature documentation should be created before or during implementation and updated as the feature evolves. Use kebab-case for folder names (e.g., `enhanced-database-classification`).
4. **Pull Coolify source** - At the start of each session, run `git -C docs/coolify-source pull` to update the Coolify reference source. If missing, clone it: `git clone --depth 1 https://github.com/coollabsio/coolify.git docs/coolify-source`.
5. **Reference Coolify source** - When working on policies, authorization, or UI integration, browse `docs/coolify-source/` to understand Coolify's native implementation.
6. **Read before writing** - Always read existing files before modifying them.

## Package Context

This is a **Laravel package** that extends Coolify v4 with:

1. **Granular permissions** — Project-level and environment-level access management
2. **Encrypted S3 backups** — Transparent encryption at rest using rclone's crypt backend
3. **Resource Backups** — Volume, configuration, and full backups for Applications, Services, and Databases
4. **Custom Template Sources** — External GitHub repositories as sources for docker-compose service templates
5. **Enhanced Database Classification** — Expanded database image detection, `coolify.database` Docker label, `# type: database` comment convention, wire-compatible backup support, and expanded port mapping
6. **Network Management** — Per-environment Docker network isolation, shared networks, dedicated proxy network, server-level management UI, per-resource network assignment, and Docker Swarm overlay support
7. **MCP Server** — Model Context Protocol server enabling AI assistants to manage Coolify infrastructure via natural language
8. **Cluster Management** — Docker Swarm cluster dashboard, node management, service/task monitoring, cluster visualizer, Swarm secrets/configs, structured deployment config, and K8s-ready abstraction layer

It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system. For encryption, backup, classification, template, and network features, modified Coolify files are overlaid in the Docker image. The MCP server is a standalone TypeScript/Node.js package in `mcp-server/`.

### Network Management Architecture

Network management uses a post-deployment hook approach — zero overlay files for Phase 1 (environment isolation), two small overlays for Phase 2 (proxy isolation: `proxy.php` + `docker.php`), and zero new overlays for Phase 3 (Swarm support).

**Key files:**
- `src/Services/NetworkService.php` — Core engine (Docker CRUD, reconciliation, Swarm)
- `src/Jobs/NetworkReconcileJob.php` — Post-deployment network assignment
- `src/Jobs/ProxyMigrationJob.php` — Proxy isolation migration
- `src/Models/ManagedNetwork.php` — Network model (environment, shared, proxy, system scopes)
- `src/Models/ResourceNetwork.php` — Polymorphic resource-to-network pivot
- `src/Overrides/Helpers/proxy.php` — Phase 2: proxy compose network integration
- `src/Overrides/Helpers/docker.php` — Phase 2: `traefik.docker.network` label injection

**Critical patterns:**
- All shell commands use `escapeshellarg()` for interpolated values
- Network creation verified via post-creation inspection before marking ACTIVE
- Overlay blocks triple-guarded: `coolify-enhanced.enabled` + `network_management.enabled` + `proxy_isolation`
- Swarm manager validation before overlay network creation
- Network limit enforced in auto-provisioning (ensureEnvironmentNetwork, ensureProxyNetwork)

See `docs/features/network-management/` for PRD, implementation plan, and feature overview.

### Key Characteristics

1. **Addon, not core modification** - All code lives in a separate package (with overlay files for encryption)
2. **Feature-flagged** - Controlled by `COOLIFY_ENHANCED=true` (backward compat: `COOLIFY_GRANULAR_PERMISSIONS=true`)
3. **Backward compatible** - When disabled, Coolify behaves normally
4. **Docker-deployed** - Installed via custom Docker image extending official Coolify
5. **UI injection** - Access Matrix injected into `/team/admin`, Encryption Form injected into `/storages/{uuid}`

## Critical Architecture Knowledge

### Service Provider Boot Order (CRITICAL)

Laravel boots **package providers BEFORE application providers**. This means:

1. Our `CoolifyEnhancedServiceProvider::boot()` runs FIRST
2. Coolify's `AuthServiceProvider::boot()` runs AFTER us
3. Coolify's `$policies` property calls `Gate::policy()` internally, **overwriting** our policies

**Solution:** Defer policy registration using `$this->app->booted()`:

```php
// In CoolifyEnhancedServiceProvider::boot()
$this->app->booted(function () {
    $this->registerPolicies();    // Runs AFTER Coolify's AuthServiceProvider
    $this->registerUserMacros();
    $this->extendS3StorageModel();
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

### Service Provider (`CoolifyEnhancedServiceProvider.php`)

The main entry point that:
- Loads package configuration, migrations, and views
- Loads API routes (web UI is injected via middleware)
- Registers Livewire components (`enhanced::access-matrix`, `enhanced::storage-encryption-form`)
- Registers `InjectPermissionsUI` middleware for UI injection
- Registers Eloquent global scopes for filtering projects/environments
- **Defers** policy registration, User macros, and S3 model extension to `$this->app->booted()` callback

**Key methods:**
- `register()` - Merges config
- `boot()` - Loads all package resources, sets up booted callback
- `registerLivewireComponents()` - Registers `enhanced::access-matrix` and `enhanced::storage-encryption-form`
- `registerMiddleware()` - Pushes `InjectPermissionsUI` as global middleware
- `registerScopes()` - Adds ProjectPermissionScope and EnvironmentPermissionScope
- `registerPolicies()` - Overrides Gate policies for all resource types (called via booted callback)
- `registerUserMacros()` - Adds `canPerform()` macro to User model (called via booted callback)
- `extendS3StorageModel()` - Adds saving event for encryption fields (called via booted callback)

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

### Rclone Service (`Services/RcloneService.php`)

Handles all rclone-related operations for encrypted S3 backups.

**Key methods:**
```php
// Get the rclone Docker image name (configurable)
getRcloneImage(): string

// Check if a storage has encryption enabled and properly configured
isEncryptionEnabled(?S3Storage $s3): bool

// Implement rclone's password obscure algorithm in PHP
obscurePassword(string $password): string

// Generate env file content for rclone S3 + crypt remotes
buildEnvFileContent(S3Storage $s3): string

// Get the correct remote target (encrypted: or s3remote:)
getRemoteTarget(S3Storage $s3, string $remotePath): string

// Build full Docker command for encrypted upload
buildUploadCommands(...): array

// Build full Docker command for encrypted download
buildDownloadCommands(...): array

// Build full Docker command for encrypted file deletion
buildDeleteCommands(...): array

// Build cleanup commands for container and env file
buildCleanupCommands(string $containerName): string
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

### Traits

**HasS3Encryption** (`Traits/HasS3Encryption.php`)
- Added to S3Storage model via service provider
- `hasEncryption()` - Check if encryption is enabled and configured
- `hasFilenameEncryption()` - Check if filename encryption is active
- `getEncryptionConfig()` - Get encryption config as array

### Middleware

**InjectPermissionsUI** (`Http/Middleware/InjectPermissionsUI.php`)
- Global HTTP middleware that intercepts responses
- On `/team/admin` page, injects the AccessMatrix Livewire component (for admin/owner)
- On `/storages/{uuid}` page, injects the StorageEncryptionForm Livewire component
- Only injects for authenticated users on HTML responses
- Uses `Blade::render()` to server-side render Livewire components
- Includes positioning scripts to place UI within existing page structure

### Livewire Components

**AccessMatrix** (`Livewire/AccessMatrix.php`)
- Unified permission management component
- Displays a matrix of users x (projects + environments)
- Each cell is a permission level dropdown
- Supports bulk operations: All/None per row and per column
- Environment cells show "inherited" when using project-level cascade
- Users with owner/admin role show "bypass" (non-editable)
- Search/filter for users

**StorageEncryptionForm** (`Livewire/StorageEncryptionForm.php`)
- Per-storage encryption settings component
- Enable/disable toggle, password fields, filename encryption dropdown
- Directory name encryption toggle (disabled when filename encryption is off)
- Warning about password loss
- Saves settings to S3Storage model

### Cluster Management Architecture

Comprehensive Docker Swarm cluster management with a K8s-ready orchestrator abstraction layer. Feature flag: `COOLIFY_CLUSTER_MANAGEMENT=true`.

**Key files:**
- `src/Contracts/ClusterDriverInterface.php` — Orchestrator abstraction (K8s-ready)
- `src/Drivers/SwarmClusterDriver.php` — Docker Swarm implementation (SSH + Docker CLI)
- `src/Models/Cluster.php` — Cluster entity (uuid, name, type, status, settings, metadata)
- `src/Services/ClusterDetectionService.php` — Auto-detect Swarm clusters from manager servers
- `src/Jobs/ClusterSyncJob.php` — Periodic cluster metadata refresh
- `src/Livewire/ClusterDashboard.php` — Main dashboard (overview, services, visualizer, events tabs)
- `src/Livewire/ClusterVisualizer.php` — Dual-view: column-per-node grid + topology map
- `src/Livewire/ClusterNodeManager.php` — Node actions: drain/activate, promote/demote, labels
- `src/Livewire/SwarmConfigForm.php` — Structured Swarm config (replaces YAML textarea)
- `src/Http/Controllers/Api/ClusterController.php` — REST API

**Critical patterns:**
- **Orchestrator abstraction**: ALL Docker commands go through `ClusterDriverInterface`. Never call Docker directly from UI components. Future `KubernetesClusterDriver` implements same interface.
- **SSH execution**: Uses Coolify's `instant_remote_process()` for all Docker CLI commands. JSON output parsing (`--format '{{json .}}'`).
- **Command injection prevention**: `escapeshellarg()` on ALL interpolated CLI arguments (node IDs, label keys/values, service IDs).
- **Caching**: Node/service lists cached 30s, cluster info 60s. Write operations (drain, scale) must call `Cache::forget("cluster:{id}:{resource}")`.
- **Auto-detection**: `docker info --format json` on Swarm managers → extract Swarm ID → find-or-create Cluster → link servers by IP matching.
- **Team-scoped**: Clusters belong to teams. All queries use `Cluster::ownedByTeam(currentTeam()->id)`.
- **Encrypted secrets**: Join tokens in `Cluster.settings` use `'encrypted:array'` cast. Never exposed in API responses.
- **Phase 1 = zero overlays**: Full cluster visibility without any Coolify file modifications.

**Data model:**
```
Cluster (type: swarm|kubernetes)
├── manager_server_id → Server (primary Swarm manager)
├── team_id → Team
├── settings (encrypted: join tokens, sync config)
├── metadata (cached: swarm_id, node/service counts)
├── servers() → Server[] (via server.cluster_id FK)
├── events() → ClusterEvent[]
└── driver() → ClusterDriverInterface (SwarmClusterDriver for Swarm type)
```

**Coolify Swarm source reference:**
- `Server.php`: `isSwarm()`, `isSwarmManager()`, `isSwarmWorker()` methods, `swarm_cluster` integer field
- `SwarmDocker.php`: Polymorphic destination model for Swarm resources (104 lines)
- `Application.php`: `swarm_replicas`, `swarm_placement_constraints` (base64 YAML)
- `docker.php` helpers: `getContainerStatus()` branches on `$server->isSwarm()` to use `docker service ls`
- `ApplicationDeploymentJob.php`: Uses `docker stack deploy` for Swarm destinations

See `docs/features/cluster-management/` for full PRD, implementation plan, and README.

### Overlay Files (Overrides)

Modified Coolify files that are copied over originals in the Docker image:

**DatabaseBackupJob** (`Overrides/Jobs/DatabaseBackupJob.php`)
- Branches `upload_to_s3()` into encrypted (rclone) vs unencrypted (mc) paths
- Tracks `is_encrypted` on backup execution record

**Import** (`Overrides/Livewire/Project/Database/Import.php`)
- Handles encrypted restore via rclone download
- Detects encryption status and routes through appropriate path

**databases.php** (`Overrides/Helpers/databases.php`)
- Modified `deleteBackupsS3()` to use rclone when filename encryption is enabled

**select.blade.php** (`Overrides/Views/livewire/project/new/select.blade.php`)
- Adds custom template source label badges on service cards in the New Resource page
- Uses `service._source` field (set by `TemplateSourceService::parseTemplateContent()`) to identify custom templates
- Badge: small pill in top-right corner showing the source name
- Doc icon position shifts down when a source label is present

**shared.php** (`Overrides/Helpers/shared.php`)
- Modifies `get_service_templates()` to merge custom templates from enabled sources alongside built-in ones
- Defines `isDatabaseImageEnhanced()` wrapper that checks `coolify.database` Docker labels before falling back to `isDatabaseImage()`
- Injects `coolify.database` labels from `# type: database` comment headers into compose YAML

**constants.php** (`Overrides/Helpers/constants.php`)
- Overlay of `bootstrap/helpers/constants.php` with ~50 additional database images
- Categories: graph, vector, time-series, document, search, key-value, column-family, NewSQL, OLAP
- Must be kept in sync with Coolify upstream (full file copy with additions)

**StartDatabaseProxy** (`Overrides/Actions/Database/StartDatabaseProxy.php`)
- Expanded `DATABASE_PORT_MAP` constant mapping ~50 database base image names to default internal ports
- Multi-level fallback: built-in match → base image lookup → partial string match → compose port extraction → helpful error
- Fixes "Unsupported database type" error when toggling "Make Publicly Available" for unrecognized databases

**ServiceDatabase** (`Overrides/Models/ServiceDatabase.php`)
- Expanded `databaseType()` with wire-compatible database mappings: YugabyteDB→postgresql, TiDB→mysql, FerretDB→mongodb, Percona→mysql, Apache AGE→postgresql
- This automatically enables backup UI, dump-based backups, import UI, and correct port mapping for wire-compatible databases
- Conservative mapping: only databases where standard dump tools produce correct backups are mapped (CockroachDB, Vitess, ScyllaDB are NOT mapped)
- Added `proxy_ports` JSON cast, `getServiceDatabaseUrls()` for multi-port URL generation, `parseProxyPortsLabel()` for parsing the `coolify.proxyPorts` Docker label

**Service/Index.php** (`Overrides/Livewire/Project/Service/Index.php`)
- Full overlay of Coolify's Service Index Livewire component
- Adds multi-port proxy support: detects `coolify.proxyPorts` label from `docker_compose_raw`, renders per-port toggle/public-port UI
- New properties: `proxyPorts`, `availableProxyPorts`, `hasMultiPortProxy`
- Enhanced `instantSave()` to branch to `instantSaveMultiPort()` when multi-port label detected
- `updateProxyPorts()` saves port config and restarts proxy container if running
- Falls back to stock single-port UI when label is absent

**service/index.blade.php** (`Overrides/Views/livewire/project/service/index.blade.php`)
- Full overlay of Coolify's service index Blade view
- `@if ($hasMultiPortProxy)` branch shows per-port table with checkboxes and public port inputs
- Falls back to original single-port toggle when multi-port is not available

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
2. Install Enhanced addon (requires Coolify)
3. Uninstall Enhanced addon (delegates to `uninstall.sh`)
4. Check installation status
5. Full setup (Coolify + addon in sequence)

**CLI mode** (with args):
- `--install-coolify` - Downloads and runs the official Coolify installer
- `--install-addon` - Installs the enhanced addon (GHCR or `--local`)
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
5. Sets `COOLIFY_ENHANCED=true` in `.env`
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
2. Copies package files to `/tmp/coolify-enhanced`
3. Configures composer to use local path repository
4. Installs package via composer
5. Overlays modified Coolify files (DatabaseBackupJob, Import, databases.php)
6. Sets up s6-overlay for migrations
7. Runs composer dump-autoload

## Common Tasks

### Grant user access to project

```php
use AmirhMoradi\CoolifyEnhanced\Services\PermissionService;

PermissionService::grantProjectAccess($user, $project, 'deploy');
```

### Check permission programmatically

```php
use AmirhMoradi\CoolifyEnhanced\Services\PermissionService;

if (PermissionService::canPerform($user, 'deploy', $application)) {
    // Allow deployment
}
```

### Override environment permissions

```php
use AmirhMoradi\CoolifyEnhanced\Services\PermissionService;

PermissionService::grantEnvironmentAccess($user, $environment, 'view_only');
```

## Troubleshooting

### Permissions not being enforced

1. Check feature flag: `config('coolify-enhanced.enabled')` should be `true`
2. Verify environment variable: `COOLIFY_ENHANCED=true`
3. Check user's team role (owner/admin bypasses all checks)
4. Verify policies are registered: check `Gate::getPolicyFor(Application::class)` returns our policy class
5. Check boot order: ensure `$this->app->booted()` is used for policy registration

### Environment override not working

1. Verify `environment_user` record exists for the user+environment
2. Check that `hasEnvironmentPermission()` is called (not just `hasProjectPermission()`)
3. Ensure `canCreateInCurrentContext()` checks environment level first

### Migrations not running

1. Check s6 service logs: `cat /var/log/s6-rc/addon-migration/current`
2. Run manually: `php artisan migrate --path=vendor/amirhmoradi/coolify-enhanced/database/migrations`

### Access Matrix not showing

1. Verify user has admin/owner role (only admins/owners see the matrix)
2. Clear view cache: `php artisan view:clear`
3. Check container logs for middleware errors
4. Verify you are on the `/team/admin` or `/team` page

### Encryption form not showing

1. Navigate to a storage detail page (`/storages/{uuid}`)
2. Check that the InjectPermissionsUI middleware is registered
3. Check that `storage.show` route name matches
4. Look for JavaScript errors in browser console

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
11. **Rclone password obscuring** — Uses AES-256-CTR with a well-known fixed key from rclone's source. The PHP implementation must produce exactly the same output as `rclone obscure`.
12. **Env file for rclone credentials** — Base64-encoded env file written to server, passed via `--env-file` to Docker. Must be cleaned up after use to avoid credential leaks.
13. **Filename encryption breaks S3 listing** — When `filename_encryption != 'off'`, files on S3 have encrypted names. Cannot use Laravel Storage driver for listing/deleting; must use rclone.
14. **Custom template `_source` passes through to frontend** — `parseTemplateContent()` adds `_source` and `_source_uuid` to each template object. In `Select.php::loadServices()`, the `+ (array) $service` merge preserves these fields, so Alpine.js can access `service._source` to render source labels.
15. **Select.blade.php overlay is a full page copy** — The New Resource select overlay copies the entire original view with minimal additions (source label badge + doc icon shift). Mark enhanced additions with `{{-- Coolify Enhanced: ... --}}` comments. Must be kept in sync with upstream Coolify changes.
16. **`isDatabaseImageEnhanced()` wrapper** — Defined in `shared.php`, NOT `docker.php`. Checks `coolify.database` label in both map format (`coolify.database: "true"`) and array format (`- coolify.database=true`) before delegating to `isDatabaseImage()`. Only covers 2 call sites in shared.php (service import + deployment), not 4 in parsers.php. This is intentional: parsers.php handles Application compose, not Service templates.
17. **`constants.php` overlay maintenance** — Full copy of the original with ~50 additional entries grouped by database category. Must be kept in sync with Coolify upstream. New entries should be added to the appropriate category section.
18. **`# type: database` injects labels into compose** — The comment header modifies actual YAML (adds `coolify.database` label to all services), which is then base64-encoded. Label persists into `docker_compose_raw` in DB, ensuring classification survives re-parses. Per-service labels take precedence.
19. **Label check is case-insensitive** — `isDatabaseImageEnhanced()` lowercases the label key. Boolean parsing uses `filter_var(FILTER_VALIDATE_BOOLEAN)`, which accepts `true/false/1/0/yes/no/on/off`.
20. **StartDatabaseProxy port resolution** — Tries built-in match first, then `DATABASE_PORT_MAP` lookup by base image, then partial string match, then compose port extraction, then helpful error guiding user to set `custom_type`.
21. **Wire-compatible mapping is conservative** — Only YugabyteDB (pg_dump), TiDB (mysqldump), FerretDB (mongodump), Percona (mysqldump), Apache AGE (pg_dump). CockroachDB NOT mapped (pg_dump fails on catalog). Vitess NOT mapped (mysqldump unreliable for sharded setups). Users can set `custom_type` for manual override.
22. **ServiceDatabase.php overlay maintenance** — Small (170 lines) but critical. Wire-compatible mappings use `$image->contains()` — watch for substring false positives (e.g., `age` matching `garage` or `image`; the AGE check excludes these).
23. **parsers.php preserves existing records** — Even without our label check, `updateCompose()` preserves existing ServiceApplication/ServiceDatabase records. Re-classification only affects truly NEW services, and the expanded DATABASE_DOCKER_IMAGES handles most cases.
24. **Multi-port proxy label format** — `coolify.proxyPorts` label: `"internalPort:label,internalPort:label,..."` (e.g., `"7687:bolt,7444:log-viewer"`). Parsed by `ServiceDatabase::parseProxyPortsLabel()`. Label name is case-sensitive.
25. **Multi-port proxy backward compat** — When `coolify.proxyPorts` label is absent from compose, `$hasMultiPortProxy` is `false` and the view shows stock Coolify single-port UI. No behavioral changes for databases without the label.
26. **Multi-port `proxy_ports` JSON** — Schema: `{"7687": {"public_port": 17687, "label": "bolt", "enabled": true}, ...}`. Keys are internal port strings. Initialized from label defaults on first Livewire component mount.
27. **Service/Index.php overlay size** — ~560 lines, full copy of Coolify's original. Multi-port changes are marked with `[MULTI-PORT PROXY OVERLAY]` comments. Keep in sync with upstream.
28. **Multi-port nginx** — `StartDatabaseProxy::handleMultiPort()` generates multiple `server` blocks in one nginx `stream` context. All ports share one proxy container. `StopDatabaseProxy` needs no changes (just `docker rm -f`).

## Coolify Source Reference

The Coolify source code is cloned at `docs/coolify-source/` (gitignored). Key reference files:

| File | What to check |
|------|---------------|
| `app/Providers/AuthServiceProvider.php` | All registered policies and gates |
| `app/Policies/` | Default policy implementations (all return true) |
| `resources/views/` | Blade templates using `canGate`, `@can` directives |
| `app/Livewire/` | Components using `$this->authorize()` |
| `app/Models/` | Model relationships and structure |
| `app/Jobs/DatabaseBackupJob.php` | Backup job with `upload_to_s3()` method |
| `app/Livewire/Project/Database/Import.php` | Restore component with `restoreFromS3()` |
| `bootstrap/helpers/databases.php` | Helper functions including `deleteBackupsS3()` |
| `app/Models/S3Storage.php` | S3 storage model with encrypted casts |
| `app/Livewire/Project/New/Select.php` | New Resource page component (loadServices method) |
| `resources/views/livewire/project/new/select.blade.php` | New Resource page view (service card rendering) |
| `templates/compose/` | Built-in service templates (YAML format reference) |
| `bootstrap/helpers/shared.php` | Helper functions including `get_service_templates()` |
| `bootstrap/helpers/constants.php` | `DATABASE_DOCKER_IMAGES` constant (our overlay expands this) |
| `bootstrap/helpers/docker.php` | `isDatabaseImage()` function (NOT overlaid — wrapper in shared.php) |
| `app/Actions/Database/StartDatabaseProxy.php` | Database proxy with port mapping (our overlay expands ports) |
| `app/Models/ServiceDatabase.php` | Service database model with `databaseType()` (our overlay adds wire-compat mappings + multi-port proxy) |
| `app/Livewire/Project/Service/Index.php` | Service index Livewire component (our overlay adds multi-port proxy UI logic) |

## Version Compatibility

| Package Version | Coolify Version | PHP Version |
|-----------------|-----------------|-------------|
| 1.x | v4.x | 8.2+ |

**Note:** Coolify v5 may include similar built-in features. A migration guide will be provided when v5 is released.

## MCP Server

### Overview

The `mcp-server/` directory contains a standalone TypeScript MCP (Model Context Protocol) server that wraps Coolify's REST API and coolify-enhanced's API endpoints. It enables AI assistants (Claude Desktop, Cursor, VS Code) to manage infrastructure via natural language.

### Architecture

- **Language**: TypeScript (ES2022, NodeNext modules)
- **SDK**: `@modelcontextprotocol/sdk` v1.26+
- **Transport**: stdio (StdioServerTransport) — standard for MCP CLI servers
- **API Client**: `CoolifyClient` in `src/lib/coolify-client.ts` — single HTTP client for both native and enhanced endpoints
- **Tool Registration**: 14 modules in `src/tools/` register ~99 tools via `server.tool()` with Zod schemas

### Tool Module Layout

| Module | Tools | Description |
|--------|-------|-------------|
| `servers.ts` | 8 | Server CRUD, resources, domains, validate |
| `projects.ts` | 9 | Project + environment CRUD |
| `applications.ts` | 10 | App CRUD, start/stop/restart, logs, deploy |
| `databases.ts` | 8 | Database CRUD, start/stop/restart |
| `services.ts` | 8 | Service CRUD, start/stop/restart |
| `deployments.ts` | 4 | Deployment list, details, cancel, app history |
| `environment-variables.ts` | 10 | App + service env var CRUD, bulk update |
| `database-backups.ts` | 5 | DB backup config + executions |
| `security.ts` | 7 | Private keys, teams |
| `system.ts` | 3 | Version, health, resources |
| `permissions.ts` | 5 | [Enhanced] Project access management |
| `resource-backups.ts` | 5 | [Enhanced] Resource backup schedules |
| `templates.ts` | 7 | [Enhanced] Custom template sources |
| `networks.ts` | 10 | [Enhanced] Network management |

### Key Implementation Patterns

1. **Every tool handler wraps calls in try/catch** and returns `{ isError: true }` on failure
2. **Tool annotations are flat** — `{ readOnlyHint: true, destructiveHint: false, ... }` (NOT nested under `annotations:`)
3. **Enhanced tools are conditionally registered** — `createMcpServer()` checks `enhanced` boolean
4. **Feature detection** — `client.isEnhanced()` probes `GET /api/v1/resource-backups`; 200/401/403 = enhanced, 404 = standard
5. **Logging uses stderr** — `console.error()` for all logging; stdout is reserved for MCP protocol
6. **Retry with exponential backoff** — `requestWithRetry()` retries on 429/5xx up to 3 times

### Development

```bash
cd mcp-server
npm install
npm run build    # TypeScript compilation
npm run dev      # Watch mode
npm start        # Run the server

# Environment variables required:
# COOLIFY_BASE_URL=https://coolify.example.com
# COOLIFY_ACCESS_TOKEN=your-token
# COOLIFY_ENHANCED=true  (optional — auto-detected)
```

### Pitfalls

- **Don't use `console.log()`** — stdout is the MCP protocol channel. Use `console.error()` for logging
- **Annotations are flat** — `server.tool(name, desc, schema, { readOnlyHint: true }, callback)` — the 4th arg is `ToolAnnotations`, not `{ annotations: ToolAnnotations }`
- **Health endpoint path** — `/health` is NOT under `/api/v1`. The client uses a relative path `/../health` to escape the prefix
- **Feature detection failure** — If the enhanced API probe throws a network error (not 404), the server defaults to core-only mode rather than crashing

# CLAUDE.md

This file provides guidance to **Claude Code** and other AI assistants when working with this codebase.

> **For detailed AI agent instructions, see [AGENTS.md](AGENTS.md)**

## Mandatory Rules for AI Agents

1. **Keep documentation updated** - After every significant code change, update CLAUDE.md and AGENTS.md with new learnings, patterns, and pitfalls discovered during implementation.
2. **Update all documentation on every feature/modification** - Every new feature, modification, or bug fix **must** include updates to: (a) **README.md** — user-facing documentation so users know how to use and configure the feature, (b) **AGENTS.md** — technical details for AI agents including architecture, overlay files, and pitfalls, (c) **CLAUDE.md** — architecture knowledge, package structure, key files, and common pitfalls, (d) **docs/** files — relevant documentation files (e.g., `docs/custom-templates.md` for template-related changes). Do not consider a feature complete until documentation is updated.
3. **Create feature documentation** - Every new feature **must** have a dedicated folder under `docs/features/<feature-name>/` containing at minimum: (a) **PRD.md** — Product Requirements Document with problem statement, goals, solution design, technical decisions with rationale, user experience, files modified, risks, and testing checklist, (b) **plan.md** — Technical implementation plan with code snippets, file changes, and architecture details, (c) **README.md** — Feature overview, components, file list, and links to related docs. Feature documentation should be created before or during implementation and updated as the feature evolves. Use kebab-case for folder names (e.g., `enhanced-database-classification`).
4. **Pull Coolify source on each prompt** - At the start of each session, run `git -C docs/coolify-source pull` to ensure the Coolify reference source is up to date. If the directory doesn't exist, clone it: `git clone --depth 1 https://github.com/coollabsio/coolify.git docs/coolify-source`.
5. **Browse Coolify source for context** - When working on policies, authorization, or UI integration, always reference the Coolify source under `docs/coolify-source/` to understand how Coolify implements things natively.
6. **Read before writing** - Always read existing files before modifying them. Understand the current state before making changes.

## Project Overview

This is a Laravel package that extends Coolify v4 with three main features:

1. **Granular Permissions** — Project-level and environment-level access management with role-based overrides
2. **Encrypted S3 Backups** — Transparent encryption at rest for all backups using rclone's crypt backend (NaCl SecretBox: XSalsa20 + Poly1305)
3. **Resource Backups** — Volume, configuration, and full backups for Applications, Services, and Databases (beyond Coolify's database-only backup)
4. **Custom Template Sources** — Add external GitHub repositories as sources for docker-compose service templates, extending Coolify's one-click service list
5. **Enhanced Database Classification** — Expanded database image detection list and `coolify.database` label/`# type: database` comment convention for explicit service classification
6. **Network Management** — Per-environment Docker network isolation, shared networks, dedicated proxy network, server-level management UI, and per-resource network assignment
7. **MCP Server** — Model Context Protocol server enabling AI assistants (Claude Desktop, Cursor, VS Code) to manage Coolify infrastructure via natural language
8. **Cluster Management** — Comprehensive Docker Swarm cluster dashboard, node management, service/task monitoring, cluster visualizer, Swarm secrets/configs, and structured deployment configuration with K8s-ready abstraction layer
9. **Enhanced UI Theme** — Optional corporate-grade modern UI theme (CSS + minimal JS only); activatable in Settings > Appearance; disabled by default

It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system. For encryption and backup features, modified Coolify files are overlaid in the Docker image. The MCP server is a standalone TypeScript/Node.js package in `mcp-server/`.

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
- **Multi-port database proxy**: `coolify.proxyPorts` Docker label convention (e.g., `"7687:bolt,7444:log-viewer"`) enables proxying multiple TCP ports for a single ServiceDatabase container. Stored in `proxy_ports` JSON column on `service_databases` table. Service/Index.php Livewire overlay detects the label from `docker_compose_raw` and renders per-port toggle/public-port UI. `StartDatabaseProxy` generates multiple nginx `stream` server blocks. Falls back to single-port UI when label is absent
- **parsers.php NOT overlaid**: The 2484-line parsers.php is not overlaid. Its `isDatabaseImage()` calls don't use our label check, but existing ServiceDatabase records are preserved during re-parse (code checks for existing records before creating new ones). The expanded DATABASE_DOCKER_IMAGES covers most cases at the `isDatabaseImage()` level anyway

### Network Management Architecture

Provides per-environment Docker network isolation (Phase 1), proxy network isolation (Phase 2), and Docker Swarm overlay network support (Phase 3). Phase 1 uses zero overlay files; Phase 2 adds two small overlays (`proxy.php`, `docker.php`); Phase 3 adds no new overlays.

#### Phase 1: Environment Network Isolation (zero overlays)

- **Post-deployment hook approach**: Instead of overlaying Coolify's 4130-line `ApplicationDeploymentJob.php`, the system uses Docker-level network manipulation. After Coolify deploys a resource normally, event listeners trigger `NetworkReconcileJob` which connects containers to managed networks via `docker network connect`.
- **Per-environment isolation**: Each environment gets its own Docker bridge network (`ce-env-{env_uuid}`). Resources within an environment can communicate by container name (DNS). Cross-environment communication requires explicit shared networks.
- **Shared networks**: User-created networks (`ce-shared-{uuid}`) that resources from any environment can explicitly join, enabling cross-environment communication.
- **Deployment-driven reconciliation**: Uses `ApplicationDeploymentQueue::updated()` model observer for precise application reconciliation (fires when deployment status becomes `finished`). For Services/Databases, listens for `ServiceStatusChanged`/`DatabaseStatusChanged` events and does team-based resource lookup since Coolify's events only carry `teamId`/`userId` (not the resource).
- **Verify-on-use strategy**: No periodic SSH-heavy sync. Networks are reconciled at deployment, on UI page load (60s cache), and via explicit "Sync Networks" button.
- **Docker labels for tracking**: All managed networks get `coolify.managed=true` label, enabling filtered `docker network ls` without listing system networks.
- **Race condition handling**: Idempotent Docker commands (`2>/dev/null || true`), DB unique constraints on `(docker_network_name, server_id)`, and `firstOrCreate` with exception catching.
- **Three isolation modes**: `none` (manual only), `environment` (auto-create per-env networks), `strict` (also disconnects from default `coolify` network).
- **Standalone and Swarm support**: Phase 1 uses bridge networks for standalone Docker. Phase 3 adds automatic overlay network support for Swarm servers via `resolveNetworkDriver()`.
- **Network limit**: Configurable max per server (default 200) to prevent Docker iptables performance degradation.

#### Phase 2: Proxy Network Isolation (two overlays)

- **Dedicated proxy network**: Creates `ce-proxy-{server_uuid}` per server. Only resources with FQDNs join it. Prevents proxy from having network-level access to internal-only services.
- **`traefik.docker.network` label injection**: The `docker.php` overlay adds an optional `$proxyNetwork` parameter to `fqdnLabelsForTraefik()` and `fqdnLabelsForCaddy()`. When proxy isolation is enabled, `generateLabelsApplication()` resolves the proxy network name and passes it. This ensures Traefik always routes through the correct network IP — preventing intermittent 502 errors in multi-network setups.
- **Proxy compose integration**: The `proxy.php` overlay modifies `connectProxyToNetworks()`, `collectDockerNetworksByServer()`, `generateDefaultProxyConfiguration()`, and `ensureProxyNetworksExist()` to include/prefer the dedicated proxy network when `COOLIFY_PROXY_ISOLATION=true`.
- **Service label coverage**: The existing `shared.php` overlay is updated to pass `proxyNetwork` to all `fqdnLabelsForTraefik/Caddy` calls for Services and docker-compose Applications.
- **Migration workflow**: `ProxyMigrationJob` creates proxy network, connects `coolify-proxy` container, and connects all FQDN-bearing resources. After all resources are redeployed, optional cleanup disconnects proxy from non-proxy networks.
- **Dynamic label generation**: Proxy network name is resolved at label generation time (not stored in DB), so moving a resource to a different server always uses the correct proxy network name.
- **No parsers.php overlay**: The `parsers.php` call sites (8 calls) are NOT overlaid. Post-deployment reconciliation ensures containers end up on the correct network regardless.

#### Phase 3: Swarm Overlay Network Support (zero new overlays)

- **Automatic driver detection**: `NetworkService::resolveNetworkDriver()` returns `overlay` for Swarm servers, `bridge` for standalone. All `ensure*Network()` methods auto-detect the correct driver.
- **`docker service update` approach**: Swarm tasks cannot use `docker network connect`. Instead, `NetworkService::updateSwarmServiceNetworks()` uses `docker service update --network-add/--network-rm --detach` to modify the service spec, triggering a zero-downtime rolling update.
- **Batched network changes**: Multiple `--network-add` and `--network-rm` flags are combined into a single `docker service update` command per service, minimizing the number of rolling updates.
- **Service name discovery**: `getSwarmServiceNames()` discovers Swarm service names via `docker service ls` with label filters (`coolify.applicationId`) and name filters, handling Applications, Services, and standalone databases.
- **Manager node awareness**: `isSwarmManager()` checks if a server is a Swarm manager node. Network creation must occur on manager nodes.
- **Overlay encryption**: Optional `--opt encrypted` flag for IPsec encryption between Swarm nodes. Configured via `COOLIFY_SWARM_OVERLAY_ENCRYPTION=true`.
- **Default network name**: Strict isolation mode disconnects from `coolify-overlay` (Swarm) instead of `coolify` (standalone).
- **Event listener fix**: Replaced broken event listeners (Coolify events only carry `teamId`/`userId`, not resource) with `ApplicationDeploymentQueue::updated()` observer for precise application reconciliation and team-based lookup for services/databases.
- **Proxy overlay for Swarm**: `proxy.php` overlay creates proxy networks with `--driver overlay --attachable` for Swarm servers, with optional `--opt encrypted`.
- **UI Swarm indicators**: Network manager shows "Swarm Manager/Worker" badge, overlay driver badge, and encrypted overlay indicator. Create network form includes "Encrypted Overlay" checkbox for Swarm servers.

### MCP Server Architecture

A standalone TypeScript/Node.js MCP server in `mcp-server/` that wraps Coolify's REST API and coolify-enhanced's API endpoints, enabling AI assistants to manage infrastructure through natural language.

- **Transport**: stdio (JSON-RPC) via `@modelcontextprotocol/sdk` — standard for MCP CLI servers
- **API Client**: `CoolifyClient` class handles both native Coolify API (`/api/v1/*`) and coolify-enhanced API endpoints using Bearer token authentication
- **Tool registration**: 14 tool modules register ~99 tools on the MCP server organized by category (servers, projects, applications, databases, services, deployments, env vars, backups, security, system, permissions, resource backups, templates, networks)
- **Feature detection**: Enhanced tools (permissions, resource backups, templates, networks) are conditionally registered based on `COOLIFY_ENHANCED=true` env var or auto-detected via a probe to the enhanced API
- **Tool annotations**: Every tool includes `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` to help AI clients make safety decisions
- **Retry logic**: Exponential backoff (2^attempt seconds, max 3 retries) for transient failures (429, 5xx)
- **Published as**: `@amirhmoradi/coolify-enhanced-mcp` npm package, runnable via `npx`
- **Zero overlay files**: The MCP server is entirely standalone — no Coolify file modifications needed

```
AI Client (Claude Desktop, Cursor, etc.)
    ↕ stdio (JSON-RPC)
MCP Server (@amirhmoradi/coolify-enhanced-mcp)
    ↕ HTTPS (REST API)
Coolify Instance (with optional coolify-enhanced addon)
```

### Cluster Management Architecture

Comprehensive Docker Swarm cluster management with a K8s-ready orchestrator abstraction layer. See `docs/features/cluster-management/` for full PRD, plan, and README.

- **Orchestrator abstraction**: `ClusterDriverInterface` contract with `SwarmClusterDriver` implementation. Future `KubernetesClusterDriver` can be added without rewriting UI/business logic
- **Explicit Cluster model**: New `Cluster` Eloquent model (not implicit server grouping). Auto-detected from Swarm manager servers via `docker info`, linked to servers via `server.cluster_id` FK
- **SSH-based Docker CLI**: Uses Coolify's `instant_remote_process()` for all Docker commands. JSON output format (`--format '{{json .}}'`) for reliable parsing
- **Cached with TTL**: All Docker queries cached (default 30s) to prevent SSH storms on dashboard page loads. Explicit cache invalidation on write operations
- **Team-scoped**: Clusters belong to teams, inheriting Coolify's multi-tenancy model
- **Feature flag**: `COOLIFY_CLUSTER_MANAGEMENT=true` to enable

**Key components:**
- `ClusterDashboard` — Status cards + node table + tabs (Overview, Services, Visualizer, Events)
- `ClusterServiceViewer` — Service table with inline task expansion
- `ClusterVisualizer` — Dual view: column-per-node task grid (Portainer-style) + interactive topology map
- `ClusterNodeManager` — Node actions: drain/activate/pause, promote/demote, label management
- `SwarmConfigForm` — Structured form replacing Coolify's raw YAML textarea for Swarm deployment config
- `ClusterSecrets` / `ClusterConfigs` — Docker Swarm primitives management

**Data flow:**
```
UI Component → Cluster::driver() → SwarmClusterDriver
  → instant_remote_process(["docker node ls --format '{{json .}}'"], $managerServer)
  → JSON parse → Cache → Return to UI
```

**Phase breakdown:**
1. Phase 1: Read-only dashboard + node visibility + service/task viewer + visualizer (zero overlays)
2. Phase 2: Node management + service operations + structured Swarm config (one overlay: swarm.blade.php)
3. Phase 3: Secrets/configs CRUD + event persistence
4. Phase 4: Resource↔service linking + alerts + MCP tools

## Quick Reference

### Package Structure

```
coolify-enhanced/
├── src/
│   ├── CoolifyEnhancedServiceProvider.php     # Main service provider
│   ├── Services/
│   │   ├── PermissionService.php              # Core permission logic
│   │   ├── RcloneService.php                  # Rclone encryption commands
│   │   ├── TemplateSourceService.php          # GitHub template fetch & parse
│   │   ├── NetworkService.php                 # Docker network operations + reconciliation
│   │   ├── ClusterService.php                 # High-level cluster operations
│   │   └── ClusterDetectionService.php        # Auto-detection of Swarm clusters
│   ├── Models/
│   │   ├── ProjectUser.php                    # Project access pivot
│   │   ├── EnvironmentUser.php                # Environment override pivot
│   │   ├── ScheduledResourceBackup.php        # Resource backup schedule model
│   │   ├── ScheduledResourceBackupExecution.php # Resource backup execution model
│   │   ├── CustomTemplateSource.php           # Custom GitHub template source
│   │   ├── ManagedNetwork.php                 # Docker network model
│   │   ├── ResourceNetwork.php               # Resource-network pivot model
│   │   ├── Cluster.php                        # Cluster entity model
│   │   ├── ClusterEvent.php                   # Cluster event log model
│   │   ├── SwarmSecret.php                    # Swarm secret tracking model
│   │   ├── SwarmConfig.php                    # Swarm config tracking model
│   │   └── EnhancedUiSettings.php             # Key-value UI settings (e.g. enhanced_theme_enabled)
│   ├── Traits/
│   │   └── HasS3Encryption.php                # S3 encryption helpers for model
│   ├── Contracts/
│   │   └── ClusterDriverInterface.php         # Orchestrator abstraction interface
│   ├── Drivers/
│   │   └── SwarmClusterDriver.php             # Docker Swarm driver implementation
│   ├── Policies/                              # Laravel policies (override Coolify's)
│   │   ├── ApplicationPolicy.php
│   │   ├── DatabasePolicy.php
│   │   ├── EnvironmentPolicy.php
│   │   ├── EnvironmentVariablePolicy.php
│   │   ├── ProjectPolicy.php
│   │   ├── ServerPolicy.php
│   │   ├── ServicePolicy.php
│   │   ├── NetworkPolicy.php
│   │   └── ClusterPolicy.php
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
│   │   ├── Livewire/Project/Service/
│   │   │   └── Index.php                      # Multi-port proxy support
│   │   ├── Views/
│   │   │   ├── livewire/storage/
│   │   │   │   └── show.blade.php             # Storage page with encryption form
│   │   │   ├── livewire/settings-backup.blade.php   # Settings backup with instance file backup
│   │   │   ├── livewire/project/application/
│   │   │   │   └── configuration.blade.php    # App config + Resource Backups sidebar
│   │   │   ├── livewire/project/database/
│   │   │   │   └── configuration.blade.php    # DB config + Resource Backups sidebar
│   │   │   ├── livewire/project/service/
│   │   │   │   ├── index.blade.php            # Service DB view with multi-port proxy UI
│   │   │   │   └── configuration.blade.php    # Service config + Resource Backups sidebar
│   │   │   ├── livewire/project/new/
│   │   │   │   └── select.blade.php         # New Resource page + custom source labels
│   │   │   ├── components/settings/
│   │   │   │   └── navbar.blade.php          # Settings navbar + Restore/Templates/Networks/Appearance tabs
│   │   │   ├── layouts/
│   │   │   │   └── base.blade.php            # Base layout + conditional enhanced theme link/script
│   │   │   └── components/server/
│   │   │       └── sidebar.blade.php          # Server sidebar + Resource Backups + Networks items
│   │   └── Helpers/
│   │       ├── constants.php                   # Expanded DATABASE_DOCKER_IMAGES
│   │       ├── databases.php                  # Encryption-aware S3 delete
│   │       ├── shared.php                     # Custom templates + proxy network labels
│   │       ├── proxy.php                      # Proxy network isolation (Phase 2)
│   │       └── docker.php                     # Traefik/Caddy proxy label injection (Phase 2)
│   ├── Jobs/
│   │   ├── ResourceBackupJob.php              # Volume/config/full backup job
│   │   ├── SyncTemplateSourceJob.php          # Background GitHub template sync
│   │   ├── NetworkReconcileJob.php            # Post-deploy network reconciliation
│   │   ├── ProxyMigrationJob.php             # Proxy isolation migration for existing servers
│   │   ├── ClusterSyncJob.php                # Background cluster metadata sync
│   │   └── ClusterEventCollectorJob.php      # Event stream collection
│   ├── Http/
│   │   ├── Controllers/Api/                   # API controllers
│   │   │   ├── CustomTemplateSourceController.php # Template source management API
│   │   │   ├── PermissionsController.php      # Permission management API
│   │   │   ├── ResourceBackupController.php   # Resource backup API
│   │   │   ├── NetworkController.php          # Network management API
│   │   │   └── ClusterController.php         # Cluster management API
│   │   └── Middleware/
│   │       └── InjectPermissionsUI.php        # UI injection middleware
│   └── Livewire/
│       ├── AccessMatrix.php                   # Access matrix component
│       ├── StorageEncryptionForm.php          # S3 path prefix + encryption settings
│       ├── ResourceBackupManager.php          # Resource backup management UI
│       ├── ResourceBackupPage.php             # Server backup page component
│       ├── RestoreBackup.php                  # Settings restore/import page
│       ├── CustomTemplateSources.php          # Custom template sources management
│       ├── NetworkManager.php                 # Server-level network management
│       ├── NetworkManagerPage.php             # Server networks full-page wrapper
│       ├── ResourceNetworks.php               # Per-resource network assignment
│       ├── NetworkSettings.php                # Settings page for network policies
│       ├── ClusterList.php                    # Cluster listing page
│       ├── ClusterDashboard.php               # Cluster dashboard with tabs
│       ├── ClusterNodeManager.php             # Node management with actions
│       ├── ClusterAddNode.php                 # Add node wizard
│       ├── ClusterServiceViewer.php           # Service/task viewer
│       ├── ClusterVisualizer.php              # Dual-view cluster visualizer
│       ├── ClusterEvents.php                  # Event log viewer
│       ├── ClusterSecrets.php                 # Swarm secrets CRUD
│       ├── ClusterConfigs.php                 # Swarm configs CRUD
│       ├── SwarmConfigForm.php                # Structured Swarm deployment config
│       └── SwarmTaskStatus.php                # Inline task status for resources
├── database/migrations/                        # Database migrations
├── resources/views/livewire/
│   ├── access-matrix.blade.php                # Matrix table view
│   ├── appearance-settings.blade.php          # Settings > Appearance (enhanced theme toggle)
│   ├── storage-encryption-form.blade.php      # Path prefix + encryption form view
│   ├── resource-backup-manager.blade.php      # Resource backup management view
│   ├── resource-backup-page.blade.php         # Full-page backup view
│   ├── restore-backup.blade.php              # Restore/import backup view
│   ├── custom-template-sources.blade.php     # Template sources management view
│   ├── network-manager.blade.php             # Server network management view
│   ├── network-manager-page.blade.php        # Server networks full-page view
│   ├── resource-networks.blade.php           # Per-resource network assignment view
│   ├── network-settings.blade.php            # Network policies settings view
│   ├── cluster-list.blade.php               # Cluster listing page view
│   ├── cluster-dashboard.blade.php          # Cluster dashboard with tabs view
│   ├── cluster-node-manager.blade.php       # Node management view
│   ├── cluster-add-node.blade.php           # Add node wizard view
│   ├── cluster-service-viewer.blade.php     # Service/task viewer view
│   ├── cluster-visualizer.blade.php         # Dual-view visualizer
│   ├── cluster-events.blade.php             # Event log view
│   ├── cluster-secrets.blade.php            # Secrets management view
│   ├── cluster-configs.blade.php            # Configs management view
│   ├── swarm-config-form.blade.php          # Structured Swarm config form
│   └── swarm-task-status.blade.php          # Inline task status view
├── resources/assets/
│   └── theme.css                               # Enhanced UI theme (scoped; light + dark)
├── routes/                                     # API and web routes
├── config/                                     # Package configuration
├── docker/                                     # Docker build files
├── docs/
│   ├── custom-templates.md                    # Custom template creation guide
│   ├── features/                              # Per-feature documentation
│   │   └── enhanced-database-classification/  # Database classification + multi-port proxy
│   │       ├── PRD.md                         # Product Requirements Document
│   │       ├── plan.md                        # Technical implementation plan
│   │       └── README.md                      # Feature overview
│   │   └── mcp-server/                        # MCP server feature documentation
│   │       ├── PRD.md                         # Product Requirements Document
│   │       ├── plan.md                        # Technical implementation plan
│   │       └── README.md                      # Feature overview
│   ├── examples/
│   │   └── whoami.yaml                        # Example custom template
│   └── coolify-source/                        # Cloned Coolify source (gitignored)
├── mcp-server/                                 # MCP server (TypeScript/Node.js)
│   ├── package.json                            # npm package config
│   ├── tsconfig.json                           # TypeScript config
│   ├── README.md                               # MCP server documentation
│   ├── bin/cli.ts                              # CLI entry point
│   ├── src/
│   │   ├── index.ts                            # Main entry point
│   │   ├── lib/
│   │   │   ├── coolify-client.ts               # HTTP API client
│   │   │   ├── mcp-server.ts                  # Server assembly + tool registration
│   │   │   └── types.ts                       # TypeScript type definitions
│   │   └── tools/
│   │       ├── servers.ts                      # Server management tools (8)
│   │       ├── projects.ts                     # Project & environment tools (9)
│   │       ├── applications.ts                # Application tools (10)
│   │       ├── databases.ts                   # Database tools (8)
│   │       ├── services.ts                    # Service tools (8)
│   │       ├── deployments.ts                 # Deployment tools (4)
│   │       ├── environment-variables.ts       # Env var tools (10)
│   │       ├── database-backups.ts            # DB backup tools (5)
│   │       ├── security.ts                    # Private keys + teams tools (7)
│   │       ├── system.ts                      # Version, health, resources (3)
│   │       ├── permissions.ts                 # [Enhanced] Permission tools (5)
│   │       ├── resource-backups.ts            # [Enhanced] Resource backup tools (5)
│   │       ├── templates.ts                  # [Enhanced] Custom template tools (7)
│   │       └── networks.ts                   # [Enhanced] Network management tools (10)
│   └── __tests__/                              # Test files
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
| `src/Overrides/Models/ServiceDatabase.php` | Wire-compatible database type mappings + multi-port proxy support |
| `src/Overrides/Livewire/Project/Service/Index.php` | Service DB view overlay with multi-port proxy logic |
| `src/Overrides/Views/livewire/project/service/index.blade.php` | Service DB view with multi-port proxy UI |
| `src/Overrides/Livewire/Project/Database/Import.php` | Encryption-aware restore overlay |
| `src/Overrides/Helpers/constants.php` | Expanded DATABASE_DOCKER_IMAGES with 50+ additional database images |
| `src/Overrides/Helpers/databases.php` | Encryption-aware S3 delete overlay |
| `src/Overrides/Views/livewire/storage/show.blade.php` | Storage page with encryption form |
| `src/Livewire/ResourceBackupPage.php` | Server resource backups page component |
| `src/Livewire/RestoreBackup.php` | Settings restore/import page with env var bulk import |
| `src/Overrides/Views/components/settings/navbar.blade.php` | Settings navbar with Restore + Templates + Appearance tabs |
| `src/Overrides/Views/layouts/base.blade.php` | Base layout overlay; injects theme CSS/script when enhanced theme enabled |
| `src/Models/EnhancedUiSettings.php` | Key-value UI settings (enhanced_theme_enabled) |
| `src/Livewire/AppearanceSettings.php` | Settings > Appearance (enhanced theme toggle) |
| `resources/assets/theme.css` | Enhanced UI theme (scoped; light + dark) |
| `src/Overrides/Views/livewire/project/new/select.blade.php` | New Resource page with source labels, source filter, untested badges |
| `src/Overrides/Helpers/shared.php` | Override get_service_templates() to merge custom + ignored templates |
| `src/Services/TemplateSourceService.php` | GitHub API fetch, YAML parsing, template caching |
| `src/Models/CustomTemplateSource.php` | Custom template source model (repo URL, auth, cache) |
| `src/Livewire/CustomTemplateSources.php` | Settings page for managing template sources |
| `src/Jobs/SyncTemplateSourceJob.php` | Background job for syncing templates from GitHub |
| `src/Http/Controllers/Api/CustomTemplateSourceController.php` | REST API for template sources |
| `src/Overrides/Helpers/proxy.php` | Proxy network isolation in proxy compose + connectivity |
| `src/Overrides/Helpers/docker.php` | Traefik/Caddy proxy network label injection |
| `src/Jobs/ProxyMigrationJob.php` | Proxy isolation migration for existing servers |
| `src/Services/NetworkService.php` | Docker network operations, reconciliation, proxy isolation |
| `src/Models/ManagedNetwork.php` | Docker network model (env, shared, proxy, system scopes) |
| `src/Models/ResourceNetwork.php` | Polymorphic pivot: resource-to-network membership |
| `src/Jobs/NetworkReconcileJob.php` | Post-deployment network assignment job |
| `src/Policies/NetworkPolicy.php` | Permission policy for managed networks |
| `src/Livewire/NetworkManager.php` | Server-level network management UI |
| `src/Livewire/NetworkManagerPage.php` | Server networks full-page wrapper |
| `src/Livewire/ResourceNetworks.php` | Per-resource network assignment UI |
| `src/Livewire/NetworkSettings.php` | Settings page for network policies |
| `src/Http/Controllers/Api/NetworkController.php` | REST API for network management |
| `src/Http/Middleware/InjectPermissionsUI.php` | Injects access matrix into team admin page |
| `src/Models/ProjectUser.php` | Permission levels and helpers |
| `config/coolify-enhanced.php` | Configuration options |
| `docs/coolify-source/` | Coolify source reference (gitignored) |
| `docker/Dockerfile` | Custom Coolify image build |
| `docker/docker-compose.custom.yml` | Compose override template |
| `install.sh` | Setup script (menu + CLI args) |
| `uninstall.sh` | Standalone uninstall script |
| `mcp-server/src/index.ts` | MCP server entry point |
| `mcp-server/src/lib/coolify-client.ts` | HTTP API client for Coolify + enhanced endpoints |
| `mcp-server/src/lib/mcp-server.ts` | MCP server assembly with conditional tool registration |
| `mcp-server/src/lib/types.ts` | TypeScript type definitions for all API types |
| `mcp-server/src/tools/*.ts` | 14 tool modules (99 tools total) |
| `mcp-server/package.json` | npm package: @amirhmoradi/coolify-enhanced-mcp |
| `src/Contracts/ClusterDriverInterface.php` | Orchestrator abstraction interface (K8s-ready) |
| `src/Drivers/SwarmClusterDriver.php` | Docker Swarm driver: SSH-based Docker CLI execution |
| `src/Models/Cluster.php` | Cluster entity: uuid, name, type, status, settings, metadata |
| `src/Services/ClusterDetectionService.php` | Auto-detect Swarm clusters from manager servers |
| `src/Jobs/ClusterSyncJob.php` | Periodic cluster metadata refresh |
| `src/Jobs/ClusterEventCollectorJob.php` | Docker event stream collection |
| `src/Livewire/ClusterDashboard.php` | Main cluster dashboard with tabs |
| `src/Livewire/ClusterVisualizer.php` | Dual-view visualizer (grid + topology) |
| `src/Livewire/ClusterNodeManager.php` | Node management with drain/promote/label actions |
| `src/Livewire/SwarmConfigForm.php` | Structured Swarm deployment config (replaces YAML textarea) |
| `src/Http/Controllers/Api/ClusterController.php` | Cluster management REST API |
| `src/Policies/ClusterPolicy.php` | Cluster access policy (team-scoped) |

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

# MCP Server development
cd mcp-server && npm install && npm run build
cd mcp-server && npm run dev  # Watch mode

# Run MCP server locally
COOLIFY_BASE_URL=https://coolify.example.com COOLIFY_ACCESS_TOKEN=token node mcp-server/dist/src/index.js
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
  - **Service Index** (`project/service/index.blade.php`) — multi-port proxy UI for ServiceDatabase with `coolify.proxyPorts` label
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
32. **Custom template logo paths are from repo root** — The `logo` metadata header is resolved relative to the **repository root** (e.g. `logo: svgs/myservice.svg` → raw URL at `{rawBase}/svgs/myservice.svg`), not the template folder, so SVG/icons at repo root display correctly in the template list.
33. **Ignored/untested templates (`_ignored` flag)** — Coolify's `generate:services` command skips templates with `# ignore: true`, so they never appear in the JSON. The shared.php overlay loads these directly from YAML files on disk (`templates/compose/*.yaml`) and includes them with `_ignored: true`. Custom templates from `TemplateSourceService` also preserve `_ignored` instead of skipping. The select.blade.php overlay shows an amber "Untested" badge and requires user confirmation via `confirm()` before proceeding.
34. **Doc icon stacking with badges** — When a service card has both `_source` and `_ignored` badges, the doc icon shifts down further (`top: 2.25rem`). With only one badge, it shifts to `top: 1.25rem`. The `_ignored` badge itself shifts down (`top: 1.05rem`) when a `_source` label is also present.
35. **Source filter dropdown** — The New Resource page has a "Filter by source" dropdown next to the category filter. Uses `selectedSource` state: empty string = all, `__official__` = built-in only, or a specific source name. The dropdown only appears when `sources.length > 0`. Sources are extracted from `_source` fields after `loadServices()`.
36. **`isDatabaseImageEnhanced()` wrapper** — Defined in `shared.php` overlay, not in `docker.php`. Checks `coolify.database` label in both map format (`coolify.database: "true"`) and array format (`- coolify.database=true`) before delegating to Coolify's `isDatabaseImage()`. Only covers the 2 call sites in shared.php (service import + deployment), not the 4 in parsers.php (application deployments). This is intentional: parsers.php calls handle Application compose, not Service templates.
37. **`constants.php` overlay maintenance** — Keep the expanded `DATABASE_DOCKER_IMAGES` list in sync with Coolify upstream. The overlay is a full copy of the original file with additional entries grouped by database category. New entries should be added to the appropriate category section.
38. **`# type: database` injects labels into compose** — The comment header modifies the actual YAML (adds `coolify.database` label to all services), which is then base64-encoded. This means the label persists into `docker_compose_raw` in the DB, ensuring classification survives re-parses. Per-service labels take precedence over the template-level `# type:` header.
39. **Label check is case-insensitive** — `isDatabaseImageEnhanced()` lowercases the label key before matching. Boolean parsing uses PHP's `filter_var(FILTER_VALIDATE_BOOLEAN)`, which accepts `true/false/1/0/yes/no/on/off`.
40. **StartDatabaseProxy port resolution** — The overlay first tries Coolify's built-in match, then looks up the base image name in `DATABASE_PORT_MAP`, then tries partial string matching, then extracts port from the service's compose config. Only throws if all methods fail. The error message guides users to set `custom_type`.
41. **`ServiceDatabase::databaseType()` includes full image path** — For `memgraph/memgraph-mage`, it returns `standalone-memgraph/memgraph-mage` (not just `standalone-memgraph-mage`). The port resolution in `StartDatabaseProxy` handles this by extracting the base name via `afterLast('/')`.
42. **DatabaseBackupJob unsupported types** — Dump-based backups only work for postgres, mysql, mariadb, and mongodb. For other ServiceDatabase types (memgraph, redis, clickhouse, etc.), the job now throws a meaningful exception instead of silently returning. Users should either set `custom_type` (if the DB is wire-compatible) or use Resource Backups for volume-level backups.
43. **Wire-compatible mapping is conservative** — Only databases where standard dump tools produce CORRECT backups are mapped: YugabyteDB (pg_dump), TiDB (mysqldump), FerretDB (mongodump), Percona (mysqldump), Apache AGE (pg_dump). CockroachDB is NOT mapped despite speaking pgwire because `pg_dump` fails on its catalog functions. Vitess is NOT mapped because `mysqldump` needs extra flags and isn't reliable for sharded setups. For unmapped types, `isBackupSolutionAvailable()` correctly returns false. Users can set `custom_type` if they know their DB is compatible, or use Resource Backups.
44. **ServiceDatabase.php overlay maintenance** — The model is small (170 lines) but critical. The wire-compatible mappings in `databaseType()` use `$image->contains()` checks — be careful with substring false positives (e.g., `age` matching `garage` or `image` — the AGE check excludes these).
45. **parsers.php preserves existing records** — Even without our label check, `updateCompose()` in parsers.php first checks for existing ServiceApplication/ServiceDatabase records and preserves them. Re-classification only affects truly NEW services added during a compose update, and the expanded DATABASE_DOCKER_IMAGES handles most of those.
46. **Multi-port proxy label format** — `coolify.proxyPorts` label value is `"internalPort:label,internalPort:label,..."` (e.g., `"7687:bolt,7444:log-viewer"`). Parsed by `ServiceDatabase::parseProxyPortsLabel()`. The label name is case-sensitive (lowercase `coolify.proxyPorts`).
47. **Multi-port proxy coexistence** — When `coolify.proxyPorts` label is absent, the Service/Index.php overlay behaves identically to stock Coolify (single `is_public`/`public_port` toggle). The multi-port UI only appears when the label is detected in `docker_compose_raw`.
48. **Multi-port proxy_ports JSON schema** — `service_databases.proxy_ports` stores `{"7687": {"public_port": 17687, "label": "bolt", "enabled": true}, ...}`. Keys are internal port strings. Initialized from the label's defaults on first component mount.
49. **Service/Index.php overlay maintenance** — Full copy of Coolify's `app/Livewire/Project/Service/Index.php` (~560 lines). Multi-port additions are marked with `[MULTI-PORT PROXY OVERLAY]` comments. Must be kept in sync with upstream changes to the original component.
50. **Multi-port proxy nginx config** — `StartDatabaseProxy::handleMultiPort()` generates multiple `server` blocks in a single nginx `stream` context. Each server block listens on its own public port and proxy_passes to the container's internal port. All ports share one proxy container.
51. **Multi-port StopDatabaseProxy** — No changes needed. `StopDatabaseProxy` simply does `docker rm -f {uuid}-proxy`, which stops the single proxy container handling all ports.
52. **Network management uses post-deployment hooks, not overlays** — The `NetworkReconcileJob` runs after Coolify finishes its normal deployment. Containers are connected to managed networks via `docker network connect`. This avoids overlaying `ApplicationDeploymentJob.php` (4130 lines, 16+ network references).
53. **Network race conditions** — Docker's `network create` and `network connect` are idempotent with `2>/dev/null || true`. DB unique constraints on `(docker_network_name, server_id)` handle concurrent `firstOrCreate` calls. The second caller catches the unique violation and fetches the existing record.
54. **Strict isolation disconnects from `coolify` network** — In `strict` mode, resources are disconnected from the default `coolify` Docker network (standalone) or `coolify-overlay` (Swarm) after being connected to their environment network. This breaks services that rely on the default network for inter-container communication. Only use if all services are properly assigned to managed networks.
55. **Network event listeners** — Applications use `ApplicationDeploymentQueue::updated()` model observer (NOT the `ApplicationStatusChanged` event). Services and databases use `ServiceStatusChanged` and `DatabaseStatusChanged` event listeners with team-based lookup. If Coolify changes event signatures or adds new events, update `registerNetworkManagement()` in the service provider.
56. **Shared networks are for cross-environment communication** — Resources from different environments cannot communicate by default (separate bridge networks). Users must create shared networks and manually attach resources to enable cross-env connectivity.
57. **Network limit per server** — Default 200. Docker bridge networks consume iptables rules (~10 per network). At 200+ networks, `iptables -L` performance degrades. The limit is configurable via `COOLIFY_MAX_NETWORKS` env var.
58. **PR preview deployments do NOT auto-join environment networks** — Preview deploys create their own `{app_uuid}-{pr_id}` network. The reconcile job only triggers for the main resource, not PR previews. This is intentional: previews should not access production databases.
59. **`traefik.docker.network` is NOT optional for multi-network setups** — Without it, Traefik randomly selects which network IP to route to, causing intermittent 502 errors that depend on Docker's internal network ordering (changes on restart). Phase 2's proxy isolation overlay injects this label automatically.
60. **Proxy network label is dynamic, not DB-stored** — The proxy network name is resolved at label generation time from `ManagedNetwork` table, not stored in the resource's `custom_labels`. This prevents stale labels when resources move between servers.
61. **proxy.php overlay covers 4 functions** — `connectProxyToNetworks()`, `collectDockerNetworksByServer()`, `generateDefaultProxyConfiguration()`, `ensureProxyNetworksExist()`. Each has a `[PROXY ISOLATION OVERLAY]` marked block guarded by dual config check (`proxy_isolation` + `enabled`).
62. **docker.php overlay covers 3 functions** — `fqdnLabelsForTraefik()` (+optional `$proxyNetwork` param), `fqdnLabelsForCaddy()` (+optional `$proxyNetwork` param), `generateLabelsApplication()` (+proxy network resolution). All marked with `[PROXY ISOLATION OVERLAY]`.
63. **parsers.php is NOT overlaid** — Its 8 `fqdnLabelsFor*` calls don't pass `proxyNetwork`. Post-deployment reconciliation via `NetworkReconcileJob` ensures containers join the proxy network anyway.
64. **Proxy migration is a 2-step process** — Step 1: Run "Proxy Migration" (creates network, connects proxy + FQDN resources). Step 2: After ALL resources redeployed, optionally "Cleanup Old Networks" to disconnect proxy from non-proxy networks.
65. **Default network kept during migration** — `connectProxyToNetworks()` always includes the default `coolify`/`coolify-overlay` network alongside proxy networks, ensuring backward compatibility until migration is complete.
66. **Coolify events don't carry resources** — `ApplicationStatusChanged($teamId)`, `ServiceStatusChanged($teamId)`, and `DatabaseStatusChanged($userId)` only carry team/user IDs, NOT the actual resource. The event listener fix uses `ApplicationDeploymentQueue::updated()` for precise application reconciliation, and team-based lookup for services/databases.
67. **Swarm uses `docker service update --network-add`** — Unlike standalone Docker where `docker network connect` works on running containers, Swarm tasks cannot be directly connected to networks. The `docker service update --network-add` command modifies the service spec, triggering a zero-downtime rolling update.
68. **Overlay networks require manager node** — Network creation (`docker network create --driver overlay`) must be executed on a Swarm manager node. `isSwarmManager()` checks this.
69. **`resolveNetworkDriver()` auto-detects driver** — All `ensure*Network()` methods use `resolveNetworkDriver($server)` to return `overlay` for Swarm, `bridge` for standalone. No hardcoded driver values.
70. **Swarm service name discovery** — `getSwarmServiceNames()` uses `docker service ls` with `--filter` to discover service names. Falls back to UUID-based name matching.
71. **Batched Swarm network changes** — `updateSwarmServiceNetworks()` combines multiple `--network-add` and `--network-rm` flags into a single `docker service update` command, triggering only one rolling update per service.
72. **Overlay encryption adds IPsec overhead** — `--opt encrypted` enables IPsec encryption between Swarm nodes (~5-10% overhead). Configured via `COOLIFY_SWARM_OVERLAY_ENCRYPTION=true`. Applied during network creation, not retroactively.
73. **Strict mode uses `coolify-overlay` for Swarm** — When disconnecting from the default network in strict mode, use `coolify-overlay` (Swarm) instead of `coolify` (standalone). `getDefaultNetworkName()` handles this.
74. **Phase 3 adds zero new overlay files** — All Swarm support is in `NetworkService.php`, `NetworkReconcileJob.php`, and updates to existing overlays. The `proxy.php` overlay's Swarm branches were updated but no new overlays were created.
75. **MCP server is standalone TypeScript** — Lives in `mcp-server/` directory, completely independent of the Laravel package. Does not need to run on the Coolify server — runs on the user's workstation alongside their AI client.
76. **MCP tool annotations are flat** — The `@modelcontextprotocol/sdk` v1.26 expects `{ readOnlyHint: true }` as direct properties on the annotations parameter, NOT nested under `{ annotations: { ... } }`.
77. **MCP enhanced feature detection** — The server probes `GET /api/v1/resource-backups` on startup. 200 or 401/403 means enhanced is available (endpoint exists). 404 means standard Coolify.
78. **MCP server stderr for logging** — MCP servers must use `console.error()` for logging because `console.log()` / stdout is reserved for the JSON-RPC protocol communication.
79. **MCP CoolifyClient health check path** — The health endpoint is at `/health` (not `/api/v1/health`). The client uses `/../health` relative path to escape the `/api/v1` prefix.
80. **Cluster auto-detection uses `docker info --format json`** — The Swarm ID from `docker info` is used to match/create Cluster records. Worker nodes are auto-linked by IP matching against known Coolify Server records.
81. **ClusterDriverInterface is the K8s abstraction** — All UI and business logic goes through this interface. Never call Docker commands directly from Livewire components; always go through the driver.
82. **Cluster data is cached aggressively** — Default 30s TTL for node/service lists, 60s for cluster info. Write operations (drain, scale) must explicitly invalidate cache via `Cache::forget("cluster:{id}:{resource}")`.
83. **escapeshellarg() on ALL Docker CLI arguments** — Node IDs, service IDs, label keys/values — everything interpolated into shell commands MUST be escaped. The SwarmClusterDriver has a private `escape()` helper.
84. **Join tokens are stored encrypted** — Cluster settings use `'encrypted:array'` cast. Tokens are never exposed in API responses unless explicitly requested by admin users.
85. **Coolify's existing `swarm_cluster` integer field coexists** — We add a proper `cluster_id` FK to servers. The old integer field is not removed or migrated to avoid breaking Coolify core.
86. **Phase 1 requires ZERO overlay files** — Full cluster visibility (dashboard, nodes, services, visualizer) with no Coolify file modifications. Phase 2 adds ONE overlay (swarm.blade.php) for the structured config form.
87. **`instant_remote_process()` is Coolify's SSH executor** — All Docker commands go through this function. It manages SSH connections, timeouts, and error handling. Never use `ssh` directly.
88. **Swarm secrets are immutable in Docker** — "Rotating" a secret means: create new secret, update all referencing services, remove old secret. This is a multi-step operation, not a simple update.
89. **`docker system events` has time bounds** — Always use `--since` and `--until` to bound event queries. Without `--until`, the command blocks indefinitely waiting for new events.
90. **Swarm node inspect returns NanoCPUs** — CPU count from `docker node inspect` is in nanoseconds (1 CPU = 1e9 NanoCPUs). Divide by 1e9 for core count.
91. **Enhanced UI theme only applies when enabled** — Theme CSS and `data-ce-theme="enhanced"` are injected only when `enhanced_theme_enabled()` returns true (package enabled + DB/config fallback). Base layout overlay is a full copy of Coolify's base; keep in sync with upstream when Coolify changes layouts. Keep Appearance tab visibility owner/admin-only to match route/component authorization, and keep palette changes token-driven (`--ce-*` in `theme.css`) to avoid brittle selector churn.
92. **Cluster web routes must precede Coolify's catch-all** — Coolify's `routes/web.php` ends with `Route::any('/{any}', ...)` which redirects to HOME. If package web routes are registered after that (e.g. in `boot()` after `RouteServiceProvider::boot()`), `GET /clusters` and `GET /cluster/{uuid}` match the catch-all and cause "too many redirects". Web routes are loaded in `register()` when enabled so they are registered before any provider's `boot()`. See `docs/features/cluster-management/REDIRECT_LOOP_INVESTIGATION.md`.

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
11. **Network management** - Per-environment Docker network isolation via `COOLIFY_NETWORK_MANAGEMENT=true`. Modes: `none`, `environment`, `strict`. Proxy isolation via `COOLIFY_PROXY_ISOLATION=true`
12. **Phase 1 has zero overlays** - Environment isolation uses post-deployment hooks + Docker API. No overlay files required
13. **Phase 2 adds two overlays** - `proxy.php` (proxy compose isolation) + `docker.php` (Traefik/Caddy label injection). The existing `shared.php` overlay is also updated for service labels
14. **Proxy migration** - After enabling `COOLIFY_PROXY_ISOLATION=true`, run "Proxy Migration" from Server > Networks page. All FQDN resources join the proxy network; new deployments auto-join
15. **Phase 3 adds Swarm support** - Automatic overlay networks for Swarm servers, `docker service update --network-add` for task network assignment, optional IPsec encryption via `COOLIFY_SWARM_OVERLAY_ENCRYPTION=true`
16. **Swarm service updates cause rolling restarts** - `docker service update --network-add` triggers a rolling update of service tasks. This is a zero-downtime operation but takes ~10-30s per service
17. **MCP server** - Standalone TypeScript MCP server in `mcp-server/`. Wraps all ~105 Coolify API endpoints + coolify-enhanced endpoints as MCP tools. Published as `@amirhmoradi/coolify-enhanced-mcp`
18. **MCP auto-detection** - Enhanced tools are auto-registered when coolify-enhanced API is detected, or forced via `COOLIFY_ENHANCED=true` env var
19. **MCP works with standard Coolify** - Core tools (72 tools) work with any Coolify v4 instance. Enhanced tools (27 tools) require the coolify-enhanced addon
20. **Cluster management** - Docker Swarm cluster dashboard, node management, service/task monitoring, visualizer via `COOLIFY_CLUSTER_MANAGEMENT=true`
21. **Cluster management is phased** - Phase 1: read-only visibility (zero overlays). Phase 2: write operations (one overlay). Phase 3: secrets/configs. Phase 4: integration + MCP
22. **K8s-ready architecture** - `ClusterDriverInterface` contract decouples UI from orchestrator. Implement `KubernetesClusterDriver` to add K8s support without touching any UI code
23. **Enhanced UI theme** - Optional corporate-grade theme (CSS + minimal JS only) via Settings > Appearance; disabled by default; preference in `enhanced_ui_settings` table

## See Also

- [AGENTS.md](AGENTS.md) - Detailed AI agent instructions
- [docs/custom-templates.md](docs/custom-templates.md) - Custom template creation guide
- [docs/features/](docs/features/) - Per-feature documentation (PRD, plan, README)
- [docs/features/mcp-server/](docs/features/mcp-server/) - MCP server feature documentation
- [docs/features/cluster-management/](docs/features/cluster-management/) - Cluster management feature documentation (PRD, plan, README)
- [docs/features/enhanced-ui-theme/](docs/features/enhanced-ui-theme/) - Enhanced UI theme (PRD, plan, README)
- [mcp-server/README.md](mcp-server/README.md) - MCP server usage and tool reference
- [docs/coolify-source/](docs/coolify-source/) - Coolify source code reference
- [docs/architecture.md](docs/architecture.md) - Architecture details
- [docs/api.md](docs/api.md) - API documentation
- [docs/installation.md](docs/installation.md) - Installation guide

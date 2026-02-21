# Architecture

This document describes the architecture of the Coolify Enhanced package.

## Overview

The package extends Coolify's authorization system and backup pipeline without modifying core files. It uses Laravel's service provider system to:

1. Override default policies with permission-aware versions
2. Add new database tables for permission storage and encryption settings
3. Provide UI components for permission management and encryption configuration
4. Expose API endpoints for programmatic access
5. Overlay modified Coolify files for encryption-aware backup/restore/delete

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
│  │                  Coolify Enhanced Package                │   │
│  │                                                          │   │
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
│  │  │              RcloneService                          │ │   │
│  │  │  - isEncryptionEnabled()                            │ │   │
│  │  │  - obscurePassword()                                │ │   │
│  │  │  - buildUploadCommands()                            │ │   │
│  │  │  - buildDownloadCommands()                          │ │   │
│  │  │  - buildDeleteCommands()                            │ │   │
│  │  └────────────────────────┬───────────────────────────┘ │   │
│  │                           │                              │   │
│  │  ┌────────────────────────▼───────────────────────────┐ │   │
│  │  │              Database Tables                        │ │   │
│  │  │  - project_user (project access)                    │ │   │
│  │  │  - environment_user (environment overrides)         │ │   │
│  │  │  - s3_storages (encryption columns added)           │ │   │
│  │  │  - scheduled_database_backup_executions             │ │   │
│  │  │    (is_encrypted column added)                      │ │   │
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

## Encrypted Backup Flow

### Upload (Backup)

```
DatabaseBackupJob::upload_to_s3()
        │
        ▼
  S3Storage has encryption enabled?
        │
        ├── no ──► upload_to_s3_unencrypted() [original mc behavior]
        │
        └── yes ──► upload_to_s3_encrypted()
                         │
                         ▼
                   RcloneService::buildUploadCommands()
                         │
                         ├── Build base64 env file (S3 + crypt config)
                         ├── Write env file to server
                         ├── Docker run rclone/rclone with --env-file
                         ├── rclone copy local:backup encrypted:path
                         ├── Mark backup execution as is_encrypted=true
                         └── Cleanup env file + container
```

### Download (Restore)

```
Import::restoreFromS3()
        │
        ▼
  Storage has encryption?
        │
        ├── no ──► restoreFromS3Unencrypted() [original mc behavior]
        │
        └── yes ──► restoreFromS3Encrypted()
                         │
                         ▼
                   RcloneService::buildDownloadCommands()
                         │
                         ├── Build env file, write to server
                         ├── Docker run rclone copy encrypted:file local:dest
                         └── Cleanup
```

### Delete (Cleanup)

```
deleteBackupsS3()
        │
        ▼
  S3Storage has filename encryption?
        │
        ├── no ──► Laravel Storage S3 driver delete [original behavior]
        │
        └── yes ──► RcloneService::buildDeleteCommands()
                         │
                         ├── Build env file, write to server
                         ├── Docker run rclone deletefile encrypted:path
                         └── Cleanup
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

### s3_storages Table (Added Columns)

```sql
ALTER TABLE s3_storages ADD COLUMN encryption_enabled BOOLEAN DEFAULT false;
ALTER TABLE s3_storages ADD COLUMN encryption_password LONGTEXT NULL;
ALTER TABLE s3_storages ADD COLUMN encryption_salt LONGTEXT NULL;
ALTER TABLE s3_storages ADD COLUMN filename_encryption VARCHAR(255) DEFAULT 'off';
ALTER TABLE s3_storages ADD COLUMN directory_name_encryption BOOLEAN DEFAULT false;
```

### scheduled_database_backup_executions Table (Added Column)

```sql
ALTER TABLE scheduled_database_backup_executions ADD COLUMN is_encrypted BOOLEAN DEFAULT false;
```

## Component Architecture

### Service Provider Lifecycle

```
Application Boot
      │
      ▼
CoolifyEnhancedServiceProvider::register()
      │
      ├── Merge configuration
      │
      ▼
CoolifyEnhancedServiceProvider::boot()
      │
      ├── Load migrations
      ├── Load views with namespace
      ├── Load API routes
      ├── Register Livewire components (AccessMatrix, StorageEncryptionForm)
      ├── Register InjectPermissionsUI middleware
      ├── Register global scopes
      └── Schedule booted callback:
            ├── Override policies via Gate::policy()
            ├── Register User macros
            └── Extend S3Storage model
```

### UI Injection Mechanism

The package injects its UI components into Coolify's existing pages using HTTP middleware:

```
HTTP Request
      │
      ▼
InjectPermissionsUI middleware
      │
      ├── Is HTML response? ── No → Pass through
      │ Yes
      ├── Is authenticated? ── No → Pass through
      │ Yes
      ├── Is /team/admin page AND user is admin/owner?
      │     ├── Yes → Render AccessMatrix, inject before </body>
      │     └── No → Skip
      ├── Is /storages/{uuid} page?
      │     ├── Yes → Render StorageEncryptionForm, inject before </body>
      │     └── No → Skip
      └── Positioning scripts move components to correct DOM locations
```

### Policy Override Mechanism

The package uses Laravel's `Gate::policy()` to override Coolify's default policies:

```php
// In CoolifyEnhancedServiceProvider::registerPolicies()
Gate::policy(Application::class, ApplicationPolicy::class);
Gate::policy(Project::class, ProjectPolicy::class);
// ... etc
```

This ensures that whenever Coolify checks authorization, our permission-aware policies are used instead.

### Rclone Encryption Mechanism

The encryption uses rclone's crypt backend with environment-variable configuration:

```
RCLONE_CONFIG_S3REMOTE_TYPE=s3
RCLONE_CONFIG_S3REMOTE_PROVIDER=Other
RCLONE_CONFIG_S3REMOTE_ACCESS_KEY_ID=...
RCLONE_CONFIG_S3REMOTE_SECRET_ACCESS_KEY=...
RCLONE_CONFIG_S3REMOTE_ENDPOINT=...
RCLONE_CONFIG_S3REMOTE_REGION=...

RCLONE_CONFIG_ENCRYPTED_TYPE=crypt
RCLONE_CONFIG_ENCRYPTED_REMOTE=s3remote:{bucket}
RCLONE_CONFIG_ENCRYPTED_PASSWORD={obscured_password}
RCLONE_CONFIG_ENCRYPTED_PASSWORD2={obscured_salt}  (optional)
RCLONE_CONFIG_ENCRYPTED_FILENAME_ENCRYPTION={off|standard|obfuscate}
RCLONE_CONFIG_ENCRYPTED_DIRECTORY_NAME_ENCRYPTION={true|false}
```

Password obscuring uses rclone's algorithm: AES-256-CTR with a fixed well-known key, base64url encoded.

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

## Installation & Deployment

### Install/Uninstall Scripts

The package includes automated scripts for managing installation:

```
install.sh
      │
      ├── Check prerequisites (root, Docker, Compose)
      ├── Detect Coolify at /data/coolify/source/
      ├── Pull GHCR image or build locally (--local)
      ├── Create docker-compose.custom.yml
      ├── Set COOLIFY_ENHANCED=true in .env
      ├── Run upgrade.sh to restart stack
      └── Verify installation

uninstall.sh
      │
      ├── Optionally clean database tables (prompted)
      ├── Remove docker-compose.custom.yml (with backup)
      ├── Remove env var from .env
      ├── Run upgrade.sh to restart stack
      └── Optionally remove local Docker images
```

### Docker Compose Custom File

Coolify natively supports `docker-compose.custom.yml` at `/data/coolify/source/`. This file is automatically merged with the main compose configuration and survives Coolify upgrades.

### Docker Image Build Process

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
   Overlay modified Coolify files:
   ├── DatabaseBackupJob.php → app/Jobs/
   ├── Import.php → app/Livewire/Project/Database/
   └── databases.php → bootstrap/helpers/
        │
        ▼
   Setup s6-overlay service
        │
        ▼
   composer dump-autoload
        │
        ▼
   Custom Coolify Image with Enhanced Features
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
5. **Encryption Layer**: NaCl SecretBox (XSalsa20 + Poly1305) for backups

### Role Bypass Security

Owner and Admin roles bypass all permission checks. This is intentional:

- Owners need full control to manage their team
- Admins are trusted team administrators
- Restricting them would break expected Coolify behavior

### Encryption Security

- Passwords are stored encrypted in the database (Laravel's `encrypted` cast pattern)
- Rclone env files are base64-encoded and cleaned up immediately after use
- Password obscuring uses rclone's standard algorithm (not for security, but for rclone compatibility)
- The actual encryption is NaCl SecretBox — industry-standard authenticated encryption

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

## MCP Server Architecture

The MCP (Model Context Protocol) server is a standalone TypeScript/Node.js application that wraps Coolify's REST API and coolify-enhanced's API endpoints, enabling AI assistants to manage infrastructure through natural language.

### High-Level Architecture

```
┌────────────────────────────────────────────────────────────┐
│  AI Client (Claude Desktop, Cursor, VS Code, Kiro IDE)      │
│                                                              │
│  User: "Deploy my app to production"                         │
│         ↓                                                    │
│  AI selects deploy tool → JSON-RPC request                   │
└──────────────────────────┬───────────────────────────────────┘
                           │ stdio (JSON-RPC)
┌──────────────────────────▼───────────────────────────────────┐
│              MCP Server (TypeScript/Node.js)                   │
│                                                                │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐    │
│  │  MCP Server   │    │  CoolifyClient│    │  Tool Modules │    │
│  │  Assembly     │    │  (HTTP)       │    │  (14 modules) │    │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘    │
│         │ registers tools   │ API calls         │ handlers   │
│         └───────────────────┼───────────────────┘            │
│                             │                                 │
└─────────────────────────────┼─────────────────────────────────┘
                              │ HTTPS (REST API)
┌─────────────────────────────▼─────────────────────────────────┐
│              Coolify Instance                                   │
│                                                                 │
│  ┌──────────────────┐    ┌──────────────────────────┐         │
│  │  Coolify v4 API   │    │  coolify-enhanced API     │         │
│  │  /api/v1/*        │    │  (permissions, backups,   │         │
│  │  (~105 endpoints) │    │   templates, networks)    │         │
│  └──────────────────┘    └──────────────────────────┘         │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Component Architecture

```
mcp-server/
├── src/
│   ├── index.ts                 # Entry point: config, feature detection, startup
│   ├── lib/
│   │   ├── coolify-client.ts    # HTTP client with retry, timeout, auth
│   │   ├── mcp-server.ts       # Server assembly, conditional tool registration
│   │   └── types.ts            # TypeScript interfaces for all API types
│   └── tools/                   # 14 tool modules (99 tools total)
│       ├── servers.ts           # 8 tools: CRUD + resources/domains/validate
│       ├── projects.ts          # 9 tools: project + environment CRUD
│       ├── applications.ts      # 10 tools: CRUD + start/stop/restart/logs/deploy
│       ├── databases.ts         # 8 tools: CRUD + start/stop/restart
│       ├── services.ts          # 8 tools: CRUD + start/stop/restart
│       ├── deployments.ts       # 4 tools: list, get, cancel, app history
│       ├── environment-variables.ts  # 10 tools: app + service env CRUD
│       ├── database-backups.ts  # 5 tools: backup config + executions
│       ├── security.ts          # 7 tools: private keys + teams
│       ├── system.ts            # 3 tools: version, health, resources
│       ├── permissions.ts       # 5 tools: [Enhanced] project access
│       ├── resource-backups.ts  # 5 tools: [Enhanced] backup schedules
│       ├── templates.ts         # 7 tools: [Enhanced] template sources
│       └── networks.ts          # 10 tools: [Enhanced] network management
└── bin/cli.ts                   # CLI entry point (shebang for npx)
```

### Startup Flow

```
npx @amirhmoradi/coolify-enhanced-mcp
      │
      ▼
index.ts::main()
      │
      ├── Read COOLIFY_BASE_URL, COOLIFY_ACCESS_TOKEN from env
      ├── Create CoolifyClient instance
      │
      ├── Enhanced detection:
      │     ├── COOLIFY_ENHANCED=true in env? → enhanced = true
      │     └── Otherwise: probe GET /api/v1/resource-backups
      │           ├── 200/401/403 → enhanced = true (endpoint exists)
      │           └── 404 / error → enhanced = false (standard Coolify)
      │
      ├── createMcpServer({ client, enhanced })
      │     ├── Register 10 core tool modules (72 tools)
      │     └── If enhanced: register 4 enhanced modules (+27 tools)
      │
      ├── Create StdioServerTransport
      └── server.connect(transport) → ready for JSON-RPC
```

### CoolifyClient Design

The `CoolifyClient` class is a single HTTP client handling all API communication:

- **Authentication**: Bearer token passed via `Authorization` header
- **Base URL**: Configurable, trailing slashes stripped
- **Timeout**: Default 30 seconds, configurable via `COOLIFY_MCP_TIMEOUT`
- **Retry**: Exponential backoff (2^attempt seconds, max 3 retries) for HTTP 429 and 5xx
- **Methods**: 60+ typed methods covering all Coolify native and enhanced endpoints
- **Health check**: Special handling for `/health` (not under `/api/v1`)

### Tool Registration Pattern

Each tool module exports a `register*Tools(server, client)` function:

```typescript
export function registerServerTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_servers",                              // Tool name
    "List all Coolify servers",                 // Description
    {},                                          // Input schema (Zod)
    {                                            // Annotations
      readOnlyHint: true,
      destructiveHint: false,
      idempotentHint: true,
      openWorldHint: false,
    },
    async () => {                                // Handler
      try {
        const result = await client.listServers();
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${error}` }], isError: true };
      }
    }
  );
}
```

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Standalone TypeScript** | MCP servers run as separate processes (stdio transport). No benefit to embedding in Laravel |
| **Single CoolifyClient** | Both native and enhanced APIs share auth, base URL, and response format |
| **Conditional tool registration** | Standard Coolify users get 72 core tools; enhanced users get all 99 |
| **Auto-detection** | Probes enhanced API on startup so users don't need manual configuration |
| **Tool annotations** | Help AI clients make safety decisions (confirm before destructive ops) |
| **stderr logging** | stdout is reserved for MCP JSON-RPC protocol; `console.error()` for all logs |
| **Zod schemas** | MCP SDK natively supports Zod; provides runtime validation + TypeScript types |
| **npx-first distribution** | Zero-config installation: `npx @amirhmoradi/coolify-enhanced-mcp` |

---

## Future Considerations

### Coolify v5 Migration

When Coolify v5 releases with built-in permission management:

1. Export permission data from package tables
2. Map to v5's permission structure
3. Import into v5's native system
4. Remove package

A migration script will be provided when v5's permission API is finalized.

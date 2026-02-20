# MCP Server for Coolify Enhanced — Product Requirements Document

## Problem Statement

Managing Coolify infrastructure through the web UI is manual and time-consuming. AI assistants (Claude Desktop, Cursor, VS Code Copilot, Kiro IDE) can automate infrastructure management through natural language, but need a structured interface to interact with Coolify's API. While the open-source `dazeb/coolify-mcp-enhanced` project provides a basic MCP server for vanilla Coolify, it does not support the enhanced features provided by the coolify-enhanced package (granular permissions, encrypted backups, resource backups, custom templates, network management, enhanced database classification).

## Goals

1. **Provide a comprehensive MCP server** that wraps all Coolify v4 API endpoints (~105 endpoints) for AI-assisted infrastructure management
2. **Expose coolify-enhanced features** as additional MCP tools — permissions management, resource backups, custom template sources, network management, and S3 encryption configuration
3. **Use `dazeb/coolify-mcp-enhanced` as architectural inspiration** — adopt its tool patterns, error handling approach, and transport mechanisms while building a cleaner, more maintainable implementation
4. **Follow MCP specification best practices** — proper tool annotations (readOnlyHint, destructiveHint, idempotentHint), typed schemas via Zod, and resource URI patterns
5. **Ship as an npm package** installable via `npx` for zero-config usage with any MCP-compatible AI client

## Non-Goals

- Real-time streaming of deployment logs (MCP protocol limitation — tools return single responses)
- Websocket/SSE connections to Coolify for live updates
- Multi-tenant MCP server (one server instance per Coolify instance)
- Replacing the Coolify web UI

## Solution Design

### Architecture

The MCP server is a standalone TypeScript/Node.js application that:

1. Communicates with AI clients via **stdio transport** (standard MCP pattern)
2. Calls **Coolify's REST API** (both native v1 and coolify-enhanced v1 endpoints) using Bearer token authentication
3. Lives in a `mcp-server/` directory within the coolify-enhanced monorepo
4. Is published as `@amirhmoradi/coolify-enhanced-mcp` npm package

```
AI Client (Claude Desktop, Cursor, etc.)
    ↕ stdio (JSON-RPC)
MCP Server (@amirhmoradi/coolify-enhanced-mcp)
    ↕ HTTPS (REST API)
Coolify Instance (with coolify-enhanced addon)
```

### Tool Categories

The MCP server provides tools organized into these categories:

#### Category 1: Core Coolify Tools (from native API)
These wrap Coolify's built-in API endpoints. Inspired by `dazeb/coolify-mcp-enhanced`.

| Tool | Description | API Endpoint | Annotation |
|------|-------------|-------------|------------|
| `list_servers` | List all servers | `GET /servers` | readOnly |
| `get_server` | Get server details | `GET /servers/{uuid}` | readOnly |
| `get_server_resources` | Get resources on a server | `GET /servers/{uuid}/resources` | readOnly |
| `get_server_domains` | Get domains for a server | `GET /servers/{uuid}/domains` | readOnly |
| `validate_server` | Validate server connectivity | `GET /servers/{uuid}/validate` | readOnly |
| `create_server` | Create a new server | `POST /servers` | — |
| `update_server` | Update server settings | `PATCH /servers/{uuid}` | — |
| `delete_server` | Delete a server | `DELETE /servers/{uuid}` | destructive |
| `list_projects` | List all projects | `GET /projects` | readOnly |
| `get_project` | Get project details | `GET /projects/{uuid}` | readOnly |
| `create_project` | Create a new project | `POST /projects` | — |
| `update_project` | Update project settings | `PATCH /projects/{uuid}` | — |
| `delete_project` | Delete a project | `DELETE /projects/{uuid}` | destructive |
| `list_environments` | List environments in project | `GET /projects/{uuid}/environments` | readOnly |
| `get_environment` | Get environment details | `GET /projects/{uuid}/{env}` | readOnly |
| `create_environment` | Create environment | `POST /projects/{uuid}/environments` | — |
| `delete_environment` | Delete environment | `DELETE /projects/{uuid}/environments/{env}` | destructive |
| `list_applications` | List all applications | `GET /applications` | readOnly |
| `get_application` | Get application details | `GET /applications/{uuid}` | readOnly |
| `create_application` | Create from public git repo | `POST /applications/public` | — |
| `update_application` | Update application settings | `PATCH /applications/{uuid}` | — |
| `delete_application` | Delete application | `DELETE /applications/{uuid}` | destructive |
| `start_application` | Start/deploy application | `POST /applications/{uuid}/start` | — |
| `stop_application` | Stop application | `POST /applications/{uuid}/stop` | — |
| `restart_application` | Restart application | `POST /applications/{uuid}/restart` | — |
| `get_application_logs` | Get application logs | `GET /applications/{uuid}/logs` | readOnly |
| `deploy` | Deploy by UUID or tag | `POST /deploy` | — |
| `list_deployments` | List running deployments | `GET /deployments` | readOnly |
| `get_deployment` | Get deployment details | `GET /deployments/{uuid}` | readOnly |
| `cancel_deployment` | Cancel a deployment | `POST /deployments/{uuid}/cancel` | — |
| `list_databases` | List all databases | `GET /databases` | readOnly |
| `get_database` | Get database details | `GET /databases/{uuid}` | readOnly |
| `create_database` | Create a database | `POST /databases/{type}` | — |
| `update_database` | Update database settings | `PATCH /databases/{uuid}` | — |
| `delete_database` | Delete a database | `DELETE /databases/{uuid}` | destructive |
| `start_database` | Start database | `POST /databases/{uuid}/start` | — |
| `stop_database` | Stop database | `POST /databases/{uuid}/stop` | — |
| `restart_database` | Restart database | `POST /databases/{uuid}/restart` | — |
| `list_services` | List all services | `GET /services` | readOnly |
| `get_service` | Get service details | `GET /services/{uuid}` | readOnly |
| `create_service` | Create a one-click service | `POST /services` | — |
| `update_service` | Update service settings | `PATCH /services/{uuid}` | — |
| `delete_service` | Delete a service | `DELETE /services/{uuid}` | destructive |
| `start_service` | Start/deploy service | `POST /services/{uuid}/start` | — |
| `stop_service` | Stop service | `POST /services/{uuid}/stop` | — |
| `restart_service` | Restart service | `POST /services/{uuid}/restart` | — |
| `list_app_envs` | List application env vars | `GET /applications/{uuid}/envs` | readOnly |
| `create_app_env` | Create application env var | `POST /applications/{uuid}/envs` | — |
| `update_app_env` | Update application env var | `PATCH /applications/{uuid}/envs` | — |
| `bulk_update_app_envs` | Bulk update app env vars | `PATCH /applications/{uuid}/envs/bulk` | — |
| `delete_app_env` | Delete application env var | `DELETE /applications/{uuid}/envs/{env_uuid}` | destructive |
| `list_service_envs` | List service env vars | `GET /services/{uuid}/envs` | readOnly |
| `create_service_env` | Create service env var | `POST /services/{uuid}/envs` | — |
| `update_service_env` | Update service env var | `PATCH /services/{uuid}/envs` | — |
| `bulk_update_service_envs` | Bulk update service env vars | `PATCH /services/{uuid}/envs/bulk` | — |
| `delete_service_env` | Delete service env var | `DELETE /services/{uuid}/envs/{env_uuid}` | destructive |
| `list_db_backups` | List database backup configs | `GET /databases/{uuid}/backups` | readOnly |
| `create_db_backup` | Create scheduled backup | `POST /databases/{uuid}/backups` | — |
| `update_db_backup` | Update backup config | `PATCH /databases/{uuid}/backups/{backup_uuid}` | — |
| `delete_db_backup` | Delete backup config | `DELETE /databases/{uuid}/backups/{backup_uuid}` | destructive |
| `list_db_backup_executions` | List backup executions | `GET /databases/{uuid}/backups/{backup_uuid}/executions` | readOnly |
| `list_resources` | List all resources | `GET /resources` | readOnly |
| `list_private_keys` | List private keys | `GET /security/keys` | readOnly |
| `create_private_key` | Create private key | `POST /security/keys` | — |
| `get_private_key` | Get private key details | `GET /security/keys/{uuid}` | readOnly |
| `delete_private_key` | Delete private key | `DELETE /security/keys/{uuid}` | destructive |
| `get_version` | Get Coolify version | `GET /version` | readOnly |
| `health_check` | Check Coolify health | `GET /health` | readOnly |
| `list_teams` | List all teams | `GET /teams` | readOnly |
| `get_current_team` | Get current team | `GET /teams/current` | readOnly |
| `get_team_members` | Get team members | `GET /teams/current/members` | readOnly |

#### Category 2: Enhanced Permissions Tools (coolify-enhanced API)

| Tool | Description | API Endpoint | Annotation |
|------|-------------|-------------|------------|
| `list_project_access` | List users with project access | `GET /projects/{uuid}/access` | readOnly |
| `grant_project_access` | Grant user access to project | `POST /projects/{uuid}/access` | — |
| `update_project_access` | Update user permission level | `PATCH /projects/{uuid}/access/{user_id}` | — |
| `revoke_project_access` | Revoke user project access | `DELETE /projects/{uuid}/access/{user_id}` | — |
| `check_user_permission` | Check user permission on project | `GET /projects/{uuid}/access/{user_id}/check` | readOnly |

#### Category 3: Resource Backup Tools (coolify-enhanced API)

| Tool | Description | API Endpoint | Annotation |
|------|-------------|-------------|------------|
| `list_resource_backups` | List backup schedules | `GET /resource-backups` | readOnly |
| `create_resource_backup` | Create backup schedule | `POST /resource-backups` | — |
| `get_resource_backup` | Get backup schedule details | `GET /resource-backups/{uuid}` | readOnly |
| `trigger_resource_backup` | Trigger immediate backup | `POST /resource-backups/{uuid}/trigger` | — |
| `delete_resource_backup` | Delete backup schedule | `DELETE /resource-backups/{uuid}` | destructive |

#### Category 4: Custom Template Tools (coolify-enhanced API)

| Tool | Description | API Endpoint | Annotation |
|------|-------------|-------------|------------|
| `list_template_sources` | List custom template sources | `GET /template-sources` | readOnly |
| `create_template_source` | Add custom template source | `POST /template-sources` | — |
| `get_template_source` | Get template source details | `GET /template-sources/{uuid}` | readOnly |
| `update_template_source` | Update template source | `PATCH /template-sources/{uuid}` | — |
| `delete_template_source` | Delete template source | `DELETE /template-sources/{uuid}` | destructive |
| `sync_template_source` | Sync templates from source | `POST /template-sources/{uuid}/sync` | — |
| `sync_all_templates` | Sync all template sources | `POST /template-sources/sync-all` | — |

#### Category 5: Network Management Tools (coolify-enhanced API)

| Tool | Description | API Endpoint | Annotation |
|------|-------------|-------------|------------|
| `list_server_networks` | List managed networks on server | `GET /servers/{uuid}/networks` | readOnly |
| `create_network` | Create shared network | `POST /servers/{uuid}/networks` | — |
| `get_network` | Get network details | `GET /servers/{uuid}/networks/{net_uuid}` | readOnly |
| `delete_network` | Delete managed network | `DELETE /servers/{uuid}/networks/{net_uuid}` | destructive |
| `sync_networks` | Sync networks from Docker | `POST /servers/{uuid}/networks/sync` | — |
| `migrate_proxy` | Run proxy isolation migration | `POST /servers/{uuid}/networks/migrate-proxy` | — |
| `cleanup_proxy` | Cleanup old proxy networks | `POST /servers/{uuid}/networks/cleanup-proxy` | — |
| `list_resource_networks` | List networks for a resource | `GET /resources/{type}/{uuid}/networks` | readOnly |
| `attach_resource_network` | Attach resource to network | `POST /resources/{type}/{uuid}/networks` | — |
| `detach_resource_network` | Detach resource from network | `DELETE /resources/{type}/{uuid}/networks/{net_uuid}` | — |

#### Category 6: Composite / Workflow Tools

Higher-level tools that combine multiple API calls for common operations:

| Tool | Description | Calls Multiple Endpoints | Annotation |
|------|-------------|--------------------------|------------|
| `get_infrastructure_overview` | Full overview of all servers, projects, resources | servers + projects + resources | readOnly |
| `deploy_and_wait` | Deploy and poll until complete | deploy + get_deployment (polling) | — |
| `setup_project_with_access` | Create project + set permissions | create_project + grant_access | — |
| `backup_all_resources` | Trigger backups for all resources on a server | list_resource_backups + trigger for each | — |

### MCP Resources

In addition to tools, the server exposes MCP resources for read-only data access:

| Resource URI | Description |
|-------------|-------------|
| `coolify://servers` | List of all servers |
| `coolify://servers/{uuid}` | Server details |
| `coolify://projects` | List of all projects |
| `coolify://projects/{uuid}` | Project details |
| `coolify://applications/{uuid}` | Application details |
| `coolify://databases/{uuid}` | Database details |
| `coolify://services/{uuid}` | Service details |

### MCP Prompts

Pre-built prompt templates for common operations:

| Prompt | Description |
|--------|-------------|
| `deploy-application` | Guide through deploying an application |
| `setup-database-backup` | Guide through setting up database backups |
| `manage-permissions` | Guide through project permission management |
| `troubleshoot-deployment` | Help diagnose deployment failures |
| `setup-network-isolation` | Guide through network isolation setup |

## Technical Decisions and Rationale

### 1. TypeScript with @modelcontextprotocol/sdk

**Decision:** Use TypeScript with the official `@modelcontextprotocol/sdk` (latest stable).

**Rationale:** The MCP TypeScript SDK is the most mature and widely used. It provides type-safe tool registration via Zod schemas, built-in transport handling, and is the reference implementation for MCP servers. The `dazeb/coolify-mcp-enhanced` project validates this approach.

### 2. Standalone npm Package (not embedded in Laravel)

**Decision:** Ship the MCP server as a separate TypeScript package in `mcp-server/` subdirectory.

**Rationale:** MCP servers are typically standalone processes communicating via stdio. Embedding in Laravel would add unnecessary complexity and break the MCP transport model. A standalone package can be installed anywhere — the user's workstation, a CI runner, or alongside Coolify.

### 3. Single CoolifyClient Class

**Decision:** One HTTP client class handles both native Coolify API and coolify-enhanced API endpoints.

**Rationale:** Both APIs share the same authentication mechanism (Bearer token), base URL, and response format. A single client simplifies configuration and connection management.

### 4. Tool Annotations per MCP Spec

**Decision:** Every tool includes `annotations` object with `readOnlyHint`, `destructiveHint`, `idempotentHint`, and `openWorldHint`.

**Rationale:** Tool annotations help AI clients make better decisions about which tools to call. Read-only tools can be called freely for exploration; destructive tools trigger confirmation prompts. This follows the latest MCP specification.

### 5. Zod Schemas for Parameter Validation

**Decision:** Use Zod for all tool parameter schemas.

**Rationale:** The MCP SDK's `server.tool()` method accepts Zod schemas natively. This provides runtime validation, TypeScript type inference, and clear error messages — all without custom validation code.

### 6. Feature Detection for Enhanced Tools

**Decision:** Enhanced tools (permissions, backups, templates, networks) are registered only when `COOLIFY_ENHANCED=true` is set, or auto-detected via a `/health` probe.

**Rationale:** The MCP server should work with both vanilla Coolify and coolify-enhanced. Users running standard Coolify should not see tools that won't work. Auto-detection probes the enhanced API endpoint on startup.

## User Experience

### Installation

```bash
# Global install
npm install -g @amirhmoradi/coolify-enhanced-mcp

# Or run directly
npx @amirhmoradi/coolify-enhanced-mcp
```

### Configuration (Claude Desktop)

```json
{
  "mcpServers": {
    "coolify": {
      "command": "npx",
      "args": ["-y", "@amirhmoradi/coolify-enhanced-mcp"],
      "env": {
        "COOLIFY_BASE_URL": "https://coolify.example.com",
        "COOLIFY_ACCESS_TOKEN": "your-api-token",
        "COOLIFY_ENHANCED": "true"
      }
    }
  }
}
```

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `COOLIFY_BASE_URL` | Yes | — | Coolify instance URL (e.g., `https://coolify.example.com`) |
| `COOLIFY_ACCESS_TOKEN` | Yes | — | Coolify API token with appropriate scopes |
| `COOLIFY_ENHANCED` | No | `false` | Enable enhanced features (permissions, backups, templates, networks) |
| `COOLIFY_MCP_LOG_LEVEL` | No | `info` | Log level: `debug`, `info`, `warn`, `error` |

## Files Modified/Created

### New Files

- `mcp-server/` — Entire MCP server subdirectory
- `mcp-server/package.json` — npm package configuration
- `mcp-server/tsconfig.json` — TypeScript configuration
- `mcp-server/src/index.ts` — Entry point
- `mcp-server/src/lib/coolify-client.ts` — HTTP API client
- `mcp-server/src/lib/mcp-server.ts` — Core MCP server with tool registration
- `mcp-server/src/lib/types.ts` — TypeScript type definitions
- `mcp-server/src/lib/tools/` — Tool definition modules by category
- `docs/features/mcp-server/` — Feature documentation

### Modified Files

- `CLAUDE.md` — Add MCP server architecture section
- `AGENTS.md` — Add MCP server details for AI agents
- `README.md` — Add MCP server section to user documentation

## Risks and Mitigations

| Risk | Mitigation |
|------|-----------|
| API token exposure in MCP config | Document: tokens should have minimal required scopes. Recommend `read` + `deploy` scopes only for standard usage |
| Destructive operations via AI | Tool annotations flag destructive operations; AI clients should confirm before executing. Server includes destructiveHint annotations |
| Coolify API changes breaking tools | Version-pin tool schemas. Include API version check on startup |
| Rate limiting on large Coolify instances | Built-in retry with exponential backoff (similar to dazeb project) |
| Enhanced features unavailable | Auto-detection of enhanced features; graceful fallback to core tools only |

## Testing Checklist

- [ ] All core Coolify API tools work against a live instance
- [ ] Enhanced permission tools work with coolify-enhanced addon
- [ ] Resource backup tools work with coolify-enhanced addon
- [ ] Custom template tools work with coolify-enhanced addon
- [ ] Network management tools work with coolify-enhanced addon
- [ ] Destructive tool annotations trigger confirmation in Claude Desktop
- [ ] Tool parameter validation rejects invalid inputs with clear errors
- [ ] Server starts and connects via stdio transport
- [ ] `npx` installation works without errors
- [ ] Feature auto-detection correctly identifies vanilla vs enhanced Coolify
- [ ] Retry logic handles transient network errors
- [ ] Server handles Coolify being offline gracefully

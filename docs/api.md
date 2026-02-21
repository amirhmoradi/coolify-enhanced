# API Documentation

This document describes the REST API endpoints provided by the Coolify Enhanced package, and the MCP (Model Context Protocol) server for AI-assisted infrastructure management.

## Authentication

All API endpoints require authentication via Bearer token (Laravel Sanctum).

```bash
curl -H "Authorization: Bearer YOUR_API_TOKEN" \
     -H "Accept: application/json" \
     https://your-coolify-instance/api/v1/permissions/project
```

## Base URL

All endpoints are prefixed with `/api/v1/`

---

## Permissions API

Base path: `/api/v1/permissions/`

### List Project Permissions

List all project access records for the current team.

**Endpoint:** `GET /api/v1/permissions/project`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "project_uuid": "abc123",
      "project_name": "My Project",
      "user_id": 5,
      "user_name": "John Doe",
      "user_email": "john@example.com",
      "can_view": true,
      "can_deploy": true,
      "can_manage": false,
      "can_delete": false,
      "permission_level": "deploy",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-15T10:30:00Z"
    }
  ]
}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `project_uuid` | string | Filter by project UUID |
| `user_id` | integer | Filter by user ID |

**Example:**
```bash
# List all permissions
curl -H "Authorization: Bearer $TOKEN" \
     https://coolify.example.com/api/v1/permissions/project

# Filter by project
curl -H "Authorization: Bearer $TOKEN" \
     "https://coolify.example.com/api/v1/permissions/project?project_uuid=abc123"
```

---

### Get Project Permission

Get a specific project access record.

**Endpoint:** `GET /api/v1/permissions/project/{id}`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | The project_user record ID |

**Response:**
```json
{
  "id": 1,
  "project_uuid": "abc123",
  "project_name": "My Project",
  "user_id": 5,
  "user_name": "John Doe",
  "user_email": "john@example.com",
  "can_view": true,
  "can_deploy": true,
  "can_manage": false,
  "can_delete": false,
  "permission_level": "deploy",
  "created_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z"
}
```

**Error Response (404):**
```json
{
  "message": "Project permission not found."
}
```

---

### Grant Project Access

Grant a user access to a project.

**Endpoint:** `POST /api/v1/permissions/project`

**Request Body:**
```json
{
  "project_uuid": "abc123",
  "user_id": 5,
  "permission_level": "deploy"
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `project_uuid` | string | Yes | UUID of the project |
| `user_id` | integer | Yes | ID of the user to grant access |
| `permission_level` | string | Yes | One of: `view_only`, `deploy`, `full_access` |

**Permission Levels:**

| Level | View | Deploy | Manage | Delete |
|-------|------|--------|--------|--------|
| `view_only` | Yes | — | — | — |
| `deploy` | Yes | Yes | — | — |
| `full_access` | Yes | Yes | Yes | Yes |

**Response (201 Created):**
```json
{
  "message": "Project access granted.",
  "data": {
    "id": 1,
    "project_uuid": "abc123",
    "user_id": 5,
    "can_view": true,
    "can_deploy": true,
    "can_manage": false,
    "can_delete": false
  }
}
```

**Error Response (422 Validation Error):**
```json
{
  "message": "Validation failed.",
  "errors": {
    "permission_level": ["The selected permission level is invalid."]
  }
}
```

**Error Response (409 Conflict):**
```json
{
  "message": "User already has access to this project."
}
```

---

### Update Project Permission

Update a user's project access level.

**Endpoint:** `PUT /api/v1/permissions/project/{id}`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | The project_user record ID |

**Request Body:**
```json
{
  "permission_level": "full_access"
}
```

**Response:**
```json
{
  "message": "Project permission updated.",
  "data": {
    "id": 1,
    "project_uuid": "abc123",
    "user_id": 5,
    "can_view": true,
    "can_deploy": true,
    "can_manage": true,
    "can_delete": true
  }
}
```

---

### Revoke Project Access

Remove a user's access to a project.

**Endpoint:** `DELETE /api/v1/permissions/project/{id}`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | The project_user record ID |

**Response:**
```json
{
  "message": "Project access revoked."
}
```

---

### List Environment Permissions

List all environment permission overrides.

**Endpoint:** `GET /api/v1/permissions/environment`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `environment_id` | integer | Filter by environment ID |
| `user_id` | integer | Filter by user ID |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "environment_id": 10,
      "environment_name": "production",
      "project_uuid": "abc123",
      "user_id": 5,
      "user_name": "John Doe",
      "can_view": true,
      "can_deploy": false,
      "can_manage": false,
      "can_delete": false,
      "created_at": "2024-01-15T10:30:00Z"
    }
  ]
}
```

---

### Create Environment Override

Create an environment-level permission override.

**Endpoint:** `POST /api/v1/permissions/environment`

**Request Body:**
```json
{
  "environment_id": 10,
  "user_id": 5,
  "permission_level": "view_only"
}
```

**Response (201 Created):**
```json
{
  "message": "Environment permission override created.",
  "data": {
    "id": 1,
    "environment_id": 10,
    "user_id": 5,
    "can_view": true,
    "can_deploy": false,
    "can_manage": false,
    "can_delete": false
  }
}
```

---

### Delete Environment Override

Remove an environment permission override (reverts to project-level permissions).

**Endpoint:** `DELETE /api/v1/permissions/environment/{id}`

**Response:**
```json
{
  "message": "Environment permission override removed."
}
```

---

## Bulk Operations

### Grant Access to All Team Members

Grant project access to all current team members.

**Endpoint:** `POST /api/v1/permissions/project/bulk`

**Request Body:**
```json
{
  "project_uuid": "abc123",
  "permission_level": "view_only"
}
```

**Response:**
```json
{
  "message": "Access granted to 5 team members.",
  "count": 5
}
```

---

### Revoke All Project Access

Remove all user access from a project.

**Endpoint:** `DELETE /api/v1/permissions/project/bulk/{project_uuid}`

**Response:**
```json
{
  "message": "All project access revoked.",
  "count": 5
}
```

---

## Template Sources API

Base path: `/api/v1/template-sources/`

### List Template Sources

List all custom template sources.

**Endpoint:** `GET /api/v1/template-sources`

**Response:**
```json
{
  "data": [
    {
      "uuid": "abc123",
      "name": "My Templates",
      "slug": "my-templates",
      "repository_url": "https://github.com/myorg/coolify-templates",
      "branch": "main",
      "folder_path": "templates/compose",
      "is_enabled": true,
      "sync_status": "synced",
      "last_synced_at": "2024-06-15T10:30:00Z",
      "sync_error": null,
      "template_count": 12,
      "created_at": "2024-06-01T08:00:00Z"
    }
  ]
}
```

---

### Create Template Source

Add a new GitHub repository as a template source.

**Endpoint:** `POST /api/v1/template-sources`

**Request Body:**
```json
{
  "name": "My Templates",
  "repository_url": "https://github.com/myorg/coolify-templates",
  "branch": "main",
  "folder_path": "templates/compose",
  "auth_token": "ghp_xxxx"
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | Human-readable source name |
| `repository_url` | string | Yes | GitHub repository URL |
| `branch` | string | No | Branch name (default: `main`) |
| `folder_path` | string | No | Path within repo to YAML templates (default: root) |
| `auth_token` | string | No | GitHub PAT for private repositories |

**Response (201 Created):**
```json
{
  "message": "Template source created.",
  "data": { "uuid": "abc123", "name": "My Templates", "..." : "..." }
}
```

---

### Get Template Source

Get details for a specific template source.

**Endpoint:** `GET /api/v1/template-sources/{uuid}`

**Response:**
```json
{
  "uuid": "abc123",
  "name": "My Templates",
  "repository_url": "https://github.com/myorg/coolify-templates",
  "branch": "main",
  "folder_path": "templates/compose",
  "is_enabled": true,
  "sync_status": "synced",
  "last_synced_at": "2024-06-15T10:30:00Z",
  "templates": [
    { "name": "whoami", "slogan": "A simple HTTP service", "tags": "testing,debug" }
  ]
}
```

---

### Update Template Source

Update a template source's settings.

**Endpoint:** `PUT /api/v1/template-sources/{uuid}`

**Request Body:**
```json
{
  "name": "Updated Name",
  "branch": "develop",
  "is_enabled": false
}
```

---

### Delete Template Source

Delete a custom template source.

**Endpoint:** `DELETE /api/v1/template-sources/{uuid}`

**Response:**
```json
{
  "message": "Template source deleted."
}
```

> Deleting a source has zero impact on services already deployed from its templates.

---

### Sync Template Source

Trigger a sync for a specific template source.

**Endpoint:** `POST /api/v1/template-sources/{uuid}/sync`

**Response:**
```json
{
  "message": "Sync job dispatched."
}
```

---

### Sync All Template Sources

Trigger a sync for all enabled template sources.

**Endpoint:** `POST /api/v1/template-sources/sync-all`

**Response:**
```json
{
  "message": "Sync jobs dispatched for 3 sources."
}
```

---

## Resource Backups API

Base path: `/api/v1/resource-backups/`

### List Resource Backup Schedules

List all resource backup schedules.

**Endpoint:** `GET /api/v1/resource-backups`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `resource_type` | string | Filter by type: `application`, `service`, `database` |
| `resource_id` | integer | Filter by resource ID |

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "resource_type": "App\\Models\\Application",
      "resource_id": 42,
      "backup_type": "full",
      "frequency": "0 2 * * *",
      "s3_storage_id": 1,
      "enabled": true,
      "keep_locally": true,
      "number_of_backups_locally": 5,
      "created_at": "2024-06-01T08:00:00Z"
    }
  ]
}
```

---

### Create Resource Backup Schedule

Create a new backup schedule for a resource.

**Endpoint:** `POST /api/v1/resource-backups`

**Request Body:**
```json
{
  "resource_type": "application",
  "resource_id": 42,
  "backup_type": "full",
  "frequency": "0 2 * * *",
  "s3_storage_id": 1,
  "enabled": true,
  "keep_locally": true,
  "number_of_backups_locally": 5
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource_type` | string | Yes | `application`, `service`, `database`, or `coolify_instance` |
| `resource_id` | integer | Yes* | Resource ID (*not needed for `coolify_instance`) |
| `backup_type` | string | Yes | `volume`, `configuration`, `full`, or `coolify_instance` |
| `frequency` | string | Yes | Cron expression |
| `s3_storage_id` | integer | No | S3 storage destination for upload |
| `enabled` | boolean | No | Enable/disable (default: `true`) |
| `keep_locally` | boolean | No | Keep local copy (default: `true`) |
| `number_of_backups_locally` | integer | No | Local retention count |

**Response (201 Created):**
```json
{
  "message": "Resource backup schedule created.",
  "data": { "id": 1, "..." : "..." }
}
```

---

### Get Resource Backup Schedule

Get schedule details and recent executions.

**Endpoint:** `GET /api/v1/resource-backups/{id}`

**Response:**
```json
{
  "id": 1,
  "resource_type": "App\\Models\\Application",
  "resource_id": 42,
  "backup_type": "full",
  "frequency": "0 2 * * *",
  "enabled": true,
  "executions": [
    {
      "id": 10,
      "status": "success",
      "filename": "backup-2024-06-15.tar.gz",
      "size": 1048576,
      "is_encrypted": true,
      "created_at": "2024-06-15T02:00:00Z"
    }
  ]
}
```

---

### Update Resource Backup Schedule

**Endpoint:** `PUT /api/v1/resource-backups/{id}`

**Request Body:**
```json
{
  "frequency": "0 3 * * *",
  "enabled": false
}
```

---

### Delete Resource Backup Schedule

**Endpoint:** `DELETE /api/v1/resource-backups/{id}`

**Response:**
```json
{
  "message": "Resource backup schedule deleted."
}
```

---

## Network Management API

Base path: `/api/v1/networks/`

### List Server Networks

List all managed networks for a server.

**Endpoint:** `GET /api/v1/networks/{serverUuid}`

**Response:**
```json
{
  "data": [
    {
      "uuid": "net-abc123",
      "docker_network_name": "ce-env-xyz789",
      "scope": "environment",
      "driver": "bridge",
      "status": "active",
      "server_id": 1,
      "environment_id": 5,
      "docker_id": "sha256:abc...",
      "is_proxy_network": false,
      "resource_count": 3
    }
  ]
}
```

---

### Create Shared Network

Create a user-defined shared network on a server.

**Endpoint:** `POST /api/v1/networks/{serverUuid}`

**Request Body:**
```json
{
  "name": "shared-backend",
  "driver": "bridge"
}
```

**Request Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | Network display name |
| `driver` | string | No | `bridge` (standalone) or `overlay` (Swarm); auto-detected if omitted |

**Response (201 Created):**
```json
{
  "message": "Network created.",
  "data": { "uuid": "net-abc123", "docker_network_name": "ce-shared-abc123", "..." : "..." }
}
```

---

### Get Network Details

Get network details including Docker inspection data.

**Endpoint:** `GET /api/v1/networks/{serverUuid}/{networkUuid}`

---

### Delete Network

Delete a managed network from the server.

**Endpoint:** `DELETE /api/v1/networks/{serverUuid}/{networkUuid}`

**Response:**
```json
{
  "message": "Network deleted."
}
```

---

### Sync Networks

Sync managed networks from Docker (discover new networks, verify existing).

**Endpoint:** `POST /api/v1/networks/{serverUuid}/sync`

---

### Proxy Migration

Run the proxy isolation migration (creates proxy network, connects FQDN resources).

**Endpoint:** `POST /api/v1/networks/{serverUuid}/proxy/migrate`

---

### Proxy Cleanup

Disconnect the proxy from non-proxy networks after all resources are redeployed.

**Endpoint:** `POST /api/v1/networks/{serverUuid}/proxy/cleanup`

---

### List Resource Networks

List networks attached to a specific resource.

**Endpoint:** `GET /api/v1/networks/resource/{type}/{uuid}`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `application`, `service`, or `database` |
| `uuid` | string | Resource UUID |

---

### Attach Resource to Network

Attach a resource to a managed network.

**Endpoint:** `POST /api/v1/networks/resource/{type}/{uuid}/attach`

**Request Body:**
```json
{
  "network_uuid": "net-abc123"
}
```

---

### Detach Resource from Network

Detach a resource from a managed network.

**Endpoint:** `DELETE /api/v1/networks/resource/{type}/{uuid}/{networkUuid}`

---

## Error Responses

### Common Error Codes

| Code | Description |
|------|-------------|
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Invalid or missing token |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Resource doesn't exist |
| 409 | Conflict - Resource already exists |
| 422 | Validation Error - Invalid request body |
| 500 | Server Error - Internal error |

### Error Response Format

```json
{
  "message": "Human-readable error message.",
  "errors": {
    "field_name": ["Specific error for this field."]
  }
}
```

---

## Rate Limiting

API endpoints are subject to Coolify's default rate limiting:

- 60 requests per minute per API token
- Rate limit headers included in response:
  - `X-RateLimit-Limit`
  - `X-RateLimit-Remaining`
  - `X-RateLimit-Reset`

---

## MCP Server (AI Assistant Integration)

The Coolify Enhanced MCP Server wraps all the above REST API endpoints (plus Coolify's native API) as MCP tools, enabling AI assistants to manage infrastructure through natural language.

### What is MCP?

The [Model Context Protocol](https://modelcontextprotocol.io) (MCP) is an open standard that allows AI assistants to interact with external tools and data sources. The MCP server translates natural language commands from AI clients into Coolify API calls.

### Quick Setup

```bash
# Run directly via npx (no install needed)
npx @amirhmoradi/coolify-enhanced-mcp
```

### Client Configuration

#### Claude Desktop

Add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "coolify": {
      "command": "npx",
      "args": ["-y", "@amirhmoradi/coolify-enhanced-mcp"],
      "env": {
        "COOLIFY_BASE_URL": "https://coolify.example.com",
        "COOLIFY_ACCESS_TOKEN": "your-api-token"
      }
    }
  }
}
```

#### Cursor / VS Code / Kiro IDE

Add to your MCP settings:

```json
{
  "mcpServers": {
    "coolify": {
      "command": "npx",
      "args": ["-y", "@amirhmoradi/coolify-enhanced-mcp"],
      "env": {
        "COOLIFY_BASE_URL": "https://coolify.example.com",
        "COOLIFY_ACCESS_TOKEN": "your-api-token"
      }
    }
  }
}
```

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `COOLIFY_BASE_URL` | Yes | — | Coolify instance URL |
| `COOLIFY_ACCESS_TOKEN` | Yes | — | API token (create in Coolify: Settings > Keys & Tokens) |
| `COOLIFY_ENHANCED` | No | `false` | Force enable enhanced tools (auto-detected if omitted) |
| `COOLIFY_MCP_TIMEOUT` | No | `30000` | API request timeout in milliseconds |
| `COOLIFY_MCP_RETRIES` | No | `3` | Retry attempts for transient failures |

### Available MCP Tools

The MCP server provides **99 tools** organized into 14 categories:

| Category | Tools | Source |
|----------|-------|--------|
| Servers | 8 | Coolify native API |
| Projects & Environments | 9 | Coolify native API |
| Applications | 10 | Coolify native API |
| Databases | 8 | Coolify native API |
| Services | 8 | Coolify native API |
| Deployments | 4 | Coolify native API |
| Environment Variables | 10 | Coolify native API |
| Database Backups | 5 | Coolify native API |
| Security & Teams | 7 | Coolify native API |
| System | 3 | Coolify native API |
| **Permissions** | **5** | **coolify-enhanced API** |
| **Resource Backups** | **5** | **coolify-enhanced API** |
| **Custom Templates** | **7** | **coolify-enhanced API** |
| **Networks** | **10** | **coolify-enhanced API** |

72 core tools work with any Coolify v4 instance. 27 enhanced tools require the coolify-enhanced addon.

### Tool Annotations

Every tool includes semantic annotations that help AI clients make safety decisions:

| Annotation | Description |
|------------|-------------|
| `readOnlyHint` | Tool only reads data, no side effects |
| `destructiveHint` | Tool may delete or irreversibly modify resources |
| `idempotentHint` | Repeated calls with same args have no additional effect |
| `openWorldHint` | Tool interacts with external entities beyond Coolify |

### Feature Auto-Detection

The MCP server automatically detects whether the coolify-enhanced addon is installed by probing `GET /api/v1/resource-backups`:

- **200, 401, or 403** — endpoint exists, enhanced tools are registered
- **404** — endpoint missing, only core tools are registered

You can override auto-detection by setting `COOLIFY_ENHANCED=true`.

For the full MCP tool reference, see [mcp-server/README.md](../mcp-server/README.md).

---

## SDK Examples

### PHP (Laravel)

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken($apiToken)
    ->post('https://coolify.example.com/api/v1/permissions/project', [
        'project_uuid' => 'abc123',
        'user_id' => 5,
        'permission_level' => 'deploy',
    ]);

if ($response->successful()) {
    $permission = $response->json('data');
}
```

### JavaScript (Node.js)

```javascript
const response = await fetch('https://coolify.example.com/api/v1/permissions/project', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${apiToken}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  body: JSON.stringify({
    project_uuid: 'abc123',
    user_id: 5,
    permission_level: 'deploy',
  }),
});

const data = await response.json();
```

### Python

```python
import requests

response = requests.post(
    'https://coolify.example.com/api/v1/permissions/project',
    headers={
        'Authorization': f'Bearer {api_token}',
        'Accept': 'application/json',
    },
    json={
        'project_uuid': 'abc123',
        'user_id': 5,
        'permission_level': 'deploy',
    }
)

data = response.json()
```

### cURL

```bash
# Grant access
curl -X POST \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"project_uuid":"abc123","user_id":5,"permission_level":"deploy"}' \
  https://coolify.example.com/api/v1/permissions/project

# List permissions
curl -H "Authorization: Bearer $API_TOKEN" \
  -H "Accept: application/json" \
  https://coolify.example.com/api/v1/permissions/project

# Revoke access
curl -X DELETE \
  -H "Authorization: Bearer $API_TOKEN" \
  https://coolify.example.com/api/v1/permissions/project/1

# Create a template source
curl -X POST \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"My Templates","repository_url":"https://github.com/myorg/templates","branch":"main"}' \
  https://coolify.example.com/api/v1/template-sources

# Create a resource backup schedule
curl -X POST \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"resource_type":"application","resource_id":42,"backup_type":"full","frequency":"0 2 * * *"}' \
  https://coolify.example.com/api/v1/resource-backups

# List server networks
curl -H "Authorization: Bearer $API_TOKEN" \
  https://coolify.example.com/api/v1/networks/server-uuid-here
```

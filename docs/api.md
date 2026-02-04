# API Documentation

This document describes the REST API endpoints provided by the Coolify Granular Permissions package.

## Authentication

All API endpoints require authentication via Bearer token (Laravel Sanctum).

```bash
curl -H "Authorization: Bearer YOUR_API_TOKEN" \
     -H "Accept: application/json" \
     https://your-coolify-instance/api/v1/permissions/project
```

## Base URL

All endpoints are prefixed with `/api/v1/permissions/`

## Endpoints

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
| `view_only` | ✓ | ✗ | ✗ | ✗ |
| `deploy` | ✓ | ✓ | ✗ | ✗ |
| `full_access` | ✓ | ✓ | ✓ | ✓ |

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

## Webhooks (Future)

Planned webhook events for permission changes:

| Event | Trigger |
|-------|---------|
| `permission.granted` | User granted project access |
| `permission.updated` | User's permission level changed |
| `permission.revoked` | User's access revoked |

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
```

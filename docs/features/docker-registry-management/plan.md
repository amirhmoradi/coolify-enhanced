# Docker Registry Management — Technical Implementation Plan

## Architecture Overview

Team-scoped `DockerRegistry` model with encrypted provider-specific credentials. `RegistrySyncJob` automates `docker login` on team servers via SSH. Coolify's existing `prepare_builder_image()` mounts `~/.docker/config.json` into helper containers — zero deployment job changes.

## Data Model

### `docker_registries` Table

```sql
CREATE TABLE docker_registries (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    team_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL, -- dockerhub|ghcr|gitlab|ecr|quay|azure|custom
    url VARCHAR(500) NOT NULL,
    credentials TEXT NOT NULL, -- encrypted JSON, provider-specific
    is_active BOOLEAN DEFAULT TRUE,
    last_tested_at TIMESTAMP NULL,
    last_test_status VARCHAR(50) NULL, -- success|failed
    last_test_error TEXT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);
```

### `docker_registry_server` Pivot Table

```sql
CREATE TABLE docker_registry_server (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    docker_registry_id BIGINT NOT NULL,
    server_id BIGINT NOT NULL,
    sync_status VARCHAR(50) DEFAULT 'pending', -- pending|synced|failed|stale
    sync_error TEXT NULL,
    synced_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (docker_registry_id) REFERENCES docker_registries(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE KEY (docker_registry_id, server_id)
);
```

## Provider Credential Schemas

Each provider type stores different fields in the encrypted `credentials` JSON:

- **dockerhub**: `{"username": "...", "password": "..."}`
- **ghcr**: `{"username": "...", "password": "..."}`
- **gitlab**: `{"username": "...", "password": "...", "custom_url": null}`
- **ecr**: `{"aws_access_key_id": "...", "aws_secret_access_key": "...", "region": "...", "account_id": "..."}`
- **quay**: `{"username": "...", "password": "..."}`
- **azure**: `{"login_server": "...", "username": "...", "password": "..."}`
- **custom**: `{"username": "...", "password": "..."}`

## Key Components

### RegistryService

- `loginOnServer(DockerRegistry, Server)` — SSH docker login
- `logoutFromServer(DockerRegistry, Server)` — SSH docker logout
- `testConnection(DockerRegistry)` — V2 API ping
- `getEcrAuthToken(DockerRegistry)` — AWS SDK GetAuthorizationToken
- `getLoginCredentials(DockerRegistry)` — resolve username/password for any type
- `resolveRegistryUrl(DockerRegistry)` — compute URL from type + credentials

### RegistrySyncJob

- Dispatched per-server with `ShouldBeUnique` keyed on `server_id`
- Iterates all active registries for the server's team
- Runs `docker login` for each, updates pivot status
- Catches SSH errors gracefully, marks failed

### EcrTokenRefreshJob

- Scheduled every 6 hours
- Queries all active ECR registries
- Generates fresh token via AWS SDK
- Dispatches `RegistrySyncJob` for each affected server

## Sync Triggers

1. Registry CRUD → dispatch to all team servers
2. Server created → dispatch all team registries to new server
3. ECR refresh → scheduled every 6 hours
4. Manual → API or UI button

## API Endpoints

```
GET    /api/v1/registries              — List
POST   /api/v1/registries              — Create
GET    /api/v1/registries/{uuid}       — Show
PUT    /api/v1/registries/{uuid}       — Update
DELETE /api/v1/registries/{uuid}       — Delete
POST   /api/v1/registries/{uuid}/test  — Test connection
POST   /api/v1/registries/{uuid}/sync  — Force sync
GET    /api/v1/registries/{uuid}/status — Sync status
POST   /api/v1/registries/sync-all     — Sync all
```

## Security

- Credentials stored with `encrypted:array` cast
- Passwords piped via `--password-stdin` (not in command args)
- API responses mask credentials with `***`
- `docker logout` on registry deletion
- Team owner/admin only via policy

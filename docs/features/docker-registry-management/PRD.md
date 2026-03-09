# Docker Registry Management — Product Requirements Document

## Problem Statement

Coolify v4 has no built-in Docker registry management. Users must manually SSH into each server and run `docker login` for every private registry they need. This manual process is:

- **Error-prone**: Forgetting to log in on a server causes deployment failures
- **Unscalable**: Every new server requires re-running `docker login` for each registry
- **Insecure**: No centralized credential management or rotation
- **Frustrating**: Users on Coolify's GitHub (#2499, #4763) consistently request this

Competitors (Portainer, CapRover, Rancher) all provide UI-based registry management. Coolify is an outlier.

## Goals

1. Provide a centralized UI for managing multiple Docker registries with authentication
2. Automatically sync credentials to all team servers (no manual SSH)
3. Support provider-specific authentication (Docker Hub, GHCR, GitLab, ECR, Quay, Azure ACR, Custom)
4. Validate credentials before deploying (connection test)
5. Handle ECR's 12-hour token expiry automatically
6. Zero changes to Coolify's deployment job — leverage existing `config.json` mounting

## Non-Goals

- Registry browsing (listing repos/tags) — future enhancement
- Image deletion from registries — out of scope
- Per-resource registry selection — global auto-login covers all use cases
- Self-hosted registry deployment/hosting — only credential management

## Solution Design

### Scope: Team-Level Registries

Registries belong to a team and are available on all team servers. When a registry is added or updated, credentials are automatically synced to every server in the team via `docker login` over SSH.

### Provider Types

Seven provider types with dedicated form fields:

| Provider | URL | Auth Fields |
|----------|-----|-------------|
| Docker Hub | `docker.io` | username, access token |
| GHCR | `ghcr.io` | username, personal access token |
| GitLab | `registry.gitlab.com` (or custom) | username, personal access token |
| AWS ECR | Auto-computed from region + account | AWS access key, secret key, region, account ID |
| Quay | `quay.io` | username, password (robot account) |
| Azure ACR | `{name}.azurecr.io` | login server, username, password |
| Custom | User-provided | URL, username, password |

### Credential Injection Mechanism

Uses `docker login` on each server via SSH (`instant_remote_process()`). This writes to `~/.docker/config.json`, which Coolify's existing deployment flow already mounts into helper containers. Zero deployment job changes required.

For Docker Swarm: `--with-registry-auth` on `docker stack deploy` distributes tokens from the manager's config to worker nodes automatically.

### ECR Token Refresh

AWS ECR tokens expire after 12 hours. A scheduled `EcrTokenRefreshJob` runs every 6 hours using the AWS SDK (already installed via `league/flysystem-aws-s3-v3`) to generate fresh tokens and re-run `docker login` on all servers.

## Technical Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Credential injection | `docker login` on servers | Zero deployment job overlay changes; works for both apps and services |
| Scope | Team-level | Matches Coolify's team model; servers belong to one team |
| ECR handling | AWS SDK in PHP | SDK already installed via flysystem; avoids installing AWS CLI on servers |
| Connection test | V2 API from Coolify server | No SSH needed; validates credentials before syncing |
| Sync serialization | `ShouldBeUnique` per server | Prevents concurrent `config.json` writes |
| Feature tier | Pro | Listed as planned pro feature in `config/features.php` |

## User Experience

### Settings > Registries (Primary Management)

- Table of registries with name, type, URL, status, actions
- "Add Registry" form with provider type selector and provider-specific fields
- "Test Connection" button for credential validation
- Per-registry expandable sync status showing each server
- "Sync All" button for manual re-sync

### Server > Registries (Read-Only Status)

- Table showing all team registries with per-server sync status
- "Re-sync" button to force credential push to this server
- No CRUD — management is centralized in Settings

## Files Modified

### New Files
- `src/Models/DockerRegistry.php` — Model with encrypted credentials
- `src/Models/DockerRegistryServer.php` — Pivot model for sync status
- `src/Services/RegistryService.php` — Login/logout, test, ECR token
- `src/Jobs/RegistrySyncJob.php` — Per-server credential sync
- `src/Jobs/EcrTokenRefreshJob.php` — ECR token refresh
- `src/Livewire/RegistryManager.php` — Settings page
- `src/Livewire/ServerRegistries.php` — Server status view
- `src/Http/Controllers/Api/RegistryController.php` — REST API
- `src/Policies/RegistryPolicy.php` — Authorization
- Database migrations, Blade views, MCP tools

### Modified Files
- Settings navbar overlay — add "Registries" tab
- Server sidebar overlay — add "Registries" item
- Service provider — register components
- API/web routes — add registry endpoints
- Config, `.free-edition-ignore`, `.env.free`

### NOT Modified
- `ApplicationDeploymentJob.php` — existing config.json mounting suffices
- `StartService.php` — server-level docker login covers compose pull

## Risks

| Risk | Mitigation |
|------|------------|
| Server unreachable during sync | Mark as `failed`, show in UI, allow manual re-sync |
| ECR token refresh failure | Retry with backoff; last successful token persists until expiry |
| Concurrent docker login race | Serialize per server via `ShouldBeUnique` |
| Credential helper on server | `docker login` works through credential helpers transparently |

## Testing Checklist

- [ ] Create each provider type and verify credentials saved encrypted
- [ ] Test connection for each provider type
- [ ] Verify `docker login` runs on server and `config.json` is updated
- [ ] Deploy application with private image — verify pull succeeds
- [ ] Deploy service with private image — verify compose pull succeeds
- [ ] Delete registry — verify `docker logout` removes credentials
- [ ] Add new server — verify all registries synced automatically
- [ ] ECR token refresh — verify re-login every 6 hours
- [ ] Feature disabled — verify upsell card shown
- [ ] Free edition build — verify all registry files stripped

# Docker Registry Management

Centralized management of multiple Docker container registries with provider-specific authentication, automatic credential syncing to servers, and connection testing.

## Overview

This Pro feature replaces the manual `docker login` workflow with a UI-based registry management system. Registries are configured once at the team level and credentials are automatically pushed to all team servers.

## Components

| Component | File | Purpose |
|-----------|------|---------|
| DockerRegistry model | `src/Models/DockerRegistry.php` | Registry entity with encrypted credentials |
| DockerRegistryServer model | `src/Models/DockerRegistryServer.php` | Per-server sync status pivot |
| RegistryService | `src/Services/RegistryService.php` | Login/logout, test, ECR token |
| RegistrySyncJob | `src/Jobs/RegistrySyncJob.php` | SSH docker login on servers |
| EcrTokenRefreshJob | `src/Jobs/EcrTokenRefreshJob.php` | AWS ECR 6-hour token refresh |
| RegistryManager | `src/Livewire/RegistryManager.php` | Settings > Registries page |
| ServerRegistries | `src/Livewire/ServerRegistries.php` | Server > Registries status |
| RegistryController | `src/Http/Controllers/Api/RegistryController.php` | REST API |
| RegistryPolicy | `src/Policies/RegistryPolicy.php` | Team-scoped authorization |
| MCP tools | `mcp-server/src/tools/registries.ts` | 7 MCP tools for AI assistants |

## Supported Providers

- **Docker Hub** — username + access token
- **GitHub Container Registry (GHCR)** — username + personal access token
- **GitLab Container Registry** — username + PAT, custom URL support
- **AWS ECR** — AWS access key/secret, automatic 12h token refresh
- **Quay.io** — username + password (robot accounts)
- **Azure Container Registry** — login server + service principal
- **Custom** — any V2-compatible registry with basic auth

## How It Works

1. Admin adds a registry in Settings > Registries with provider-specific credentials
2. On save, a `RegistrySyncJob` runs `docker login` on every team server via SSH
3. Docker writes credentials to `~/.docker/config.json` on each server
4. Coolify's existing deployment flow mounts this file into helper containers
5. `docker pull` and `docker push` work transparently for private images

No deployment job changes are needed — the existing `prepare_builder_image()` flow handles everything.

## Configuration

```env
CORELIX_PLATFORM=true
FEATURE_DOCKER_REGISTRY_MANAGEMENT=true  # Enabled by default in Pro edition
```

## Feature Tier

**Pro** — This feature is stripped from the free edition build.

## Related Documentation

- [PRD.md](PRD.md) — Product requirements
- [plan.md](plan.md) — Technical implementation plan
- [Coolify Registry Docs](https://coolify.io/docs/knowledge-base/docker/registry) — Current manual approach

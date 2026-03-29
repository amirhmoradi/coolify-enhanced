# Network Management

Per-environment Docker network isolation for Corelix Platform.

## Overview

Provides three phases of network isolation:

1. **Phase 1: Environment Isolation** (zero overlays) — Per-environment bridge networks via post-deployment hooks
2. **Phase 2: Proxy Isolation** (two overlays) — Dedicated proxy network with `traefik.docker.network` label injection
3. **Phase 3: Swarm Support** (zero new overlays) — Automatic overlay networks for Docker Swarm clusters

## Key Components

| File | Purpose |
|------|---------|
| `src/Services/NetworkService.php` | Core engine: Docker network CRUD, reconciliation, Swarm support |
| `src/Jobs/NetworkReconcileJob.php` | Post-deployment hook: connects containers to managed networks |
| `src/Jobs/ProxyMigrationJob.php` | Migrates existing servers to proxy isolation |
| `src/Models/ManagedNetwork.php` | Docker network model with scopes (environment, shared, proxy, system) |
| `src/Models/ResourceNetwork.php` | Polymorphic pivot: resource-to-network membership |
| `src/Policies/NetworkPolicy.php` | Permission policy for managed networks |
| `src/Livewire/NetworkManager.php` | Server-level network management UI |
| `src/Livewire/ResourceNetworks.php` | Per-resource network assignment UI |
| `src/Livewire/NetworkSettings.php` | Settings page for network policies |
| `src/Http/Controllers/Api/NetworkController.php` | REST API for network management |
| `src/Overrides/Helpers/proxy.php` | Phase 2: proxy network in proxy compose config |
| `src/Overrides/Helpers/docker.php` | Phase 2: `traefik.docker.network` label injection |

## Configuration

```env
CORELIX_NETWORK_MANAGEMENT=true       # Enable network management
CORELIX_NETWORK_ISOLATION_MODE=environment  # none, environment, strict
CORELIX_PROXY_ISOLATION=false         # Enable dedicated proxy network
CORELIX_SWARM_OVERLAY_ENCRYPTION=false # IPsec for Swarm overlays
CORELIX_MAX_NETWORKS=200              # Safety limit per server
```

## Related Docs

- [PRD.md](PRD.md) — Product Requirements Document
- [plan.md](plan.md) — Technical implementation plan
- [Network Management System Plan](../../plans/network-management-system.md) — Original architecture plan

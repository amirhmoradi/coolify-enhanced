# Network Management — Product Requirements Document

## Problem Statement

Coolify v4 deploys all containers on a single flat Docker network (`coolify`). This means:
- Every container can reach every other container by name — no isolation between environments
- The reverse proxy (Traefik/Caddy) has network-level access to all containers, including internal databases
- No support for cross-environment communication control
- Docker Swarm deployments lack overlay network management

## Goals

1. **Environment isolation**: Resources in the same environment communicate by container name; cross-environment requires explicit opt-in
2. **Proxy isolation**: Reverse proxy only accesses resources with public FQDNs
3. **Shared networks**: User-created networks for explicit cross-environment communication
4. **Swarm support**: Automatic overlay networks for multi-host Docker Swarm clusters
5. **Zero-overlay Phase 1**: Environment isolation without modifying any Coolify source files

## Solution Design

### Phase 1: Environment Network Isolation (zero overlays)

- Post-deployment hook via `ApplicationDeploymentQueue::updated()` model observer
- After Coolify deploys normally, `NetworkReconcileJob` connects containers to environment networks via `docker network connect`
- Per-environment bridge networks: `ce-env-{environment_uuid}`
- Three isolation modes: `none`, `environment`, `strict`

### Phase 2: Proxy Network Isolation (two overlays)

- Dedicated proxy network: `ce-proxy-{server_uuid}`
- `proxy.php` overlay: proxy compose config includes proxy network
- `docker.php` overlay: injects `traefik.docker.network` label for correct routing
- Prevents intermittent 502 errors in multi-network setups

### Phase 3: Docker Swarm Support (zero new overlays)

- Automatic overlay network driver for Swarm servers
- `docker service update --network-add` for Swarm task network assignment
- Optional IPsec encryption via `--opt encrypted`
- Manager node validation before overlay network creation

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Post-deployment hooks over overlays | Avoids overlaying the 4,130-line `ApplicationDeploymentJob.php` |
| `docker network connect` for standalone | Idempotent, works on running containers |
| `docker service update --network-add` for Swarm | Only way to modify Swarm task networks |
| `2>/dev/null || true` with post-inspection verification | Handles "already exists" / "already connected" gracefully while detecting actual failures |
| `escapeshellarg()` on all shell command interpolations | Prevents command injection via subnet/gateway/network names |
| Network limit (default 200) | Docker bridge networks consume iptables rules; performance degrades beyond 200 |

## Risks

1. **Upstream overlay sync**: `proxy.php` (604 lines) and `docker.php` (1536 lines) must track Coolify upstream changes
2. **Swarm rolling restarts**: `docker service update --network-add` triggers rolling updates (10-30s per service)
3. **Strict mode breakage**: Disconnecting from `coolify` network breaks services relying on default connectivity
4. **iptables degradation**: Each bridge network adds ~10 iptables rules; performance degrades at scale

## Testing Checklist

- [ ] Environment network created on first deployment
- [ ] Containers connected to environment network after deployment
- [ ] Cross-environment communication blocked without shared network
- [ ] Shared network enables cross-environment communication
- [ ] Proxy isolation prevents proxy from accessing non-FQDN resources
- [ ] `traefik.docker.network` label injected correctly
- [ ] Swarm overlay networks created on manager nodes
- [ ] Network limit enforced
- [ ] Feature disabled: stock Coolify behavior preserved
- [ ] Subnet/gateway validation prevents command injection

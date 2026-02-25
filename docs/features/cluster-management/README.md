# Cluster Management & Swarm Visualization

> **Status:** Planning · **Feature flag:** `COOLIFY_CLUSTER_MANAGEMENT=true`

## Overview

Adds comprehensive Docker Swarm cluster management and monitoring to Coolify via coolify-enhanced. Provides a Portainer-class cluster dashboard, node management, service/task monitoring, cluster visualizer, Swarm secrets/configs, and structured deployment configuration — all integrated into Coolify's existing UI patterns.

Designed with an orchestrator abstraction layer (`ClusterDriverInterface`) so Kubernetes support can be added later without rewriting UI or business logic.

## Key Capabilities

### Phase 1: Visibility (Read-Only)
- **Cluster Dashboard** — Status cards (health, nodes, services, tasks), resource usage bars, full node listing table
- **Service/Task Viewer** — Table of all Swarm services with inline task expansion (click row → see tasks per node, their status, errors)
- **Cluster Visualizer** — Dual view toggle:
  - **Grid View** (Portainer-style): columns per node, color-coded task blocks (green=running, yellow=pending, red=failed, blue=updating)
  - **Topology View**: interactive node hierarchy with manager/worker relationships, proportional sizing
- **Event Log** — Real-time stream of Swarm events (service updates, node status changes, task rescheduling) with type/node/service filters
- **Auto-detection** — Discovers Swarm clusters from existing manager servers; auto-links workers by IP matching

### Phase 2: Management (Write Operations)
- **Add Node Wizard** — Step-by-step: choose role → copy join command → wait for node to appear
- **Node Actions** — Drain / Activate / Pause availability, Promote / Demote role, Remove node, Label management (add/remove key-value pairs)
- **Structured Swarm Config** — Replaces Coolify's raw YAML textarea with a form: mode (replicated/global), replicas, update policy, rollback policy, placement constraints builder, resource limits, health check, restart policy
- **Service Operations** — Inline scaling, one-click rollback, force update

### Phase 3: Swarm Primitives
- **Secrets Management** — Create / Rotate / Remove Docker secrets with dependency checking
- **Configs Management** — Create / View / Remove Docker configs with content editor
- **Event Persistence** — Collect and store events in DB with configurable retention

### Phase 4: Integration
- **Resource ↔ Service Linking** — Show Swarm task status on Coolify Application/Service detail pages
- **Alerts** — Node down, service degraded, update failure → Coolify notification channels
- **MCP Server** — ~12 new tools for AI-driven cluster management

## Architecture

```
┌───────────────────────────────────────────────────────┐
│  UI (Livewire + Blade)                                │
│  ClusterDashboard, NodeManager, ServiceViewer,        │
│  Visualizer, Secrets, Configs, Events, SwarmConfig    │
├───────────────────────────────────────────────────────┤
│  ClusterDriverInterface (Contract)                    │
│  getNodes(), getServices(), getTasks(), drainNode(),  │
│  scaleService(), createSecret(), getEvents() ...      │
├───────────────────────────────────────────────────────┤
│  SwarmClusterDriver                                   │
│  SSH → docker CLI → JSON parse → cached results       │
├───────────────────────────────────────────────────────┤
│  Future: KubernetesClusterDriver                      │
│  kubectl / K8s API                                    │
└───────────────────────────────────────────────────────┘
```

### Data Model

```
Cluster
├── uuid, name, description, type (swarm|kubernetes)
├── status (healthy|degraded|unreachable)
├── manager_server_id → Server
├── team_id → Team
├── settings (encrypted: join tokens, sync config)
├── metadata (cached: swarm_id, node_count, etc.)
│
├── servers() → Server[] (via server.cluster_id)
├── events() → ClusterEvent[]
└── secrets() → SwarmSecret[] (local tracking)
```

## Navigation

```
Sidebar
├── Dashboard
├── Projects
├── Servers
│   └── Server Detail → "Cluster: production" link
├── Clusters ← NEW
│   ├── Cluster List (card view)
│   └── Cluster Dashboard
│       ├── Overview
│       ├── Services
│       ├── Visualizer
│       ├── Secrets (Phase 3)
│       ├── Configs (Phase 3)
│       └── Events
└── Settings
```

## Components

| Component | File | Purpose |
|-----------|------|---------|
| ClusterList | `src/Livewire/ClusterList.php` | Card grid of team's clusters |
| ClusterDashboard | `src/Livewire/ClusterDashboard.php` | Main dashboard with tab navigation |
| ClusterNodeManager | `src/Livewire/ClusterNodeManager.php` | Node table with action dropdowns |
| ClusterAddNode | `src/Livewire/ClusterAddNode.php` | Step-by-step add node wizard |
| ClusterServiceViewer | `src/Livewire/ClusterServiceViewer.php` | Service table with inline task expansion |
| ClusterVisualizer | `src/Livewire/ClusterVisualizer.php` | Dual-view task/topology visualizer |
| ClusterEvents | `src/Livewire/ClusterEvents.php` | Filterable event log |
| ClusterSecrets | `src/Livewire/ClusterSecrets.php` | Secret CRUD |
| ClusterConfigs | `src/Livewire/ClusterConfigs.php` | Config CRUD with content viewer |
| SwarmConfigForm | `src/Livewire/SwarmConfigForm.php` | Structured deployment config (replaces YAML) |
| SwarmTaskStatus | `src/Livewire/SwarmTaskStatus.php` | Inline task status for resource pages |

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/clusters` | List team's clusters |
| POST | `/api/v1/clusters` | Create cluster |
| GET | `/api/v1/clusters/{uuid}` | Get cluster details |
| PATCH | `/api/v1/clusters/{uuid}` | Update cluster settings |
| DELETE | `/api/v1/clusters/{uuid}` | Delete cluster |
| POST | `/api/v1/clusters/{uuid}/sync` | Force metadata sync |
| GET | `/api/v1/clusters/{uuid}/nodes` | List nodes |
| POST | `/api/v1/clusters/{uuid}/nodes/{id}/drain` | Drain node |
| POST | `/api/v1/clusters/{uuid}/nodes/{id}/activate` | Activate node |
| POST | `/api/v1/clusters/{uuid}/nodes/{id}/promote` | Promote to manager |
| POST | `/api/v1/clusters/{uuid}/nodes/{id}/demote` | Demote to worker |
| DELETE | `/api/v1/clusters/{uuid}/nodes/{id}` | Remove node |
| GET | `/api/v1/clusters/{uuid}/services` | List services |
| POST | `/api/v1/clusters/{uuid}/services/{id}/scale` | Scale service |
| POST | `/api/v1/clusters/{uuid}/services/{id}/rollback` | Rollback service |
| POST | `/api/v1/clusters/{uuid}/services/{id}/force-update` | Force update service |
| GET | `/api/v1/clusters/{uuid}/services/{id}/tasks` | Get service tasks |
| GET | `/api/v1/clusters/{uuid}/events` | Get cluster events |
| GET | `/api/v1/clusters/{uuid}/visualizer` | Get visualizer data |
| GET | `/api/v1/clusters/{uuid}/secrets` | List secrets |
| POST | `/api/v1/clusters/{uuid}/secrets` | Create secret |
| DELETE | `/api/v1/clusters/{uuid}/secrets/{id}` | Remove secret |
| GET | `/api/v1/clusters/{uuid}/configs` | List configs |
| POST | `/api/v1/clusters/{uuid}/configs` | Create config |
| DELETE | `/api/v1/clusters/{uuid}/configs/{id}` | Remove config |

## Configuration

```env
COOLIFY_CLUSTER_MANAGEMENT=true           # Enable feature
COOLIFY_CLUSTER_SYNC_INTERVAL=60          # Metadata sync interval (seconds)
COOLIFY_CLUSTER_CACHE_TTL=30              # Docker API cache TTL (seconds)
COOLIFY_CLUSTER_EVENT_RETENTION=7         # Event retention (days)
```

## Overlay Files

| Phase | Overlay | Reason |
|-------|---------|--------|
| 1 | None | Pure read-only dashboard, no Coolify modifications needed |
| 2 | `swarm.blade.php` | Replace raw YAML textarea with structured Swarm config form |

**Phase 1 achieves full cluster visibility with zero overlays.**

## Key Design Decisions

| Decision | Why |
|----------|-----|
| Orchestrator abstraction (`ClusterDriverInterface`) | K8s-ready without rewrite |
| SSH-based Docker CLI (not Docker API socket) | Consistent with Coolify's pattern |
| JSON output parsing | Structured, version-independent |
| Explicit Cluster model (not implicit grouping) | Supports naming, settings, multi-type |
| Auto-detect + user-editable | Minimal setup, full customization |
| Livewire polling (not WebSocket) | Consistent with Coolify; no extra infra |
| Cached with configurable TTL | Prevents SSH storm on dashboards |
| Team-scoped clusters | Inherits Coolify's multi-tenancy |

## Troubleshooting

- **"Too many redirects" when cluster management is enabled (non-Swarm node)** — Coolify's catch-all route can match `/clusters` and `/cluster/{uuid}` if package web routes are registered after it. See [REDIRECT_LOOP_INVESTIGATION.md](REDIRECT_LOOP_INVESTIGATION.md) for root cause and fix. Use [VALIDATION_ACTION_PLAN.md](VALIDATION_ACTION_PLAN.md) to validate after applying the fix.

## Related Documentation

- [PRD.md](PRD.md) — Full product requirements with UX mockups
- [plan.md](plan.md) — Technical implementation plan with code snippets
- [Network Management](../network-management/) — Related Phase 3 Swarm overlay network support
- [CLAUDE.md](../../../CLAUDE.md) — Project architecture reference
- [AGENTS.md](../../../AGENTS.md) — AI agent implementation guide

## Competitive Research

This feature draws from:
- **Portainer** — Cluster visualizer, node management table, service detail sections, secrets/configs CRUD
- **Dokploy** — Add node wizard with join command, per-app cluster settings, clean scaling UX

See [PRD.md appendix](PRD.md#appendix-competitive-research-notes) for detailed competitive analysis.

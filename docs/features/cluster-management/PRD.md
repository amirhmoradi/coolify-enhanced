# Cluster Management & Swarm Visualization — Product Requirements Document

## Problem Statement

Coolify v4 has **experimental** Docker Swarm support but lacks any cluster management or monitoring UI. Current state:

1. **No cluster dashboard** — No overview of Swarm topology, node health, or cluster-wide metrics
2. **No node management** — Servers are marked as manager/worker via checkboxes, but there's no node listing, health monitoring, drain/activate controls, or label management
3. **No service/task visibility** — Once deployed via `docker stack deploy`, there's no way to see task distribution, replica status, or rolling update progress
4. **No cluster visualizer** — No visual representation of which tasks run on which nodes
5. **Primitive Swarm config** — Placement constraints are a raw YAML textarea; no structured UI for replicas, rollback, health checks, or resource limits
6. **Known bugs** — Container naming conflicts with replicas, network scope mismatch (`local` vs `swarm`), wrong deploy command in some code paths
7. **No Swarm primitives** — Docker Swarm secrets and configs are not manageable through the UI
8. **No event/log visibility** — No Swarm event stream or audit trail for cluster operations

### Competitive Landscape

| Feature | Coolify v4 | Dokploy | Portainer | **Target (coolify-enhanced)** |
|---------|-----------|---------|-----------|-------------------------------|
| Cluster dashboard | None | Basic node table | Full dashboard | Full dashboard with cards + metrics |
| Node management | Checkbox only | Add node wizard | Full CRUD + drain/labels | Full CRUD + drain/labels + wizard |
| Service/task viewer | None | None | Table + inline expansion | Table + detail drawer + filters |
| Cluster visualizer | None | None | Column-per-node grid | Dual view (grid + topology) |
| Per-app Swarm config | YAML textarea | Structured UI | Full collapsible sections | Structured UI with smart defaults |
| Secrets/configs | None | None | Full CRUD | Full CRUD with rotation |
| Event log | None | None | Activity log | Real-time event stream |
| Multi-cluster | N/A | Single cluster | Multiple environments | Explicit cluster entities |

## Goals

1. **Cluster visibility** — Real-time dashboard showing cluster health, node status, resource usage, and task distribution
2. **Node management** — Add/remove/drain/activate nodes, manage labels, promote/demote roles
3. **Service & task monitoring** — View all Swarm services, their tasks, replica status, and rolling update progress
4. **Cluster visualizer** — Visual representation of task placement across nodes (dual view: grid + topology)
5. **Per-resource Swarm configuration** — Structured UI for replicas, placement, rollback, health checks, resource limits
6. **Swarm primitives** — Manage Docker secrets and configs through the UI
7. **K8s-ready architecture** — Abstract cluster operations behind an orchestrator-agnostic interface so Kubernetes support can be added later without a rewrite
8. **Zero-overlay approach where possible** — Use Docker API queries and post-deployment hooks; minimize Coolify file overlays

## Non-Goals (Explicit Exclusions)

- Kubernetes support (architecture-ready, not implemented)
- Multi-cluster federation (each cluster is independent)
- Custom orchestrators beyond Docker Swarm
- Replacing Portainer (complement Coolify's deployment workflow, not replicate every Portainer feature)

## Solution Design

### Architecture: Orchestrator Abstraction Layer

```
┌─────────────────────────────────────────────────────────┐
│  UI Layer (Livewire Components + Blade Views)           │
│  ClusterDashboard, NodeManager, ServiceViewer,          │
│  ClusterVisualizer, SwarmConfig, SecretsManager         │
├─────────────────────────────────────────────────────────┤
│  Orchestrator Interface (Contract)                      │
│  ClusterDriverInterface                                 │
│  - getNodes(), getServices(), getTasks()                │
│  - drainNode(), activateNode(), promoteNode()           │
│  - scaleService(), updateService(), rollbackService()   │
│  - createSecret(), createConfig()                       │
│  - getClusterInfo(), getEvents()                        │
├─────────────────────────────────────────────────────────┤
│  Swarm Driver (implements ClusterDriverInterface)       │
│  SwarmClusterDriver                                     │
│  - Executes docker CLI commands via SSH on manager node │
│  - Parses JSON output from docker inspect/service/node  │
│  - Caches results with configurable TTL                 │
├─────────────────────────────────────────────────────────┤
│  Future: K8s Driver (implements ClusterDriverInterface) │
│  KubernetesClusterDriver                                │
│  - kubectl / K8s API calls                              │
│  - Maps K8s concepts to common interface                │
└─────────────────────────────────────────────────────────┘
```

### Data Model: Explicit Cluster Entity

```
Cluster (new model)
├── id, uuid, name, description
├── type: enum('swarm', 'kubernetes')  // K8s-ready
├── status: enum('healthy', 'degraded', 'unreachable')
├── manager_server_id: FK → Server (primary manager)
├── team_id: FK → Team
├── settings: JSON (driver-specific config)
│   ├── swarm_join_token_worker (encrypted)
│   ├── swarm_join_token_manager (encrypted)
│   ├── auto_detect_nodes: bool
│   └── metrics_retention_hours: int
├── metadata: JSON (cached cluster info)
│   ├── swarm_id
│   ├── created_at (Swarm creation time)
│   ├── node_count
│   └── service_count
├── timestamps
│
├── servers() → HasMany Server (via server.cluster_id)
├── networks() → HasMany ManagedNetwork
└── secrets() → HasMany SwarmSecret
```

**Auto-detection flow:** When a server is marked as Swarm manager, the system queries `docker info --format json` to discover the Swarm ID, join tokens, and existing nodes. A `Cluster` record is auto-created (or matched to existing by Swarm ID). Worker nodes are auto-discovered and linked via `docker node ls`.

### Phase 1: Cluster Dashboard & Node Visibility (Read-Only)

**Goal:** See your cluster's health, nodes, and basic metrics at a glance.

#### 1.1 Cluster Model & Auto-Detection
- New `Cluster` Eloquent model with migration
- `ClusterDetectionService` — queries `docker info` on Swarm manager servers, auto-creates/updates `Cluster` records
- `ClusterSyncJob` — periodic background sync of cluster metadata (node count, health, tokens)
- Add `cluster_id` nullable FK to `servers` table (replaces integer `swarm_cluster` field)

#### 1.2 Cluster Dashboard Page
- **Route:** `/cluster/{uuid}` (new top-level navigation item)
- **Layout:** Dashboard with summary cards + node listing

**Dashboard Cards (top row):**
```
┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  Cluster      │ │  Nodes       │ │  Services    │ │  Tasks       │
│  Status       │ │              │ │              │ │              │
│  ● Healthy    │ │  5 total     │ │  12 running  │ │  47 running  │
│               │ │  3 managers  │ │  2 updating  │ │  3 pending   │
│  Swarm v24.0  │ │  2 workers   │ │  0 failed    │ │  1 failed    │
└──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘
```

**Resource Usage Cards (second row):**
```
┌────────────────────────┐ ┌────────────────────────┐ ┌────────────────────────┐
│  CPU Usage             │ │  Memory Usage          │ │  Disk Usage            │
│  ████████░░  78%       │ │  ██████░░░░  58%       │ │  ████░░░░░░  42%       │
│  Across 5 nodes        │ │  23.4 / 40 GB          │ │  168 / 400 GB          │
└────────────────────────┘ └────────────────────────┘ └────────────────────────┘
```

**Node Listing (table below cards):**

| Status | Name | Role | IP Address | Docker | CPU | Memory | Tasks | Availability |
|--------|------|------|------------|--------|-----|--------|-------|--------------|
| ● Ready | node-1 | Manager (Leader) | 10.0.1.1 | 24.0.7 | 4 cores | 8 GB | 12 | Active |
| ● Ready | node-2 | Manager | 10.0.1.2 | 24.0.7 | 4 cores | 8 GB | 10 | Active |
| ● Ready | node-3 | Manager | 10.0.1.3 | 24.0.7 | 8 cores | 16 GB | 15 | Active |
| ● Ready | worker-1 | Worker | 10.0.2.1 | 24.0.7 | 16 cores | 32 GB | 8 | Active |
| ○ Down | worker-2 | Worker | 10.0.2.2 | 24.0.7 | 16 cores | 32 GB | 0 | Active |

**Data source:** `docker node ls --format json` + `docker node inspect` on the manager server via SSH.

#### 1.3 Cluster Sidebar Integration

When viewing a Server that belongs to a cluster, its sidebar shows:
- "Cluster: {name}" link to the cluster dashboard
- Node role badge (Manager/Worker/Leader)

New top-level navigation:
- "Clusters" menu item (only visible when clusters exist)
- Lists all clusters the user's team has access to

#### 1.4 Service & Task Viewer (Read-Only)

**Route:** `/cluster/{uuid}/services`

**Service Listing Table:**

| Service | Image | Mode | Replicas | Ports | Updated |
|---------|-------|------|----------|-------|---------|
| web-app | myapp:latest | replicated | 3/3 | 80→8080 | 2 min ago |
| api | api:v2.1 | replicated | 2/3 ⚠ | 443→3000 | 5 min ago |
| monitoring | prom:latest | global | 5/5 | 9090 | 1 hr ago |
| redis | redis:7 | replicated | 1/1 | — | 3 hrs ago |

**Click to expand → Task Detail:**

| Task ID | Node | Status | Started | Error |
|---------|------|--------|---------|-------|
| abc123 | node-1 | ● Running | 2 hrs ago | — |
| def456 | node-3 | ● Running | 2 hrs ago | — |
| ghi789 | worker-1 | ○ Pending | 30s ago | no suitable node |

**Data source:** `docker service ls --format json`, `docker service ps <id> --format json`

#### 1.5 Cluster Visualizer

**Route:** `/cluster/{uuid}/visualizer`

**Dual View with Toggle:**

**View 1: Task Grid (Portainer-style)**
```
┌─ node-1 (manager) ─┐ ┌─ node-2 (manager) ─┐ ┌─ worker-1 ────────┐
│ ┌─────────────────┐ │ │ ┌─────────────────┐ │ │ ┌─────────────────┐│
│ │ ■ web-app.1     │ │ │ │ ■ web-app.2     │ │ │ │ ■ web-app.3     ││
│ │   Running 2h    │ │ │ │   Running 2h    │ │ │ │   Running 2h    ││
│ └─────────────────┘ │ │ └─────────────────┘ │ │ └─────────────────┘│
│ ┌─────────────────┐ │ │ ┌─────────────────┐ │ │ ┌─────────────────┐│
│ │ ■ api.1         │ │ │ │ ■ api.2         │ │ │ │ ■ redis.1       ││
│ │   Running 5m    │ │ │ │   Running 5m    │ │ │ │   Running 3h    ││
│ └─────────────────┘ │ │ └─────────────────┘ │ │ └─────────────────┘│
│ ┌─────────────────┐ │ │ ┌─────────────────┐ │ │                    │
│ │ ■ monitoring.1  │ │ │ │ ■ monitoring.2  │ │ │  CPU: ████░░ 62%  │
│ │   Running 1h    │ │ │ │   Running 1h    │ │ │  RAM: ██░░░░ 34%  │
│ └─────────────────┘ │ │ └─────────────────┘ │ │                    │
│  CPU: ██████░░ 78%  │ │  CPU: █████░░░ 65%  │ │                    │
│  RAM: ████░░░░ 52%  │ │  RAM: ████░░░░ 48%  │ │                    │
└─────────────────────┘ └─────────────────────┘ └────────────────────┘
```

- Color-coded task blocks: green=running, yellow=pending, red=failed, blue=updating
- Auto-refresh every 5s (configurable)
- Filter by service, status, node
- Click task → drawer with logs, inspect data

**View 2: Topology Map**
```
                    ┌──────────────┐
              ┌─────│  node-1 (L)  │─────┐
              │     │  ● 3 tasks   │     │
              │     └──────────────┘     │
              │            │             │
     ┌────────────┐  ┌────────────┐  ┌────────────┐
     │  node-2    │  │  node-3    │  │  worker-1  │
     │  ● 3 tasks │  │  ● 4 tasks │  │  ● 2 tasks │
     └────────────┘  └────────────┘  └────────────┘
                                        │
                                  ┌────────────┐
                                  │  worker-2  │
                                  │  ○ DOWN    │
                                  └────────────┘
```

- Interactive: click node to see its tasks, hover for quick stats
- Visual connection lines between managers (Raft consensus)
- Node size proportional to task count or resource usage
- Dead nodes shown dimmed/red

### Phase 2: Node & Service Management (Write Operations)

**Goal:** Manage nodes and control service deployment from the UI.

#### 2.1 Add Node Wizard
- **"Add Node" button** on cluster dashboard
- Step 1: Choose role (manager/worker)
- Step 2: Display join command with token (auto-fetched from `docker swarm join-token`)
- Step 3: User runs command on target server; UI polls `docker node ls` until new node appears
- Step 4: Optional — configure node labels immediately
- If the target server is already registered in Coolify, auto-link the `Server` record to the `Cluster`

#### 2.2 Node Actions
- **Drain** — `docker node update --availability drain <node>` (with confirmation: "X tasks will be rescheduled")
- **Activate** — `docker node update --availability active <node>`
- **Pause** — `docker node update --availability pause <node>`
- **Promote** — `docker node promote <node>` (worker → manager)
- **Demote** — `docker node demote <node>` (manager → worker, with safety: can't demote last manager)
- **Remove** — `docker node rm <node>` (requires drain first, safety checks)
- **Label management** — Add/remove labels via `docker node update --label-add/--label-rm`
  - Structured UI: key-value table with add/remove buttons
  - Common labels suggested: `zone`, `disk`, `gpu`, `tier`

#### 2.3 Per-Resource Swarm Configuration UI

Replace the raw YAML textarea with a structured form:

**Replicas & Scaling**
```
┌─ Deployment Configuration ───────────────────────────────────────┐
│                                                                   │
│  Mode:     (●) Replicated  ( ) Global                            │
│  Replicas: [  3  ] ▲▼                                            │
│                                                                   │
│  ☐ Only deploy to worker nodes                                   │
│                                                                   │
│  Update Policy                                                    │
│  ├─ Parallelism:    [  1  ]  (tasks updated simultaneously)      │
│  ├─ Delay:          [ 10s ]  (between batches)                   │
│  ├─ Failure action: [Rollback ▼]                                 │
│  ├─ Monitor:        [ 5s  ]  (after update, before next)         │
│  └─ Order:          [Start-first ▼]                              │
│                                                                   │
│  Rollback Policy                                                  │
│  ├─ Parallelism:    [  1  ]                                      │
│  ├─ Failure action: [Pause ▼]                                    │
│  └─ Order:          [Stop-first ▼]                               │
│                                                                   │
│  Placement Constraints                                            │
│  ┌──────────────────┬────┬───────────────────┬─────┐             │
│  │ node.role        │ == │ worker            │  ✕  │             │
│  │ node.labels.zone │ == │ us-east-1a        │  ✕  │             │
│  │ [+ Add constraint]                              │             │
│  └──────────────────────────────────────────────────┘             │
│                                                                   │
│  Placement Preferences                                            │
│  ┌──────────────────────────────────────────┬─────┐              │
│  │ Spread: node.labels.zone                 │  ✕  │              │
│  │ [+ Add preference]                              │              │
│  └──────────────────────────────────────────────────┘             │
│                                                                   │
│  Resource Limits                                                  │
│  ├─ CPU limit:       [  2.0  ] cores                             │
│  ├─ Memory limit:    [ 512   ] MB                                │
│  ├─ CPU reservation: [  1.0  ] cores                             │
│  └─ Memory reservation: [ 256 ] MB                               │
│                                                                   │
│  Health Check                                                     │
│  ├─ Command:  [ CMD-SHELL curl -f http://localhost/ || exit 1 ]  │
│  ├─ Interval: [ 30s ]  Timeout: [ 10s ]                         │
│  ├─ Retries:  [  3  ]  Start period: [ 40s ]                    │
│  └─ Start interval: [ 5s ]                                       │
│                                                                   │
│  Restart Policy                                                   │
│  ├─ Condition: [On-failure ▼]                                    │
│  ├─ Delay:     [ 5s  ]                                           │
│  ├─ Max attempts: [ 3 ]                                          │
│  └─ Window:    [ 120s ]                                          │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

- Values pre-populated with Docker defaults
- Smart validation (e.g., reservations ≤ limits)
- "Advanced" toggle to show/hide less common options
- Generates valid `deploy:` YAML section from structured inputs

#### 2.4 Service Operations
- **Scale** — Inline replica count adjustment with `docker service scale`
- **Rollback** — One-click `docker service rollback` with confirmation
- **Force update** — `docker service update --force` to redistribute tasks
- **Remove** — `docker service rm` with confirmation

### Phase 3: Swarm Primitives & Advanced (Full CRUD)

**Goal:** Complete Swarm management: secrets, configs, events, advanced operations.

#### 3.1 Swarm Secrets Management

**Route:** `/cluster/{uuid}/secrets`

| Name | Created | Updated | Target Services |
|------|---------|---------|-----------------|
| db-password | 2 days ago | 2 days ago | api, web-app |
| tls-cert | 1 month ago | 1 week ago | proxy |
| api-key | 3 months ago | 3 months ago | api |

- **Create secret** — Name + value (textarea or file upload), optional labels
- **Rotate secret** — Creates new version, updates all referencing services (rolling update)
- **Remove** — With dependency check (which services reference it)
- **Note:** Docker secrets are immutable; "update" creates a new secret and re-attaches

#### 3.2 Swarm Configs Management

**Route:** `/cluster/{uuid}/configs`

Similar to secrets but values are visible (not sensitive):
- **Create** — Name + content (code editor with syntax highlighting) + labels
- **Update** — Creates new version, updates referencing services
- **View** — Full content visible (unlike secrets)
- **Remove** — With dependency check

#### 3.3 Cluster Event Log

**Route:** `/cluster/{uuid}/events`

Real-time event stream from `docker system events --filter type=service --filter type=node --format json`:

```
┌─ Event Log ──────────────────────────────────────────────────────┐
│ ● 14:32:01  service/web-app  update started (replicas 2→3)      │
│ ● 14:32:05  node/worker-1    task web-app.3 assigned             │
│ ● 14:32:08  container/abc123 started on worker-1                 │
│ ● 14:32:12  service/web-app  update completed                    │
│ ● 14:30:00  node/worker-2    status changed to down              │
│ ● 14:30:01  service/api      task api.2 rescheduled to node-1    │
│ ○ 14:25:00  node/worker-2    status changed to ready             │
│ ...                                                               │
│                                     [Load more] [Auto-refresh ●] │
└──────────────────────────────────────────────────────────────────┘
```

- Filterable by event type, service, node
- Color-coded: green=start, red=stop/fail, yellow=warning, blue=update
- Persistent event storage in DB (configurable retention)
- Real-time via Livewire polling (5s interval)

#### 3.4 Stack Management

Manage docker stacks (groups of services deployed together):
- List stacks: `docker stack ls`
- View stack services: `docker stack services <name>`
- Deploy stack from compose file
- Remove stack

### Phase 4: Integration & Polish

#### 4.1 Coolify Resource ↔ Swarm Service Linking
- Auto-detect when a Coolify Application/Service maps to a Swarm service
- Show Swarm task status on the resource detail page
- Link from service viewer back to Coolify resource

#### 4.2 Cluster Alerts & Notifications
- Node down alert
- Service degraded (replicas < desired) alert
- Rolling update failure alert
- Integrates with Coolify's existing notification channels (Discord, Slack, email, Telegram)

#### 4.3 MCP Server Extensions
- New tool modules: `cluster.ts`, `swarm-nodes.ts`, `swarm-services.ts`, `swarm-secrets.ts`
- Enables AI assistants to monitor and manage clusters via natural language
- Example: "Scale web-app to 5 replicas", "Drain worker-2 for maintenance", "Show cluster health"

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Orchestrator abstraction layer (`ClusterDriverInterface`) | Enables future K8s support without rewriting UI/business logic |
| Explicit `Cluster` model (not implicit grouping) | Supports naming, settings, multi-manager, future K8s clusters |
| Auto-detect + user-editable clusters | Reduces manual setup while allowing customization |
| SSH-based Docker CLI execution (not Docker API) | Consistent with Coolify's existing server communication pattern |
| JSON output parsing (`--format json`) | Reliable, structured, version-independent parsing |
| Livewire polling (not WebSocket) | Consistent with Coolify's architecture; no additional infrastructure |
| Dual visualizer (grid + topology) | Grid for task distribution (practical), topology for infrastructure overview (intuitive) |
| Phased rollout (4 phases) | Visibility first, then management; reduces risk |
| Cached cluster data with TTL | Prevents SSH storm on page load; stale data acceptable for dashboards |
| Team-scoped clusters | Inherits Coolify's team model; clusters belong to teams |

## User Experience

### Navigation Flow

```
Sidebar
├── Dashboard
├── Projects
│   └── ...
├── Servers
│   └── Server Detail
│       ├── ... (existing tabs)
│       └── Cluster: "production" → links to cluster dashboard
├── Clusters ← NEW
│   ├── Cluster List (card view)
│   │   └── Cluster Dashboard
│   │       ├── Overview (cards + node table)
│   │       ├── Services (service/task table)
│   │       ├── Visualizer (grid/topology toggle)
│   │       ├── Secrets
│   │       ├── Configs
│   │       └── Events
│   └── "Create Cluster" / "Auto-detect"
└── Settings
    └── ...
```

### Key UX Principles

1. **Information hierarchy** — Most important data (health, alerts) at the top; drill down for detail
2. **Auto-refresh without flicker** — Livewire morph updates, no full page reload
3. **Progressive disclosure** — Basic config visible; advanced options behind toggles
4. **Actionable states** — Every status indicator leads to a remediation action
5. **Consistent with Coolify** — Use Coolify's existing component library (`x-forms.*`, `x-modal`, etc.)
6. **Color language** — Green=healthy/running, Yellow=warning/pending, Red=error/down, Blue=updating/info
7. **Confirmation for destructive actions** — Drain, remove, scale down all require explicit confirmation

### Cluster List Page

```
┌─ Clusters ─────────────────────────────────────────────────────────┐
│                                                                     │
│  [+ Create Cluster]  [Auto-detect from Servers]                    │
│                                                                     │
│  ┌─────────────────────────────────┐  ┌─────────────────────────── │
│  │  ● production                   │  │  ● staging                 │
│  │  Swarm · 5 nodes · 12 services │  │  Swarm · 2 nodes · 4 svc  │
│  │  CPU: ████░░ 62%  RAM: ███░░ 48│  │  CPU: ██░░░░ 28%  RAM: █░ │
│  │  Last sync: 30s ago            │  │  Last sync: 45s ago        │
│  └─────────────────────────────────┘  └─────────────────────────── │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Files Modified

### New Files (estimated)

| File | Purpose |
|------|---------|
| `src/Models/Cluster.php` | Cluster Eloquent model |
| `src/Models/SwarmSecret.php` | Swarm secret model (for DB tracking) |
| `src/Models/SwarmConfig.php` | Swarm config model (for DB tracking) |
| `src/Contracts/ClusterDriverInterface.php` | Orchestrator abstraction interface |
| `src/Drivers/SwarmClusterDriver.php` | Docker Swarm implementation of driver |
| `src/Services/ClusterService.php` | High-level cluster operations |
| `src/Services/ClusterDetectionService.php` | Auto-detection of Swarm clusters |
| `src/Jobs/ClusterSyncJob.php` | Background cluster metadata sync |
| `src/Jobs/ClusterEventCollectorJob.php` | Event stream collection |
| `src/Livewire/ClusterDashboard.php` | Dashboard component |
| `src/Livewire/ClusterList.php` | Cluster listing page |
| `src/Livewire/ClusterNodeManager.php` | Node management component |
| `src/Livewire/ClusterServiceViewer.php` | Service/task viewer component |
| `src/Livewire/ClusterVisualizer.php` | Visualizer component |
| `src/Livewire/ClusterSecrets.php` | Secrets management component |
| `src/Livewire/ClusterConfigs.php` | Configs management component |
| `src/Livewire/ClusterEvents.php` | Event log component |
| `src/Livewire/SwarmConfigForm.php` | Per-resource Swarm config form |
| `src/Http/Controllers/Api/ClusterController.php` | Cluster REST API |
| `src/Policies/ClusterPolicy.php` | Cluster access policy |
| `database/migrations/*_create_clusters_table.php` | Cluster migration |
| `database/migrations/*_create_swarm_secrets_table.php` | Secrets tracking |
| `database/migrations/*_create_swarm_configs_table.php` | Configs tracking |
| `database/migrations/*_create_cluster_events_table.php` | Event storage |
| `database/migrations/*_add_cluster_id_to_servers.php` | Server-cluster link |
| `resources/views/livewire/cluster-*.blade.php` | All Blade views |
| `routes/web.php` | Cluster web routes |
| `routes/api.php` | Cluster API routes |
| `mcp-server/src/tools/clusters.ts` | MCP cluster tools |
| `mcp-server/src/tools/swarm-nodes.ts` | MCP node management tools |
| `mcp-server/src/tools/swarm-services.ts` | MCP service management tools |

### Modified Files (estimated)

| File | Change |
|------|--------|
| `src/CoolifyEnhancedServiceProvider.php` | Register cluster routes, components, jobs |
| `config/coolify-enhanced.php` | Cluster management config options |
| `src/Overrides/Views/components/server/sidebar.blade.php` | Add cluster link |

### Overlay Files (new, Phase 2+)

| File | Change |
|------|--------|
| `src/Overrides/Views/livewire/project/application/swarm.blade.php` | Replace YAML textarea with structured form |

## Risks

1. **SSH overhead** — Frequent `docker node ls` / `docker service ls` calls can strain SSH connections. Mitigate with aggressive caching (30-60s TTL) and batch queries
2. **Coolify Swarm bugs** — Upstream Swarm bugs (replica naming, deploy command) may surface. Document workarounds, don't try to fix Coolify core
3. **Large clusters** — 50+ nodes / 200+ services need pagination, lazy loading, and efficient queries
4. **Event stream volume** — Busy clusters generate hundreds of events/minute. Need retention policies and efficient storage
5. **Concurrent management** — Multiple users managing same cluster need optimistic locking or at least stale-data warnings
6. **Overlay file maintenance** — `swarm.blade.php` overlay must track upstream Coolify changes
7. **K8s abstraction leakage** — Swarm and K8s have fundamentally different concepts; abstraction must be carefully designed to not over-generalize

## Testing Checklist

### Phase 1
- [ ] Cluster auto-detected from Swarm manager server
- [ ] Cluster dashboard shows correct node count and status
- [ ] Node table reflects actual `docker node ls` output
- [ ] Service table reflects `docker service ls` output
- [ ] Task expansion shows per-service task details
- [ ] Visualizer grid correctly maps tasks to nodes
- [ ] Topology view shows manager/worker hierarchy
- [ ] Auto-refresh updates without flicker
- [ ] Cluster accessible only by owning team
- [ ] Feature flag disabled: no cluster UI visible

### Phase 2
- [ ] Add node wizard generates correct join command
- [ ] New node appears in UI after joining
- [ ] Drain/activate/pause changes node availability
- [ ] Promote/demote changes node role
- [ ] Node labels can be added/removed
- [ ] Structured Swarm config generates valid deploy YAML
- [ ] Scale operation changes replica count
- [ ] Rollback reverts to previous version
- [ ] Force update redistributes tasks

### Phase 3
- [ ] Secrets can be created and attached to services
- [ ] Secret rotation triggers service update
- [ ] Configs can be created with content visible
- [ ] Event log streams real-time cluster events
- [ ] Event filtering works correctly
- [ ] Event retention policy enforced

### Phase 4
- [ ] Coolify resources linked to Swarm services
- [ ] Alerts fire for node down / service degraded
- [ ] MCP cluster tools work end-to-end
- [ ] All operations work via API

## Appendix: Competitive Research Notes

### Portainer Strengths to Adopt
- Cluster visualizer with auto-refresh is immediately useful
- Inline task expansion (no page navigation) reduces context switching
- Node availability controls (drain/active/pause) as dropdown, not separate buttons
- Service detail as single scrollable page with collapsible sections

### Dokploy Strengths to Adopt
- Add node wizard with copy-paste join command is very intuitive
- Per-application cluster settings (replicas, placement) in the app config page
- Clean separation of vertical vs horizontal scaling concepts

### Dokploy Weaknesses to Avoid
- Separate "Swarm" menu disconnected from servers is confusing
- No service/task visibility after deployment
- No cluster visualizer

### Portainer Weaknesses to Avoid
- Agent requirement adds operational complexity (we use SSH instead)
- Dense UI with many tabs can overwhelm new users
- No integration with deployment workflows (Portainer is monitoring-focused)

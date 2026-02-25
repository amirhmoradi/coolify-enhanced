# Coolify Enhanced

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Build and Publish Docker Image](https://github.com/amirhmoradi/coolify-enhanced/actions/workflows/docker-publish.yml/badge.svg)](https://github.com/amirhmoradi/coolify-enhanced/actions/workflows/docker-publish.yml)
[![Docker Image](https://img.shields.io/badge/ghcr.io-coolify--enhanced-blue)](https://ghcr.io/amirhmoradi/coolify-enhanced)

**The missing enterprise features for Coolify v4 — granular permissions, encrypted backups, volume/config backups, custom service templates, enhanced database classification, Docker network isolation, and Docker Swarm cluster management.**

Coolify Enhanced is a drop-in addon for [Coolify](https://coolify.io) that adds access control, backup security, template extensibility, and Docker Swarm cluster management that teams need when running Coolify in production. It installs in under 2 minutes, requires zero changes to your existing setup, and can be removed cleanly at any time.

> If you're coming from Dokploy, Portainer, CapRover, or another open-source PaaS and chose Coolify for its simplicity — this addon fills the remaining gaps for team management and backup security.

---

## Why Coolify Enhanced?

Coolify v4 is an excellent self-hosted PaaS, but ships with a few limitations for production team use:

| Gap | Without This Addon | With Coolify Enhanced |
|-----|--------------------|-----------------------|
| **Access control** | All team members see and can manage all projects | Project-level and environment-level permissions with View Only, Deploy, and Full Access roles |
| **Backup encryption** | Database backups stored as plaintext on S3 | NaCl SecretBox encryption (XSalsa20 + Poly1305) — military-grade, at rest |
| **Volume & config backups** | Only database dumps are backed up | Docker volumes, app configuration, and full resource backups on schedule |
| **Service templates** | Limited to Coolify's built-in 200+ templates | Add unlimited custom templates from any GitHub repository |
| **Database classification** | Many databases (Memgraph, Milvus, Qdrant, etc.) misclassified as applications | 50+ additional database images recognized; explicit label and comment overrides |
| **Network isolation** | All containers share a single flat Docker network | Per-environment bridge networks, dedicated proxy network, cross-env shared networks, Docker Swarm overlay support |
| **MCP Server (AI Assistant Integration)** | None | 119+ MCP tools covering all Coolify (and Coolify Enhanced) API endpoints |
| **Cluster management** | Checkbox-only Swarm config, no dashboard, no node management | Full cluster dashboard, node management, service/task viewer, visualizer, secrets/configs, structured deploy config |
| **UI theme** | Single default look | Optional corporate-grade modern theme (light/dark) — Settings > Appearance, off by default |


All features are **independent** — enable only what you need. When disabled, Coolify behaves exactly as stock.

---

## Table of Contents

- [Features at a Glance](#features-at-a-glance)
- [Screenshots](#screenshots)
- [Quick Start](#quick-start)
- [Feature Details](#feature-details)
  - [Granular Permissions](#1-granular-permissions)
  - [Encrypted S3 Backups](#2-encrypted-s3-backups)
  - [Resource Backups (Volumes, Config, Full)](#3-resource-backups)
  - [Custom Template Sources](#4-custom-template-sources)
  - [Enhanced Database Classification](#5-enhanced-database-classification)
  - [Network Management](#6-network-management)
  - [MCP Server](#7-mcp-server)
  - [Cluster Management](#8-cluster-management)
  - [Enhanced UI Theme](#9-enhanced-ui-theme)
- [Installation](#installation)
- [Configuration](#configuration)
- [API Reference](#api-reference)
- [Upgrading & Reverting](#upgrading--reverting)
- [Architecture Overview](#architecture-overview)
- [FAQ](#faq)
- [Contributing](#contributing)
- [License](#license)

---

## Features at a Glance

### Granular Permissions
- Project-level and environment-level access control
- Three permission tiers: **View Only**, **Deploy**, **Full Access**
- Visual Access Matrix UI on the Team Admin page
- Environment-level overrides that cascade from project permissions
- Owner/Admin bypass — only Members and Viewers are restricted
- Full REST API for automation

### Encrypted S3 Backups
- Per-storage encryption using rclone's crypt backend (NaCl SecretBox)
- Transparent encrypt-on-upload, decrypt-on-download
- Optional filename and directory name encryption
- Configurable per S3 storage destination
- Backward compatible — existing unencrypted backups keep working

### Resource Backups
- **Volume backups** — tar.gz snapshots of Docker named volumes and bind mounts
- **Configuration backups** — JSON export of resource settings, environment variables, docker-compose, labels
- **Full backups** — volumes + configuration in one shot
- **Coolify instance backups** — full `/data/coolify` directory backup
- Independent cron scheduling per resource
- Retention policies (by count, by age, or by storage)
- Same S3 upload pipeline with optional encryption

### Enhanced Database Classification
- **50+ additional database images** recognized out of the box (graph, vector, time-series, document, search, column-family, NewSQL, OLAP)
- **`coolify.database` Docker label** — explicitly mark any service as a database (or not) in your compose files
- **`# type: database` comment convention** — template-level metadata that automatically classifies all services
- **Wire-compatible backup support** — YugabyteDB, TiDB, FerretDB, Percona, and Apache AGE get native dump-based backups
- **Expanded port mapping** — "Make Publicly Available" works for all recognized database types
- **Meaningful error messages** — unsupported backup types guide users to `custom_type` or Resource Backups

### Network Management
- **Per-environment isolation** — each environment gets its own Docker bridge network
- **Shared networks** — user-created cross-environment communication channels
- **Proxy network isolation** — dedicated proxy network prevents the reverse proxy from accessing internal services
- **Docker Swarm support** — automatic overlay networks for multi-host clusters with optional IPsec encryption
- **Three isolation modes**: `none` (manual only), `environment` (auto per-env), `strict` (disconnects from default)
- **Post-deployment hooks** — zero overlay files for Phase 1; containers joined to networks after Coolify deploys normally
- Server-level network management UI + per-resource network assignment UI
- REST API for automation

### Custom Template Sources
- Add any GitHub repository (public or private) as a template source
- Templates appear alongside Coolify's built-in services on the New Resource page
- **Filter by source** — dropdown to show All, Coolify Official, or a specific custom source
- Auto-sync on configurable schedule (default: every 6 hours)
- Same YAML format as Coolify's built-in templates — zero learning curve
- Name collision handling — built-in templates always take precedence
- Deployed services are independent of template sources (write-once)

### Cluster Management (Docker Swarm)
- **Cluster Dashboard** — real-time status cards, node listing, service/task counts
- **Node Management** — drain/activate/pause, promote/demote, add/remove nodes, label CRUD
- **Service/Task Viewer** — table with inline task expansion, scaling, rollback, force update
- **Cluster Visualizer** — dual view: Portainer-style column-per-node grid + topology map
- **Swarm Secrets & Configs** — full CRUD for Docker Swarm primitives
- **Structured Swarm Config** — form replacing raw YAML textarea for replicas, placement, rollback, health checks, resource limits
- **Event Log** — real-time cluster event stream with type/action filters
- **Auto-detection** — discovers Swarm clusters from existing manager servers
- **K8s-ready architecture** — orchestrator abstraction layer (`ClusterDriverInterface`) for future Kubernetes support
- **20 MCP tools** for AI-driven cluster management
- **Status notifications** — alerts when clusters degrade or become unreachable

### Enhanced UI Theme
- **Optional corporate-grade theme** — modern, sophisticated color palette; light and dark modes
- **CSS and minimal JS only** — no structural changes; same layout and framework (Tailwind)
- **Settings > Appearance** — single toggle "Use enhanced theme"; **disabled by default**
- **Instance-wide** — preference stored in database; reload the page after toggling to see changes

### MCP Server (AI Assistant Integration)
- **119+ MCP tools** wrapping all Coolify API endpoints for AI-driven infrastructure management
- Works with **Claude Desktop**, **Cursor**, **VS Code Copilot**, **Kiro IDE**, and any MCP-compatible client
- **Core tools** (72) work with standard Coolify — no addon required
- **Enhanced tools** (47) for permissions, resource backups, custom templates, network management, and cluster management
- **Auto-detection** of coolify-enhanced features — falls back gracefully to core tools
- **Tool annotations** — read-only, destructive, and idempotent hints for AI safety
- Install via `npx @amirhmoradi/coolify-enhanced-mcp`

#### Quick Setup (Claude Desktop)

Add to your `claude_desktop_config.json`:

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

See [mcp-server/README.md](mcp-server/README.md) for full documentation and the complete tool reference.

---

## Screenshots

### Access Matrix — Team Permission Management

<!-- SCREENSHOT: Access Matrix on the Team > Admin page showing the user/project/environment permission grid with dropdowns -->
<img width="2372" height="1956" alt="coolify-granular-user-permissions" src="https://github.com/user-attachments/assets/ab96988f-5f77-4dbe-8371-d9f093f8ccf7" />

*The Access Matrix provides a unified view of all users, projects, and environments with per-cell permission controls.*

### S3 Storage — Encryption Settings

<!-- SCREENSHOT: S3 Storage detail page showing the encryption toggle, password fields, filename encryption dropdown -->
<img width="2360" height="1938" alt="coolify-s3-pathprefix-encryption" src="https://github.com/user-attachments/assets/8a6f2713-83d6-4153-a32b-0f1f928ba613" />

*Enable per-storage encryption with a single toggle. Configure encryption password, salt, and filename encryption mode.*

### Resource Backups — Application Configuration Page

<!-- SCREENSHOT: Application configuration page with Resource Backups sidebar item selected, showing backup schedules and executions -->
<img width="2364" height="1584" alt="coolify-resource-app-full-new-backup" src="https://github.com/user-attachments/assets/846964fa-ea29-4442-8579-f0f2409c544f" />

<img width="2362" height="1386" alt="coolify-resource-app-full-executions-backup" src="https://github.com/user-attachments/assets/5672339f-6886-452d-9310-de52d4284e2a" />


*Schedule volume, configuration, or full backups for any application, database, or service with independent cron expressions.*

### Resource Backups — Server Overview

<!-- SCREENSHOT: Server sidebar with Resource Backups item, showing all resource backups for the server -->
<img width="2366" height="1960" alt="coolify-instance-file-backups" src="https://github.com/user-attachments/assets/977c6e2c-4802-4aaf-8a85-5b944a9dbfc8" />

*View and manage all resource backups across a server from a single page.*

### Settings — Restore & Import

<!-- SCREENSHOT: Settings > Restore page showing the JSON backup viewer and env var bulk import -->
<img width="2362" height="1852" alt="import-server-backups" src="https://github.com/user-attachments/assets/8aa300f0-2703-4975-9fbe-1e33a15c3de1" />

*Browse configuration backup contents, bulk-import environment variables, and follow step-by-step restoration guides.*

### Custom Template Sources — Settings Page

<!-- SCREENSHOT: Settings > Templates page showing added sources with sync status, template count, and template previews -->
<img width="2368" height="1076" alt="custom-template-sources-settings" src="https://github.com/user-attachments/assets/7a1e67c6-7b7e-471f-b413-54b387c2c2a5" />

*Add GitHub repositories as template sources, view sync status, preview discovered templates, and trigger manual syncs.*

### New Resource Page — Source Filter & Labels

<!-- SCREENSHOT: New Resource page showing service cards with custom template source labels and the source filter dropdown -->
<img width="2364" height="1030" alt="New-Resources-From-Custom-templates-sources" src="https://github.com/user-attachments/assets/41fbeb33-832c-4aec-a3de-646ba738b2c7" />

*Custom templates appear alongside built-in services with source labels. Use the source filter dropdown to narrow by origin.*

### Instance File Backup — Settings Page

<!-- SCREENSHOT: Settings > Backup page showing the Instance File Backup section below the native database backup -->
<img width="2366" height="1960" alt="coolify-instance-file-backups" src="https://github.com/user-attachments/assets/fcb1e267-1408-437b-8095-d775181f61e2" />


*Schedule full `/data/coolify` directory backups (excluding backup directories) from the Settings page.*

### Network Management

<!-- SCREENSHOT: Network Management -->
#### Server level Network Management
<img width="2544" height="1814" alt="coolify-server-network-management" src="https://github.com/user-attachments/assets/e2194a98-b49f-4931-8618-cf7816e5a0cf" />

#### Project level Network Settings

<img width="2556" height="1806" alt="coolify-project-level-networks" src="https://github.com/user-attachments/assets/365fb628-4a35-4e8a-ad6e-53ac7cabd8b5" />

### MCP Server



---

## Quick Start

### One-Line Install (Coolify Already Running)

```bash
git clone https://github.com/amirhmoradi/coolify-enhanced.git
cd coolify-enhanced
sudo bash install.sh --install-addon
```

### Fresh Server (Coolify + Addon)

```bash
git clone https://github.com/amirhmoradi/coolify-enhanced.git
cd coolify-enhanced
sudo bash install.sh --install-coolify --install-addon --unattended
```

### Manual Install (2 Files)

Create `/data/coolify/source/docker-compose.custom.yml`:

```yaml
services:
  coolify:
    image: ghcr.io/amirhmoradi/coolify-enhanced:latest
    environment:
      - COOLIFY_ENHANCED=true
```

Restart Coolify:

```bash
cd /data/coolify/source && bash upgrade.sh
```

> Coolify natively supports `docker-compose.custom.yml` — it is merged with the main compose file and survives upgrades.

That's it. Navigate to **Team > Admin** to see the Access Matrix, open any **S3 Storage** page for encryption settings, check **Settings > Templates** to add custom template sources, and enable `COOLIFY_CLUSTER_MANAGEMENT=true` to access the cluster dashboard.

---

## Feature Details

### 1. Granular Permissions

Coolify v4 gives all team members full access to everything. Coolify Enhanced adds project-level and environment-level access control so you can restrict who sees, deploys, and manages each project.

#### Permission Levels

| Level | View | Deploy | Manage | Delete |
|-------|:----:|:------:|:------:|:------:|
| **View Only** | Yes | — | — | — |
| **Deploy** | Yes | Yes | — | — |
| **Full Access** | Yes | Yes | Yes | Yes |

#### Role Hierarchy

| Role | Behavior |
|------|----------|
| **Owner** | Full access to everything (bypasses all checks) |
| **Admin** | Full access to everything (bypasses all checks) |
| **Member** | Requires explicit project/environment access |
| **Viewer** | Requires explicit project/environment access |

#### How Permission Resolution Works

```
1. Is the user an Owner or Admin?  -->  Allow (bypass)
2. Does an environment-level override exist?  -->  Use it
3. Does a project-level permission exist?  -->  Use it (cascades to environments)
4. No access record found  -->  Deny
```

Environment overrides take precedence over project permissions. This lets you give a developer Deploy access to a project but restrict production to View Only.

#### Access Matrix UI

The Access Matrix is injected into the **Team > Admin** page. It provides:

- A grid of all users vs. all projects/environments
- Per-cell dropdown to set permission level (None, View Only, Deploy, Full Access)
- **All/None** buttons per row (set all resources for a user) and per column (set all users for a resource)
- User search/filter
- Visual indicators for role bypass, inheritance, and current level

<!-- SCREENSHOT: Close-up of the Access Matrix grid with dropdowns and All/None buttons -->
![Access Matrix Close-up](<!-- INSERT_SCREENSHOT_URL: access-matrix-closeup.png -->)

---

### 2. Encrypted S3 Backups

Every S3 storage destination in Coolify can independently enable encryption. When enabled, all database backups to that storage are encrypted before upload using [rclone's crypt backend](https://rclone.org/crypt/) — NaCl SecretBox (XSalsa20 + Poly1305), an industry-standard authenticated encryption scheme.

#### Configuration Options

| Setting | Description |
|---------|-------------|
| **Enable Encryption** | Toggle on/off per storage |
| **Encryption Password** | Main encryption key (required) |
| **Salt (password2)** | Optional secondary key for extra security |
| **Filename Encryption** | `off` (default), `standard`, or `obfuscate` |
| **Directory Name Encryption** | Encrypt directory names on S3 (requires filename encryption) |
| **S3 Path Prefix** | Optional path prefix for multi-instance bucket sharing |

#### How It Works

```
Backup Job starts
     |
     v
S3 Storage has encryption enabled?
     |
  No --> mc upload (standard Coolify behavior)
  Yes --> rclone crypt upload:
           1. Build env config (S3 remote + crypt remote + obscured passwords)
           2. Write env file to server
           3. Docker run rclone container with --env-file
           4. Upload encrypted backup
           5. Mark execution as is_encrypted=true
           6. Cleanup env file + container
```

- Each backup execution tracks its encryption status (`is_encrypted` field)
- Restore operations auto-detect whether a backup is encrypted and use rclone to decrypt
- Existing unencrypted backups continue to work — no migration needed

> **Warning**: If you lose the encryption password, your encrypted backups cannot be recovered. Store it securely.

<!-- SCREENSHOT: S3 storage page showing encryption form with all options filled in -->
![Encryption Form](<!-- INSERT_SCREENSHOT_URL: encryption-form-detail.png -->)

---

### 3. Resource Backups

Coolify's built-in backup system only covers database dumps. Coolify Enhanced extends this to support **Docker volume snapshots**, **configuration exports**, and **full backups** for Applications, Services, and Databases.

#### Backup Types

| Type | What It Backs Up | Format |
|------|------------------|--------|
| **Volume** | All Docker named volumes and bind mounts for the resource | `tar.gz` per volume |
| **Configuration** | Resource model, environment variables, persistent storages, docker-compose, custom labels | `JSON` |
| **Full** | Both volume + configuration in one execution | `tar.gz` + `JSON` |
| **Coolify Instance** | Full `/data/coolify` directory (minus backups and metrics) | `tar.gz` |

#### Key Capabilities

- **Independent scheduling** — Each resource gets its own cron expression
- **Retention policies** — Limit by count, by age (days), or by storage destination
- **S3 upload** — Same pipeline as database backups, with optional encryption
- **Restore/Import** — Browse JSON backup contents, bulk-import environment variables, step-by-step restoration guide
- **Feature flag safety** — Queued jobs exit silently if the feature is disabled

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Application/Database/Service > Configuration** | "Resource Backups" sidebar item with backup manager |
| **Server > Resource Backups** | All resource backups for the server in one page |
| **Settings > Backup** | "Instance File Backup" section for Coolify directory backups |
| **Settings > Restore** | Browse and import configuration backups |

#### Backup Directory Structure

```
/data/coolify/backups/resources/{team-slug}-{team-id}/{resource-name}-{uuid}/
```

<!-- SCREENSHOT: Resource backup manager showing a scheduled backup with recent executions and download links -->
![Resource Backup Manager](<!-- INSERT_SCREENSHOT_URL: resource-backup-detail.png -->)

---

### 4. Custom Template Sources

Coolify ships with 200+ one-click service templates. Custom Template Sources lets you extend this list with templates from any GitHub repository — public or private.

#### How It Works

1. You point Coolify Enhanced at a GitHub repository containing YAML template files
2. The system fetches and validates templates using the same format as Coolify's built-in ones
3. Templates are cached locally and merged into the New Resource service list
4. Each template card shows a small source label so you know where it came from
5. A **source filter dropdown** lets you filter by All, Coolify Official, or a specific source

```
GitHub Repository
       |
       v
SyncTemplateSourceJob
  |-- List YAML files via GitHub Contents API
  |-- Download and parse metadata headers
  |-- Validate docker-compose structure
  |-- Cache to /data/coolify/custom-templates/{source-uuid}/templates.json
       |
       v
New Resource page --> get_service_templates()
  |-- Load built-in templates
  |-- Load custom source caches
  |-- Handle name collisions (built-in always wins)
  |-- Return merged collection with _source metadata
```

#### Adding a Source

**Via UI:** Go to **Settings > Templates** > click **+ Add Source** > enter repository URL, branch, folder path, and optional auth token > click **Save & Sync**.

**Via API:**

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Templates",
    "repository_url": "https://github.com/myorg/coolify-templates",
    "branch": "main",
    "folder_path": "templates/compose"
  }' \
  https://your-coolify.example.com/api/v1/template-sources
```

#### Template Format

Templates use the exact same YAML format as Coolify's built-in templates — a docker-compose file with comment metadata headers:

```yaml
# documentation: https://docs.example.com/
# slogan: A brief description of your service.
# tags: monitoring,devops
# category: monitoring
# logo: svgs/myservice.svg
# port: 8080

services:
  myservice:
    image: myorg/myservice:latest
    environment:
      - SERVICE_FQDN_MYSERVICE_8080
      - DATABASE_URL=${DATABASE_URL:?}
      - DEBUG=${DEBUG:-false}
    volumes:
      - myservice-data:/app/data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 5s
      timeout: 20s
      retries: 10
```

See the full [Custom Template Creation Guide](docs/custom-templates.md) for metadata headers, magic environment variables, volume patterns, logos, and a complete example.

#### Source Filter

The New Resource page includes a **Filter by source** dropdown next to the existing category filter:

| Option | Shows |
|--------|-------|
| **All Sources** | All services (built-in + custom) |
| **Coolify Official** | Only Coolify's built-in templates |
| **\<Source Name\>** | Only templates from that specific source |

The dropdown only appears when at least one custom template source has been configured.

#### Key Behaviors

- **Write-once**: After deploying a service, the compose YAML lives in the database. Removing a source has zero impact on running services.
- **Name collisions**: Built-in templates always win. Custom templates with matching names get a `-{source-slug}` suffix.
- **Auto-sync**: Configurable cron schedule (default: every 6 hours). Manual sync also available.
- **Private repos**: Add a GitHub Personal Access Token for private repository access.
- **Rate limits**: Unauthenticated GitHub API: 60 req/hr. Authenticated: 5,000 req/hr.

<!-- SCREENSHOT: Settings > Templates page with an expanded source showing its template list -->
![Template Sources Expanded](<!-- INSERT_SCREENSHOT_URL: template-sources-expanded.png -->)

---

### 5. Enhanced Database Classification

Coolify classifies service containers as either `ServiceDatabase` or `ServiceApplication` based on a hardcoded image list. Many modern databases — Memgraph, Milvus, Qdrant, Cassandra, Neo4j, and dozens more — are missing from this list, causing them to be misclassified as applications. This breaks features like "Make Publicly Available", scheduled backups, database import, and backup UI visibility.

Coolify Enhanced solves this through three complementary mechanisms.

#### Mechanism 1: Expanded Image List

The `DATABASE_DOCKER_IMAGES` constant is expanded with ~50 additional database images, organized by category:

| Category | Databases Added |
|----------|----------------|
| **Graph** | Memgraph, Neo4j, ArangoDB, OrientDB, Dgraph, JanusGraph, Apache AGE |
| **Vector** | Milvus, Qdrant, Weaviate, ChromaDB |
| **Time-series** | QuestDB, TDengine, VictoriaMetrics, InfluxDB |
| **Document** | CouchDB, Couchbase, FerretDB, SurrealDB, RavenDB, RethinkDB |
| **Search** | Elasticsearch, OpenSearch, Meilisearch, Typesense, Manticore, Solr |
| **Key-value** | Valkey, Memcached |
| **Column-family** | Cassandra, ScyllaDB |
| **NewSQL** | CockroachDB, YugabyteDB, TiDB, Vitess |
| **OLAP** | Druid, Pinot, DuckDB |
| **Other** | EdgeDB, EventStoreDB, ImmuDB, Percona, FoundationDB, Hazelcast, Ignite |

These are automatically recognized when deploying one-click services — no configuration needed.

#### Mechanism 2: `coolify.database` Docker Label

For databases not in the expanded list (or to explicitly override classification), add the `coolify.database` label to your docker-compose services:

```yaml
services:
  my-custom-db:
    image: myorg/custom-database:latest
    labels:
      coolify.database: "true"
```

This works in:
- Custom template YAML files
- Any docker-compose file deployed as a Coolify service
- Per-service granularity (e.g., mark the database container but not the admin UI)

Set to `"false"` to force-classify a database image as an application:

```yaml
services:
  redis-cache:
    image: redis:7
    labels:
      coolify.database: "false"  # Treat as application, not database
```

The label check is case-insensitive and accepts `true/false/1/0/yes/no/on/off`.

#### Mechanism 3: `# type: database` Comment Convention

Template authors can add a `# type:` metadata header to classify all services in the template at once:

```yaml
# documentation: https://memgraph.com/docs
# slogan: Real-time graph database
# tags: graph,database,cypher
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    # ...
```

This injects `coolify.database: "true"` labels into all services in the compose YAML during parsing. Per-service labels take precedence over the template-level header, so multi-service templates can have mixed classifications:

```yaml
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    # Gets coolify.database=true from # type: database

  memgraph-lab:
    image: memgraph/lab:latest
    labels:
      coolify.database: "false"  # Override: this is a web UI, not a database
```

#### Wire-Compatible Backup Support

Databases that speak a standard protocol AND produce correct backups with standard dump tools are automatically mapped to their parent backup type:

| Database | Mapped To | Dump Tool | Backup UI | Import |
|----------|-----------|-----------|:---------:|:------:|
| **YugabyteDB** | PostgreSQL | `pg_dump` | Yes | Yes |
| **Apache AGE** | PostgreSQL | `pg_dump` | Yes | Yes |
| **TiDB** | MySQL | `mysqldump` | Yes | Yes |
| **Percona Server** | MySQL | `mysqldump` | Yes | Yes |
| **FerretDB** | MongoDB | `mongodump` | Yes | Yes |

Databases where standard tools fail (CockroachDB, Vitess, ScyllaDB) are intentionally **not** mapped. For these, use [Resource Backups](#3-resource-backups) for volume-level backups, or set `custom_type` on the service database if you know your setup is compatible.

#### Setting `custom_type` for Manual Override

If a database isn't automatically recognized or wire-compatible, you can set `custom_type` directly on the ServiceDatabase record to force a specific backup type:

```bash
# Via Coolify's database or tinker — set custom_type to a supported type
# Supported types: postgresql, mysql, mariadb, mongodb
```

This overrides all automatic detection and enables dump-based backups for that service.

#### Expanded Port Mapping ("Make Publicly Available")

The "Make Publicly Available" feature (database proxy) now supports all recognized database types with correct default ports. If a database type isn't in the built-in map, the system:

1. Looks up the base image name in an expanded port map (~50 entries)
2. Tries partial string matching for variants (e.g., `timescaledb-ha` matches `timescale`)
3. Extracts the port from the service's docker-compose configuration
4. Provides a helpful error message if all methods fail

#### Multi-Port Database Proxy

Some databases (e.g., Memgraph, Neo4j) expose multiple ports — one for database queries and others for admin UIs, log viewers, or alternate protocols. Coolify's built-in proxy only supports a single port. Coolify Enhanced adds multi-port proxy support via the `coolify.proxyPorts` Docker label.

**How to use:** Add the `coolify.proxyPorts` label to your service in the docker-compose template:

```yaml
services:
  memgraph:
    image: memgraph/memgraph:latest
    labels:
      coolify.proxyPorts: "7687:bolt,7444:log-viewer"
```

The label format is `"internalPort:label,internalPort:label,..."`. Each entry declares an internal container port and a human-readable label.

**What happens in the UI:**

When you view the service database in Coolify, instead of a single "Make Publicly Available" toggle, you'll see a port mapping table:

| Port | Label | Enabled | Public Port |
|------|-------|---------|-------------|
| 7687 | bolt | Toggle | 17687 |
| 7444 | log-viewer | Toggle | 17444 |

Each port can be independently enabled/disabled with its own public port number. The system creates a single nginx proxy container that handles all enabled ports.

**Key behaviors:**

- When the `coolify.proxyPorts` label is absent, the UI shows the stock single-port toggle (fully backward compatible)
- Port configurations are stored in the `proxy_ports` JSON column on the service database
- All enabled ports share one nginx proxy container with multiple `stream` server blocks
- Disabling all ports stops and removes the proxy container
- Public URLs for all enabled ports are displayed in the UI

#### What Works Automatically

| Feature | Recognized DBs (expanded list) | Label/Comment Override | Wire-Compatible | Other DBs |
|---------|:-----------------------------:|:---------------------:|:--------------:|:---------:|
| Correct classification | Yes | Yes | Yes | Via label |
| "Make Publicly Available" | Yes | Yes | Yes | Yes (port map) |
| Multi-port proxy | Via `coolify.proxyPorts` label | Via `coolify.proxyPorts` label | Via `coolify.proxyPorts` label | Via `coolify.proxyPorts` label |
| Backup UI visible | — | — | Yes | Set `custom_type` |
| Dump-based backups | — | — | Yes | Set `custom_type` |
| Import UI visible | — | — | Yes | Set `custom_type` |
| Volume-level backups | Yes | Yes | Yes | Yes (Resource Backups) |

---

### 6. Network Management

Coolify's default networking puts all containers on a single flat `coolify` Docker network. This means every container can reach every other container by name — no isolation between environments, projects, or teams.

Coolify Enhanced adds per-environment Docker network isolation, a dedicated proxy network, shared cross-environment networks, and Docker Swarm overlay support.

#### Three Isolation Modes

| Mode | Behavior |
|------|----------|
| **`none`** | Manual only — no auto-created networks; users manage via UI/API |
| **`environment`** (default) | Each environment gets its own Docker bridge network (`ce-env-{uuid}`). Resources in the same environment communicate by container name. Cross-environment requires explicit shared networks |
| **`strict`** | Like `environment`, but also disconnects containers from the default `coolify` network. Only use when all services are properly assigned |

#### Proxy Network Isolation (Phase 2)

When enabled, a dedicated proxy network (`ce-proxy-{server_uuid}`) separates the reverse proxy from internal services. Only resources with FQDNs join it. This prevents Traefik/Caddy from having network-level access to backend databases and internal services.

The system automatically injects `traefik.docker.network` labels during label generation, preventing intermittent 502 errors in multi-network setups.

#### Docker Swarm Support (Phase 3)

For Swarm clusters, the system automatically:
- Creates overlay networks (instead of bridge) with `--attachable` flag
- Uses `docker service update --network-add` for network assignment (Swarm tasks can't use `docker network connect`)
- Batches network changes into single service updates to minimize rolling restarts
- Optionally enables IPsec encryption between Swarm nodes via `COOLIFY_SWARM_OVERLAY_ENCRYPTION=true`

#### How It Works

```
Resource deployed normally by Coolify
     |
     v
ApplicationDeploymentQueue status → 'finished'
     |
     v
NetworkReconcileJob triggered
     |
     v
1. Ensure environment network exists (ce-env-{uuid})
2. Connect all containers to environment network
3. If resource has FQDN + proxy isolation: connect to proxy network
4. If strict mode: disconnect from default 'coolify' network
5. Update resource_networks pivot table
```

This post-deployment hook approach avoids overlaying Coolify's 4,130-line `ApplicationDeploymentJob.php`.

#### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `COOLIFY_NETWORK_MANAGEMENT` | `false` | Enable/disable network management |
| `COOLIFY_NETWORK_ISOLATION_MODE` | `environment` | Isolation mode: `none`, `environment`, `strict` |
| `COOLIFY_PROXY_ISOLATION` | `false` | Enable dedicated proxy network |
| `COOLIFY_SWARM_OVERLAY_ENCRYPTION` | `false` | Enable IPsec for Swarm overlay networks |
| `COOLIFY_MAX_NETWORKS` | `200` | Max managed networks per server (iptables safety limit) |

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Server > Networks** | Network management with create/delete/sync, Docker networks tab |
| **Resource > Configuration > Networks** | Per-resource network assignment and disconnection |
| **Settings > Networks** | Global network policy configuration |

#### Proxy Migration Workflow

1. Set `COOLIFY_PROXY_ISOLATION=true` in your environment
2. Go to **Server > Networks** and click **Run Proxy Migration**
3. This creates the proxy network, connects the proxy container, and connects all FQDN resources
4. Redeploy all resources (so they get `traefik.docker.network` labels)
5. Optionally click **Cleanup Old Networks** to disconnect the proxy from non-proxy networks

### 7. MCP Server

See [mcp-server/README.md](mcp-server/README.md) for detailed information.

---

### 8. Cluster Management

Coolify v4 has experimental Docker Swarm support but lacks any cluster management or monitoring UI. Coolify Enhanced adds a comprehensive cluster dashboard, node management, service/task monitoring, a dual-view visualizer, Swarm secrets and configs management, and a structured deployment configuration form — all behind a K8s-ready orchestrator abstraction layer.

#### What It Provides

| Capability | Description |
|------------|-------------|
| **Cluster Dashboard** | Status cards (health, nodes, services, tasks), resource usage, full node listing table with auto-refresh |
| **Node Management** | Drain/Activate/Pause availability, Promote/Demote role, Remove node, Label CRUD, Add Node wizard with join command |
| **Service/Task Viewer** | Table of all Swarm services with inline task expansion — click a service to see its tasks per node, status, and errors |
| **Cluster Visualizer** | Dual view toggle — **Grid View** (Portainer-style columns per node with color-coded task blocks) and **Topology View** (interactive node hierarchy with manager/worker relationships) |
| **Swarm Secrets** | Create and remove Docker secrets with local metadata tracking |
| **Swarm Configs** | Create, view content, and remove Docker configs |
| **Structured Swarm Config** | Replaces Coolify's raw YAML textarea with a form: mode (replicated/global), replicas, update/rollback policy, placement constraints, resource limits, health check, restart policy |
| **Event Log** | Real-time stream of Swarm events (service updates, node status changes, task rescheduling) with type/action filters and configurable retention |
| **Auto-detection** | Discovers Swarm clusters from existing manager servers; auto-links workers by IP matching |
| **Status Notifications** | Alerts when clusters degrade (nodes down) or become unreachable, and recovery notifications |
| **MCP Tools** | 20 AI tools for cluster management via the MCP server |

#### Architecture

```
UI (Livewire Components)
    ↓
Cluster::driver()
    ↓
ClusterDriverInterface (Contract)
    ↓
SwarmClusterDriver (SSH → docker CLI → JSON parse → cached results)
    ↓
Future: KubernetesClusterDriver (kubectl / K8s API)
```

The orchestrator abstraction layer means Kubernetes support can be added later by implementing `ClusterDriverInterface` — no UI or business logic changes needed.

#### How It Works

```
1. Auto-detect: Scan team's servers for Swarm managers via `docker info`
2. Create Cluster record (uuid, name, type, team-scoped)
3. Sync metadata: node count, manager count, join tokens (encrypted), service count
4. Background jobs: ClusterSyncJob (metadata refresh), ClusterEventCollectorJob (event stream)
5. Dashboard: Real-time view via Livewire polling with configurable cache TTL
```

All Docker commands execute via SSH through Coolify's `instant_remote_process()`. JSON output format (`--format '{{json .}}'`) ensures reliable parsing. All interpolated values pass through `escapeshellarg()` to prevent command injection.

#### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `COOLIFY_CLUSTER_MANAGEMENT` | `false` | Enable cluster management feature |
| `COOLIFY_CLUSTER_SYNC_INTERVAL` | `60` | Metadata sync interval (seconds) |
| `COOLIFY_CLUSTER_CACHE_TTL` | `30` | Docker API response cache TTL (seconds) |
| `COOLIFY_CLUSTER_EVENT_RETENTION` | `7` | Event log retention (days) |

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Sidebar > Clusters** | Card grid of team's clusters with status indicators and metadata |
| **Cluster > Overview** | Dashboard with status cards, node listing table |
| **Cluster > Nodes** | Node management with action dropdowns and label editor |
| **Cluster > Services** | Service table with inline task expansion, scaling, rollback |
| **Cluster > Visualizer** | Dual-view task placement visualization |
| **Cluster > Events** | Filterable event log with type/action filters |
| **Cluster > Secrets** | Docker secret management |
| **Cluster > Configs** | Docker config management with content viewer |
| **Application > Swarm** | Structured deployment config (when cluster management enabled) |
| **Any Swarm resource** | Inline task status widget showing Swarm task distribution |

#### Key Design Decisions

| Decision | Why |
|----------|-----|
| Orchestrator abstraction (`ClusterDriverInterface`) | K8s-ready without rewrite |
| SSH-based Docker CLI (not Docker API socket) | Consistent with Coolify's pattern |
| Explicit Cluster model (not implicit grouping) | Supports naming, settings, multi-type |
| Livewire polling (not WebSocket) | Consistent with Coolify; no extra infra |
| Cached with configurable TTL | Prevents SSH storm on dashboards |
| Team-scoped clusters | Inherits Coolify's multi-tenancy |

See the full [Cluster Management documentation](docs/features/cluster-management/) for PRD, implementation plan, and detailed feature overview.

### 9. Enhanced UI Theme

An optional corporate-grade modern UI theme with a refined color palette and light/dark modes. **No structural changes** — CSS and minimal JavaScript only; same layout and Tailwind framework.

- **Settings > Appearance** — single toggle "Use enhanced theme"; **disabled by default**.
- **Instance-wide** — preference stored in the database; reload the page after toggling to see changes.
- **Light mode** — warm off-white base (#FAFAF9), soft grays, single accent (indigo).
- **Dark mode** — near-black base (#0F0F0F), layered grays, same accent.

See [Enhanced UI Theme](docs/features/enhanced-ui-theme/README.md) for file list and design docs.

---

## Installation

### Requirements

- Coolify v4.x running on your server
- Docker & Docker Compose
- Root/sudo access

### Interactive Setup

```bash
git clone https://github.com/amirhmoradi/coolify-enhanced.git
cd coolify-enhanced
sudo bash install.sh
```

The interactive menu provides:

1. **Install Coolify** — from the official repository (fresh servers)
2. **Install Enhanced Addon** — add the addon to existing Coolify
3. **Uninstall Enhanced Addon** — cleanly remove the addon
4. **Check Status** — verify what's installed and running
5. **Full Setup** — Coolify + addon in one step

### CLI Arguments (Automation / CI)

```bash
# Fresh server: install everything
sudo bash install.sh --install-coolify --install-addon --unattended

# Existing Coolify: just the addon
sudo bash install.sh --install-addon

# Local build instead of pulling from GHCR
sudo bash install.sh --install-addon --local

# Check installation
sudo bash install.sh --status

# Uninstall
sudo bash install.sh --uninstall
```

Run `sudo bash install.sh --help` for all options.

### Build Locally

```bash
docker build \
  --build-arg COOLIFY_VERSION=latest \
  -t coolify-enhanced:latest \
  -f docker/Dockerfile .
```

### Image Tags

| Tag | Description |
|-----|-------------|
| `latest` | Latest stable release |
| `vX.Y.Z` | Specific release version |
| `coolify-X.Y.Z` | Built against specific Coolify version |
| `sha-XXXXXX` | Specific commit SHA |

### What the Installer Does

1. Verifies Coolify at `/data/coolify/source/`
2. Pulls the pre-built image from GHCR (or builds locally)
3. Creates `docker-compose.custom.yml` with the enhanced image + env var
4. Sets `COOLIFY_ENHANCED=true` in `.env`
5. Restarts Coolify via `upgrade.sh`
6. Verifies the installation

For detailed instructions including manual install, database migrations, and troubleshooting, see the [Installation Guide](docs/installation.md).

---

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `COOLIFY_ENHANCED` | `false` | Master switch — enable/disable all enhanced features |
| `COOLIFY_RCLONE_IMAGE` | `rclone/rclone:latest` | Docker image for rclone operations |
| `COOLIFY_TEMPLATE_SYNC_FREQUENCY` | `0 */6 * * *` | Cron expression for auto-syncing template sources (empty to disable) |
| `COOLIFY_TEMPLATE_CACHE_DIR` | `storage/app/custom-templates` | Cache directory for fetched templates |
| `COOLIFY_NETWORK_MANAGEMENT` | `false` | Enable per-environment Docker network isolation |
| `COOLIFY_PROXY_ISOLATION` | `false` | Enable dedicated proxy network (requires network management) |
| `COOLIFY_NETWORK_ISOLATION_MODE` | `environment` | Isolation mode: `none`, `environment`, or `strict` |
| `COOLIFY_MAX_NETWORKS` | `200` | Maximum managed networks per server |
| `COOLIFY_SWARM_OVERLAY_ENCRYPTION` | `false` | Enable IPsec for Swarm overlay networks |
| `COOLIFY_CLUSTER_MANAGEMENT` | `false` | Enable Docker Swarm cluster management dashboard |
| `COOLIFY_CLUSTER_SYNC_INTERVAL` | `60` | Cluster metadata sync interval in seconds |
| `COOLIFY_CLUSTER_CACHE_TTL` | `30` | Docker API response cache TTL in seconds |
| `COOLIFY_CLUSTER_EVENT_RETENTION` | `7` | Cluster event log retention in days |

> For backward compatibility, `COOLIFY_GRANULAR_PERMISSIONS=true` also enables the addon.

### Config File

Publish for customization:

```bash
php artisan vendor:publish --tag=coolify-enhanced-config
```

This creates `config/coolify-enhanced.php` with options for permission levels, bypass roles, cascade behavior, auto-grant settings, encryption image, and template source limits.

### Feature Flag Behavior

| State | Behavior |
|-------|----------|
| **Enabled** (`COOLIFY_ENHANCED=true`) | All features active — permissions enforced, encryption available, templates loaded, cluster management available |
| **Disabled** (`COOLIFY_ENHANCED=false`) | Standard Coolify behavior — all team members have full access, no encryption, no custom templates, no cluster dashboard |

Permission and encryption settings are preserved in the database when disabled. Re-enabling restores them instantly.

---

## API Reference

All endpoints require Bearer token authentication (Laravel Sanctum).

### Permissions API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/permissions/project` | List project permissions |
| `GET` | `/api/v1/permissions/project/{id}` | Get specific permission |
| `POST` | `/api/v1/permissions/project` | Grant project access |
| `PUT` | `/api/v1/permissions/project/{id}` | Update permission level |
| `DELETE` | `/api/v1/permissions/project/{id}` | Revoke access |
| `POST` | `/api/v1/permissions/project/bulk` | Grant access to all team members |
| `DELETE` | `/api/v1/permissions/project/bulk/{uuid}` | Revoke all project access |
| `GET` | `/api/v1/permissions/environment` | List environment overrides |
| `POST` | `/api/v1/permissions/environment` | Create environment override |
| `DELETE` | `/api/v1/permissions/environment/{id}` | Remove environment override |

### Template Sources API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/template-sources` | List all template sources |
| `POST` | `/api/v1/template-sources` | Create a new source |
| `GET` | `/api/v1/template-sources/{uuid}` | Get source details |
| `PUT` | `/api/v1/template-sources/{uuid}` | Update a source |
| `DELETE` | `/api/v1/template-sources/{uuid}` | Delete a source |
| `POST` | `/api/v1/template-sources/{uuid}/sync` | Trigger sync for one source |
| `POST` | `/api/v1/template-sources/sync-all` | Trigger sync for all sources |

### Resource Backups API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/resource-backups` | List resource backup schedules |
| `POST` | `/api/v1/resource-backups` | Create a backup schedule |
| `GET` | `/api/v1/resource-backups/{id}` | Get schedule details |
| `PUT` | `/api/v1/resource-backups/{id}` | Update a schedule |
| `DELETE` | `/api/v1/resource-backups/{id}` | Delete a schedule |

### Network Management API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/networks/{serverUuid}` | List managed networks for a server |
| `POST` | `/api/v1/networks/{serverUuid}` | Create a shared network |
| `GET` | `/api/v1/networks/{serverUuid}/{networkUuid}` | Get network details + Docker info |
| `DELETE` | `/api/v1/networks/{serverUuid}/{networkUuid}` | Delete a managed network |
| `POST` | `/api/v1/networks/{serverUuid}/sync` | Sync networks from Docker |
| `POST` | `/api/v1/networks/{serverUuid}/proxy/migrate` | Run proxy isolation migration |
| `POST` | `/api/v1/networks/{serverUuid}/proxy/cleanup` | Disconnect proxy from non-proxy networks |
| `GET` | `/api/v1/networks/resource/{type}/{uuid}` | List networks for a resource |
| `POST` | `/api/v1/networks/resource/{type}/{uuid}/attach` | Attach a resource to a network |
| `DELETE` | `/api/v1/networks/resource/{type}/{uuid}/{networkUuid}` | Detach a resource from a network |

### Cluster Management API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/clusters` | List team's clusters |
| `POST` | `/api/v1/clusters` | Create cluster |
| `GET` | `/api/v1/clusters/{uuid}` | Get cluster details |
| `PATCH` | `/api/v1/clusters/{uuid}` | Update cluster settings |
| `DELETE` | `/api/v1/clusters/{uuid}` | Delete cluster |
| `POST` | `/api/v1/clusters/{uuid}/sync` | Force metadata sync |
| `GET` | `/api/v1/clusters/{uuid}/nodes` | List nodes |
| `POST` | `/api/v1/clusters/{uuid}/nodes/{id}/action` | Node action (drain/activate/promote/demote) |
| `DELETE` | `/api/v1/clusters/{uuid}/nodes/{id}` | Remove node |
| `GET` | `/api/v1/clusters/{uuid}/services` | List services |
| `GET` | `/api/v1/clusters/{uuid}/services/{id}/tasks` | Get service tasks |
| `POST` | `/api/v1/clusters/{uuid}/services/{id}/scale` | Scale service |
| `POST` | `/api/v1/clusters/{uuid}/services/{id}/rollback` | Rollback service |
| `GET` | `/api/v1/clusters/{uuid}/events` | Get cluster events |
| `GET` | `/api/v1/clusters/{uuid}/visualizer` | Get visualizer data |
| `GET` | `/api/v1/clusters/{uuid}/secrets` | List secrets |
| `POST` | `/api/v1/clusters/{uuid}/secrets` | Create secret |
| `DELETE` | `/api/v1/clusters/{uuid}/secrets/{id}` | Remove secret |
| `GET` | `/api/v1/clusters/{uuid}/configs` | List configs |
| `POST` | `/api/v1/clusters/{uuid}/configs` | Create config |
| `DELETE` | `/api/v1/clusters/{uuid}/configs/{id}` | Remove config |

For full request/response examples, see the [API Documentation](docs/api.md).

---

## Upgrading & Reverting

### Upgrading Coolify

```bash
cd /data/coolify/source
docker pull ghcr.io/amirhmoradi/coolify-enhanced:latest
bash upgrade.sh
```

Or rebuild locally:

```bash
docker build --build-arg COOLIFY_VERSION=v4.x.x -t coolify-enhanced:latest -f docker/Dockerfile .
```

### Uninstalling

```bash
sudo bash uninstall.sh
```

The uninstaller will:
1. Optionally clean up database tables (prompted)
2. Back up and remove `docker-compose.custom.yml`
3. Remove the environment variable
4. Restart Coolify with the original image

### Reverting Is Non-Destructive

- All projects, resources, users, and deployments remain intact
- Permission and encryption settings stay in the database (harmless, ignored by stock Coolify)
- Encrypted backups remain encrypted on S3 (need the addon or rclone with the same password to restore)
- All team members regain full access to all projects (standard Coolify v4 behavior)

For detailed revert instructions and database cleanup options, see the [Installation Guide](docs/installation.md#reverting-to-original-coolify).

---

## Architecture Overview

Coolify Enhanced is a **Laravel package** that extends Coolify via its service provider system. It does **not** modify Coolify's source code directly.

### How It Integrates

| Mechanism | Used For |
|-----------|----------|
| **Policy overrides** via `Gate::policy()` in `$app->booted()` | Granular permissions — replaces Coolify's permissive defaults |
| **View overlays** — modified copies of Coolify Blade views | Backup sidebar items, encryption form, template source labels |
| **Middleware injection** | Access Matrix on Team Admin page, Clusters sidebar link |
| **File overlays in Docker image** | Encryption-aware backup/restore jobs, custom template loading, expanded database classification |
| **S6-overlay service** | Auto-run database migrations on container start |

### Database Schema (Additions)

```
project_user              environment_user          s3_storages (added columns)
--------------            ----------------          -------------------------
id                        id                        encryption_enabled
project_id (FK)           environment_id (FK)       encryption_password
user_id (FK)              user_id (FK)              encryption_salt
can_view                  can_view                  filename_encryption
can_deploy                can_deploy                directory_name_encryption
can_manage                can_manage                path (S3 prefix)
can_delete                can_delete

scheduled_resource_backups              scheduled_resource_backup_executions
--------------------------              ------------------------------------
id                                      id
resource_type / resource_id             backup_id (FK)
backup_type (volume/config/full/...)    status
frequency (cron)                        size / filename
s3_storage_id                           is_encrypted
enabled                                 created_at

service_databases (added columns)
---------------------------------
proxy_ports (JSON, nullable)

custom_template_sources
-----------------------
id / uuid
name / slug
repository_url / branch / folder_path
auth_token (encrypted)
is_enabled
sync_status / last_synced_at / sync_error

clusters                             cluster_events
--------                             --------------
id / uuid                            id
name / description                   cluster_id (FK)
type (swarm|kubernetes)              event_type / action
status (healthy|degraded|...)        actor_id / actor_name
manager_server_id (FK)               attributes (JSON)
team_id (FK)                         scope / event_time
settings (encrypted JSON)
metadata (JSON)

servers (added column)               swarm_secrets
----------------------               -------------
cluster_id (FK, nullable)            id / docker_id
                                     cluster_id (FK)
swarm_configs                        name / labels (JSON)
-------------                        description
id / docker_id
cluster_id (FK)
name / data / labels (JSON)

managed_networks                     resource_networks
----------------                     -----------------
id / uuid                            id
docker_network_name (unique w/ srv)  resource_type / resource_id (morph)
server_id (FK)                       managed_network_id (FK)
team_id / project_id / env_id        is_auto_attached / is_connected
scope (environment/shared/proxy)     connected_at / aliases (JSON)
driver / status / docker_id
is_proxy_network / is_attachable
is_encrypted_overlay / options
```

For the full architecture document including flow diagrams, security considerations, and extensibility points, see [Architecture](docs/architecture.md).

### Feature Documentation

Each feature has detailed documentation under `docs/features/<feature-name>/`:

| Feature | Folder | Contents |
|---------|--------|----------|
| Enhanced Database Classification | [`docs/features/enhanced-database-classification/`](docs/features/enhanced-database-classification/) | PRD, implementation plan, feature overview |
| Network Management | [`docs/features/network-management/`](docs/features/network-management/) | PRD, implementation plan, feature overview |
| MCP Server | [`docs/features/mcp-server/`](docs/features/mcp-server/) | PRD, implementation plan, feature overview |
| Cluster Management | [`docs/features/cluster-management/`](docs/features/cluster-management/) | PRD, implementation plan, feature overview |

Each feature folder contains:
- **PRD.md** — Product Requirements Document (problem, goals, design, rationale, risks)
- **plan.md** — Technical implementation plan (code snippets, architecture, testing checklist)
- **README.md** — Feature overview and quick reference

---

## FAQ

**Does this modify Coolify's source code?**
No. It's a Laravel package installed via Composer inside a custom Docker image. Overlay files are image-specific — reverting to the official image restores the originals.

**Will this break Coolify updates?**
The `docker-compose.custom.yml` file survives Coolify upgrades. However, major Coolify updates may require a new addon build. Pre-built images on GHCR track the latest Coolify version.

**What happens to encrypted backups if I uninstall?**
They remain encrypted on S3. You can restore them by reinstalling the addon or using rclone directly with the same encryption password.

**Can I use this with Coolify v5?**
This addon is for Coolify v4. Coolify v5 is expected to include similar features natively. We will provide a migration guide when v5 is released.

**Does removing a custom template source break running services?**
No. After deployment, the docker-compose YAML is stored in the database. Removing a template source has zero impact on services already deployed from it.

**What encryption algorithm is used?**
NaCl SecretBox (XSalsa20 stream cipher + Poly1305 MAC) via rclone's crypt backend. This is the same algorithm used by tools like age, WireGuard, and libsodium.

**How does it compare to Dokploy / Portainer / CapRover?**

| Feature | Coolify + Enhanced | Dokploy | Portainer CE | CapRover |
|---------|-------------------|---------|--------------|----------|
| Granular project permissions | Yes | Partial | Teams only (BE) | No |
| Encrypted S3 backups | Yes | No | No | No |
| Volume & config backups | Yes | No | No | No |
| Custom service templates | Yes | No | App Templates | One-click apps |
| Cluster management | Yes (Swarm) | Basic (Swarm) | Full (Swarm + K8s) | No |
| Open source | MIT | MIT | Partial (CE/BE) | Apache 2.0 |
| Self-hosted PaaS | Yes | Yes | Container mgmt | Yes |

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Create feature documentation under `docs/features/<feature-name>/` with PRD.md, plan.md, and README.md
4. Implement the feature
5. Update all documentation (README.md, CLAUDE.md, AGENTS.md, relevant docs/ files)
6. Commit and push
7. Open a Pull Request

For development guidance, see [CLAUDE.md](CLAUDE.md) and [AGENTS.md](AGENTS.md).

---

## License

MIT License — see [LICENSE](LICENSE) for details.

---

## Support

- [GitHub Issues](https://github.com/amirhmoradi/coolify-enhanced/issues)
- [GitHub Discussions](https://github.com/amirhmoradi/coolify-enhanced/discussions)
- [Coolify Discord](https://discord.gg/coolify)

---

## Acknowledgments

- [Coolify](https://coolify.io) — The self-hostable platform this addon extends
- [rclone](https://rclone.org) — Cloud storage tool providing the encryption backend
- [Dokploy](https://dokploy.com) — Inspiration for the granular permission model

---

**Built for teams that self-host with Coolify and need production-grade access control, backup security, template flexibility, and cluster management.**

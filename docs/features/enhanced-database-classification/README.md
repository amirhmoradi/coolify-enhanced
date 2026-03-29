# Enhanced Database Classification

This feature expands Coolify's database recognition, adds explicit classification controls, enables multi-port proxy support, and provides wire-compatible backup mappings for service databases.

## Overview

Coolify classifies service containers as either `ServiceDatabase` or `ServiceApplication`. This classification determines what features are available: TCP proxy, scheduled backups, database import, and backup UI. By default, Coolify only recognizes a small set of database images. This feature solves the classification gap through multiple complementary mechanisms.

## Components

### 1. Expanded Image List

~50 additional database images added to `DATABASE_DOCKER_IMAGES` constant covering: graph, vector, time-series, document, search, key-value, column-family, NewSQL, and OLAP databases. Deployed as an overlay of `bootstrap/helpers/constants.php`.

### 2. `coolify.database` Docker Label

Explicit per-service classification override. Add `coolify.database: "true"` or `"false"` as a Docker label on any service. The `isDatabaseImageEnhanced()` wrapper in `shared.php` checks this label before falling back to the image list.

```yaml
services:
  my-custom-db:
    image: myorg/custom-database:latest
    labels:
      coolify.database: "true"
```

### 3. `# type: database` Comment Convention

Template-level metadata header that injects `coolify.database` labels into all services during parsing. Per-service labels take precedence.

```yaml
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
```

### 4. Expanded Port Mapping

`StartDatabaseProxy` overlay with `DATABASE_PORT_MAP` constant mapping ~50 database base image names to default internal ports. Multi-level fallback ensures proxy works for any recognized database.

### 5. Wire-Compatible Backup Support

`ServiceDatabase` model overlay maps wire-compatible databases to their parent backup type:

| Database | Maps To | Dump Tool |
|----------|---------|-----------|
| YugabyteDB | PostgreSQL | pg_dump |
| Apache AGE | PostgreSQL | pg_dump |
| TiDB | MySQL | mysqldump |
| Percona | MySQL | mysqldump |
| FerretDB | MongoDB | mongodump |

### 6. Multi-Port Database Proxy

For databases exposing multiple ports (e.g., Memgraph: bolt + log viewer), the `coolify.proxyPorts` Docker label enables per-port proxy configuration.

```yaml
services:
  memgraph:
    image: memgraph/memgraph:latest
    labels:
      coolify.database: "true"
      coolify.proxyPorts: "7687:bolt,7444:log-viewer"
```

**Label format:** `"internalPort:label,internalPort:label,..."`

When present, the UI shows a port mapping table instead of a single toggle. Each port gets its own enable/disable switch and public port number. All ports share one nginx proxy container.

### 7. Meaningful Error Messages

`DatabaseBackupJob` overlay replaces silent failures with contextual messages guiding users to set `custom_type` or use Resource Backups.

## Files

| File | Purpose |
|------|---------|
| `src/Overrides/Helpers/constants.php` | Expanded DATABASE_DOCKER_IMAGES |
| `src/Overrides/Helpers/shared.php` | `isDatabaseImageEnhanced()` + `# type:` injection |
| `src/Overrides/Actions/Database/StartDatabaseProxy.php` | Port mapping + multi-port nginx |
| `src/Overrides/Models/ServiceDatabase.php` | Wire-compat mappings + proxy_ports |
| `src/Overrides/Jobs/DatabaseBackupJob.php` | Meaningful error messages |
| `src/Overrides/Livewire/Project/Service/Index.php` | Multi-port proxy Livewire logic |
| `src/Overrides/Views/livewire/project/service/index.blade.php` | Multi-port proxy UI |
| `database/migrations/2024_01_01_000010_add_proxy_ports_to_service_databases.php` | proxy_ports column |

## Related Documents

- [PRD.md](PRD.md) — Full product requirements document with rationale
- [plan.md](plan.md) — Technical implementation plan for multi-port proxy
- [Custom Templates Guide](../../custom-templates.md) — Template format including `coolify.proxyPorts` label

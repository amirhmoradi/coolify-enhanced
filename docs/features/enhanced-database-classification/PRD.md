# PRD: Enhanced Database Classification

**Feature:** Enhanced Database Classification & Multi-Port Database Proxy
**Status:** Implemented
**Branch:** `claude/add-database-classification-tmBlK`
**Commits:** e10c240, 170cec7, ce7d33d, 010af38, 0fe29a3, 7e75528

---

## 1. Problem Statement

### 1.1 Database Misclassification

Coolify v4 classifies service containers as either `ServiceDatabase` or `ServiceApplication` based on a hardcoded image list (`DATABASE_DOCKER_IMAGES` in `bootstrap/helpers/constants.php`). The list is incomplete — many modern databases are missing:

| Category | Missing Databases |
|----------|-------------------|
| **Graph** | Memgraph, Neo4j, ArangoDB, OrientDB, Dgraph, JanusGraph, Apache AGE |
| **Vector** | Milvus, Qdrant, Weaviate, ChromaDB |
| **Time-series** | QuestDB, TDengine, VictoriaMetrics, InfluxDB |
| **Document** | CouchDB, Couchbase, FerretDB, SurrealDB, RavenDB, RethinkDB |
| **Search** | Elasticsearch, OpenSearch, Meilisearch, Typesense, Manticore, Solr |
| **Key-value** | Valkey, Memcached |
| **Column-family** | Cassandra, ScyllaDB |
| **NewSQL** | CockroachDB, YugabyteDB, TiDB, Vitess |
| **OLAP** | Druid, Pinot, DuckDB |

When a database image isn't recognized, Coolify creates a `ServiceApplication` instead of `ServiceDatabase`. This breaks:

- **"Make Publicly Available"** — The database proxy (nginx TCP stream) is only available for `ServiceDatabase` records
- **Scheduled backups** — The backup UI and `DatabaseBackupJob` only target `ServiceDatabase` records
- **Database import** — The import/restore UI is only rendered for `ServiceDatabase`
- **Port mapping** — `StartDatabaseProxy` doesn't know the correct internal port for unrecognized databases

### 1.2 Single-Port Proxy Limitation

Coolify's database proxy creates an nginx TCP proxy that maps exactly **one** public port to **one** internal port. The `ServiceDatabase` model has:
- `is_public` (bool) — toggle on/off
- `public_port` (int, nullable) — user-specified external port

`StartDatabaseProxy` generates a single nginx `stream { server { listen X; proxy_pass container:Y; } }` block.

Some databases expose multiple ports for different protocols or interfaces:

| Database | Ports | Purpose |
|----------|-------|---------|
| **Memgraph** | 7687 | Bolt protocol (DB queries) |
| | 7444 | HTTPS log viewer interface |
| **Neo4j** | 7687 | Bolt protocol |
| | 7474 | HTTP browser interface |
| | 7473 | HTTPS browser interface |
| **ArangoDB** | 8529 | HTTP API |
| | 8530 | Internal cluster communication |

With the current single-port proxy, only one port can be exposed — typically the database query port. Secondary interfaces (admin UIs, log viewers, monitoring endpoints) remain inaccessible from outside the Docker network.

### 1.3 Unsupported Database Type Errors

When toggling "Make Publicly Available" on a newly-classified `ServiceDatabase`, two errors occur:

1. **`StartDatabaseProxy` throws "Unsupported database type"** because the switch statement doesn't have a case for the new database type (e.g., `standalone-memgraph`)
2. **`DatabaseBackupJob` silently fails** because it doesn't know how to dump the database, or throws a generic exception with no guidance

### 1.4 Wire-Compatible Databases

Some databases speak standard protocols and can be backed up with standard tools:
- **YugabyteDB** speaks PostgreSQL wire protocol → `pg_dump` works
- **TiDB** speaks MySQL wire protocol → `mysqldump` works
- **FerretDB** speaks MongoDB wire protocol → `mongodump` works
- **Percona Server** is MySQL-compatible → `mysqldump` works
- **Apache AGE** is a PostgreSQL extension → `pg_dump` works

Without explicit type mapping, these databases get classified correctly (via expanded image list) but don't get backup/import support because `databaseType()` returns an unknown type.

---

## 2. Goals

### 2.1 Primary Goals

1. **Automatic recognition**: Expand the database image list to cover all common databases (~50 additional images)
2. **Explicit override**: Provide a mechanism for template authors to explicitly classify services as database/application
3. **Proxy support**: Make "Make Publicly Available" work for all recognized databases with correct default ports
4. **Multi-port proxy**: Allow databases with multiple ports to expose them independently
5. **Wire-compatible backups**: Enable dump-based backups for databases that speak standard protocols
6. **Meaningful errors**: Replace silent failures and generic exceptions with actionable error messages

### 2.2 Non-Goals

- Overlaying `parsers.php` (2484 lines, too risky for the benefit)
- Overlaying `docker.php` (1483 lines, wrapper approach in `shared.php` is sufficient)
- Adding backup support for databases where standard tools fail (CockroachDB, Vitess, ScyllaDB)
- Supporting multi-port proxy for standalone databases (only `ServiceDatabase` within Services)

---

## 3. Solution Design

### 3.1 Expanded `DATABASE_DOCKER_IMAGES` (Mechanism 1)

**Overlay file**: `src/Overrides/Helpers/constants.php`

Add ~50 database images to the constant, organized by category. This is the broadest mechanism — any image in the list is automatically classified as a database when deployed via Coolify's service system.

**Why overlay `constants.php` instead of `docker.php`?**
- `constants.php` is ~100 lines (just constants); `docker.php` is 1483 lines
- The constant is referenced by `isDatabaseImage()` in `docker.php`
- Overlaying the constant is safer and lower-maintenance than overlaying the function

### 3.2 `coolify.database` Docker Label (Mechanism 2)

**Wrapper function**: `isDatabaseImageEnhanced()` in `src/Overrides/Helpers/shared.php`

A wrapper around Coolify's `isDatabaseImage()` that first checks for a `coolify.database` Docker label on the service config. If the label exists, its value determines classification (true=database, false=application). If absent, falls back to `isDatabaseImage()`.

**Why a wrapper in `shared.php` instead of overlaying `docker.php`?**
- `shared.php` is already overlaid for custom templates
- The wrapper covers the 2 critical call sites in `shared.php` (service import + deployment)
- The 4 call sites in `parsers.php` handle Application compose, not Service templates, so missing them is acceptable
- `parsers.php` preserves existing records anyway, so re-classification is rare

**Label format**: Standard Docker Compose labels, both map and array format:
```yaml
# Map format
labels:
  coolify.database: "true"

# Array format
labels:
  - coolify.database=true
```

### 3.3 `# type: database` Comment Convention (Mechanism 3)

**Implementation**: `TemplateSourceService::parseTemplateContent()` in `src/Services/TemplateSourceService.php` and `get_service_templates()` in `shared.php`

Template authors add `# type: database` (or `# type: application`) as a comment metadata header. During template parsing, this injects `coolify.database` labels into all services in the compose YAML.

**Why inject labels instead of using a runtime check?**
- Labels persist into `docker_compose_raw` in the database
- Classification survives re-parses and service updates
- Per-service labels take precedence, enabling mixed-type templates

### 3.4 Expanded Port Mapping

**Overlay file**: `src/Overrides/Actions/Database/StartDatabaseProxy.php`

A new `DATABASE_PORT_MAP` constant maps ~50 database base image names to their default internal ports. Multi-level fallback:
1. Coolify's built-in switch statement (handles standard types)
2. Base image name lookup in `DATABASE_PORT_MAP`
3. Partial string matching (e.g., `timescaledb-ha` matches `timescale`)
4. Port extraction from the service's docker-compose configuration
5. Helpful error message guiding users to set `custom_type`

### 3.5 Wire-Compatible Database Mappings

**Overlay file**: `src/Overrides/Models/ServiceDatabase.php`

Enhanced `databaseType()` method that maps wire-compatible databases to their parent type:

| Database | Maps To | Tool | Rationale |
|----------|---------|------|-----------|
| YugabyteDB | postgresql | pg_dump | Full PostgreSQL wire compatibility |
| Apache AGE | postgresql | pg_dump | PostgreSQL extension |
| TiDB | mysql | mysqldump | MySQL wire-compatible |
| Percona | mysql | mysqldump | MySQL drop-in replacement |
| FerretDB | mongodb | mongodump | MongoDB wire protocol |

**Conservative approach**: Only map databases where standard dump tools produce correct, complete backups. Excluded:
- **CockroachDB**: Speaks pgwire but `pg_dump` fails on catalog functions
- **Vitess**: Speaks MySQL but `mysqldump` needs extra flags and is unreliable for sharded setups
- **ScyllaDB**: Speaks CQL but Cassandra dump tools need specific compatibility modes

### 3.6 Multi-Port Database Proxy

**New label**: `coolify.proxyPorts` on service containers
**New column**: `proxy_ports` JSON on `service_databases` table
**Modified overlay**: `StartDatabaseProxy.php` — `handleMultiPort()` method
**New overlays**: `Service/Index.php` Livewire component + `index.blade.php` view

Instead of modifying Coolify's core `public_port`/`is_public` mechanism (which works well for single-port databases), we add a **parallel multi-port proxy** system:

1. Template authors declare available ports via `coolify.proxyPorts: "7687:bolt,7444:log-viewer"`
2. The label is parsed from `docker_compose_raw` on component mount
3. Port configuration is stored in `proxy_ports` JSON column
4. When enabled, `StartDatabaseProxy::handleMultiPort()` generates multiple nginx `server` blocks
5. All ports share one proxy container

**Backward compatibility**: When `coolify.proxyPorts` is absent, the UI and proxy behavior are identical to stock Coolify.

### 3.7 Meaningful Error Messages

**Overlay file**: `src/Overrides/Jobs/DatabaseBackupJob.php`

Replace silent `return` and generic exceptions with contextual error messages:
- For unknown `ServiceDatabase` types: "Dump-based backups are not supported for {type}. Set `custom_type` to a supported type (postgresql, mysql, mariadb, mongodb) if your database is wire-compatible, or use Resource Backups for volume-level backups."
- Error is stored in the backup execution record for visibility in the UI

---

## 4. User Experience

### 4.1 Template Author Experience

```yaml
# documentation: https://memgraph.com/docs
# slogan: Real-time graph database
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    labels:
      coolify.database: "true"
      coolify.proxyPorts: "7687:bolt,7444:log-viewer"
    volumes:
      - memgraph-data:/var/lib/memgraph

  memgraph-lab:
    image: memgraph/lab:latest
    labels:
      coolify.database: "false"
    environment:
      - SERVICE_FQDN_MEMGRAPHLAB_3000
```

### 4.2 End User Experience

1. **Deploy**: User deploys Memgraph from template → classified as ServiceDatabase
2. **View**: Service page shows database features (proxy, backup UI if wire-compatible)
3. **Proxy**: "Proxy" section shows port mapping table:
   - `[x] bolt (7687) → Public Port: [17687]`
   - `[x] log-viewer (7444) → Public Port: [17444]`
4. **Enable**: Toggle "Make it publicly available" → nginx starts with both ports
5. **Access**: Both ports accessible externally; URLs displayed in UI
6. **Backups**: For wire-compatible databases, dump-based backups work automatically. For others, Resource Backups provide volume-level backup

### 4.3 Fallback for Non-Labeled Databases

Databases without `coolify.proxyPorts`:
- Single-port toggle (stock Coolify behavior)
- `DATABASE_PORT_MAP` determines the internal port
- Zero behavioral change

---

## 5. Technical Decisions & Rationale

### 5.1 Why Not Overlay `parsers.php`?

`parsers.php` is 2484 lines and handles the core compose parsing logic. Overlaying it would be extremely high-maintenance and fragile. Instead:
- The expanded `DATABASE_DOCKER_IMAGES` catches most classification at the `isDatabaseImage()` level
- `parsers.php` preserves existing records, so initial classification (via our wrapper) survives re-parses
- The `is_migrated` flag prevents re-classification of already-classified services

### 5.2 Why Not Overlay `docker.php`?

`docker.php` is 1483 lines. The `isDatabaseImageEnhanced()` wrapper in `shared.php` covers the 2 call sites that matter (service import + deployment). The 4 call sites in `parsers.php` handle Application compose (not Service templates).

### 5.3 Why JSON Column Instead of Additional Boolean Columns?

The `proxy_ports` JSON column is flexible enough to store per-port configuration (public port, label, enabled state) without requiring a separate table or multiple columns. The data is only queried at component mount time and during proxy start/stop.

### 5.4 Why `coolify.proxyPorts` Label Instead of `ports` or `expose`?

Docker `ports` and `expose` directives are consumed by Docker itself and have semantic meaning. A custom label (`coolify.proxyPorts`) is purely metadata — it declares what ports are *available for proxying* without affecting Docker networking. It's also consistent with the existing `coolify.database` label convention.

### 5.5 Why Conservative Wire-Compatible Mapping?

Incorrect backup mappings are worse than no mapping. If `pg_dump` produces an incomplete or corrupted backup of CockroachDB, the user discovers the problem at restore time — the worst possible moment. Only databases where standard tools are known to produce correct backups are mapped.

---

## 6. Files Modified/Created

| File | Action | Description |
|------|--------|-------------|
| `src/Overrides/Helpers/constants.php` | Overlay | ~50 additional database images |
| `src/Overrides/Helpers/shared.php` | Modified | `isDatabaseImageEnhanced()` wrapper + `# type:` label injection |
| `src/Services/TemplateSourceService.php` | Modified | `# type: database` parsing in custom templates |
| `src/Overrides/Actions/Database/StartDatabaseProxy.php` | Overlay | `DATABASE_PORT_MAP` + multi-level fallback + `handleMultiPort()` |
| `src/Overrides/Models/ServiceDatabase.php` | Overlay | Wire-compatible mappings + `proxy_ports` cast + multi-port helpers |
| `src/Overrides/Jobs/DatabaseBackupJob.php` | Modified | Meaningful errors for unsupported types |
| `src/Overrides/Livewire/Project/Service/Index.php` | New overlay | Multi-port proxy Livewire logic |
| `src/Overrides/Views/livewire/project/service/index.blade.php` | New overlay | Multi-port proxy UI |
| `database/migrations/2024_01_01_000010_add_proxy_ports_to_service_databases.php` | New | `proxy_ports` JSON column |
| `docker/Dockerfile` | Modified | COPY lines for new overlays |

---

## 7. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Overlay drift with Coolify upstream | High | Mark all changes with comments; overlays are full file copies for easy diffing |
| Substring false positives in `databaseType()` | Medium | AGE check excludes `garage`, `image`; all checks are tested against known image names |
| Port conflicts on host | Medium | Validate port uniqueness before starting proxy (future improvement) |
| Large overlay files | Medium | `Service/Index.php` is ~560 lines; changes are minimal and well-marked |
| `proxy_ports` schema migration | Low | Column is nullable; existing databases unaffected |
| parsers.php re-classification | Low | Existing records are preserved; expanded image list catches new services |

---

## 8. Testing Checklist

- [ ] Deploy a service with an image from the expanded list (e.g., Memgraph) → classified as ServiceDatabase
- [ ] Deploy with `coolify.database: "true"` label on unknown image → classified as ServiceDatabase
- [ ] Deploy with `# type: database` comment → all services get labels → classified correctly
- [ ] Mixed template (`# type: database` + `coolify.database: "false"` on one service) → correct per-service classification
- [ ] Toggle "Make Publicly Available" on expanded database → proxy starts with correct port
- [ ] Multi-port proxy: deploy with `coolify.proxyPorts` → table UI shown → enable ports → nginx starts with multiple server blocks
- [ ] Multi-port proxy: disable one port → proxy restarts with remaining ports
- [ ] Multi-port proxy: disable all ports → proxy container removed
- [ ] Standard database (PostgreSQL) → single-port toggle unchanged
- [ ] Wire-compatible database (YugabyteDB) → backup UI visible → pg_dump works
- [ ] Non-wire-compatible database (Memgraph) → backup job produces meaningful error
- [ ] Re-parse service → existing ServiceDatabase records preserved
- [ ] Feature disabled (`CORELIX_PLATFORM=false`) → stock Coolify behavior

---

## 9. Future Considerations

- **Port conflict validation**: Check that no two databases on the same server use the same public port
- **Automatic port assignment**: Generate unique public ports when user enables proxy (instead of requiring manual input)
- **parsers.php integration**: If Coolify v5 provides hooks or events for classification, migrate from overlay to hook-based approach
- **Additional wire-compatible mappings**: As databases evolve, more may become compatible with standard tools (e.g., CockroachDB improves pg_dump support)

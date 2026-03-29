# Coolify Upstream Issues Tracked by Corelix Platform

This document maps open issues in [coollabsio/coolify](https://github.com/coollabsio/coolify/issues) to features implemented (or planned) in this addon. Each issue includes a draft comment for posting upstream.

> **Last updated:** 2026-02-20

---

## Table of Contents

- [Granular Permissions](#granular-permissions)
- [Encrypted S3 Backups](#encrypted-s3-backups)
- [Resource Backups (Volumes, Config, Full)](#resource-backups)
- [Custom Template Sources](#custom-template-sources)
- [Enhanced Database Classification](#enhanced-database-classification)
- [Network Management & Isolation](#network-management--isolation)
- [Summary](#summary)

---

## Granular Permissions

### #2378 — [Feature]: Granular permissions & user roles
- **URL:** https://github.com/coollabsio/coolify/issues/2378
- **Labels:** Feature, Bounty, Enhancement
- **Status:** Open (original feature request from early v4)
- **Relevance:** This is the foundational request. Corelix Platform implements exactly this — project-level and environment-level RBAC with View Only, Deploy, and Full Access tiers.

**Draft Comment:**

> Built this as a drop-in addon: [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced).
>
> What it does — project-level and environment-level access control with three permission tiers (View Only, Deploy, Full Access). Environment-level overrides cascade from project permissions. Owners and Admins bypass all checks; only Members and Viewers are restricted.
>
> Implementation uses Laravel policy overrides registered in `$app->booted()` — after Coolify's own AuthServiceProvider finishes — so nothing in core gets patched. The Access Matrix UI injects into the existing Team Admin page via middleware. Full REST API included for automation.
>
> Ships as a Docker image overlay. Install takes under 2 minutes. When disabled, Coolify behaves exactly as stock.
>
> Not a replacement for native v5 RBAC — just a bridge for teams that need this today on v4.

---

### #6894 — [Enhancement]: Project-specific members
- **URL:** https://github.com/coollabsio/coolify/issues/6894
- **Labels:** Bounty ($1K), Hold, Enhancement
- **Status:** Open, on hold
- **Relevance:** Directly requests per-project member assignment. Corelix Platform's `project_user` pivot table with role-based overrides covers this exactly.

**Draft Comment:**

> This is implemented in [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) — project-level member assignment with View Only / Deploy / Full Access permissions, plus optional environment-level overrides.
>
> The permission model uses a `project_user` pivot table for project access and an `environment_user` table for environment-level overrides. Resolution order: environment override first, project-level fallback second, deny if nothing found. Owners and Admins skip all checks.
>
> The Access Matrix UI renders on the Team Admin page — one table, all projects and environments, click to assign. Also exposes a REST API for scripting.
>
> Addon approach — no Coolify source changes. Installs via Docker image overlay and `docker-compose.custom.yml`.

---

### #5293 — [Bug]: Member can delete any project without being a member of the project
- **URL:** https://github.com/coollabsio/coolify/issues/5293
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** This is a direct consequence of Coolify's policies returning `true` for all operations. Corelix Platform replaces all resource policies (Project, Application, Service, Database, Environment, Server) with permission-checked versions.

**Draft Comment:**

> Root cause: Coolify's `ProjectPolicy` returns `true` for `delete()` regardless of team membership or project assignment. Every policy in `AuthServiceProvider` does the same — authorization is effectively disabled for all resource types.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) replaces these with permission-checked policies. `ProjectPolicy::delete()` requires Full Access on the project. The policies are registered in `$app->booted()` to ensure they override Coolify's defaults (package providers boot before app providers).
>
> Same fix applies to Application, Service, Database, Environment, and Server resources — all gated by the permission model.

---

## Encrypted S3 Backups

### #7776 — S3 path prefix support
- **URL:** https://github.com/coollabsio/coolify/pull/7776
- **Labels:** PR (merged or pending)
- **Status:** Pull request
- **Relevance:** Corelix Platform independently implements per-storage S3 path prefix, plus adds transparent encryption on top. The rclone-based approach handles both prefix and encryption in a single pipeline.

**Draft Comment:**

> Path prefix is implemented in [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) alongside transparent S3 encryption.
>
> The addon adds a `path` column to `s3_storages` — prepended in uploads (mc and rclone), deletes, restores, and file existence checks. When encryption is also enabled, the path prefix sits outside the encrypted namespace, so S3 console browsing still shows the directory structure.
>
> Encryption uses rclone's crypt backend — NaCl SecretBox (XSalsa20 + Poly1305). Configured per S3 storage destination. Existing unencrypted backups keep working (`is_encrypted` tracked per execution). Optional filename encryption for compliance use cases.

---

### #5698 — [Bug]: S3 backup retention not working
- **URL:** https://github.com/coollabsio/coolify/issues/5698
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** When filename encryption is enabled, Coolify's native S3 listing (via Laravel Storage) cannot read the encrypted filenames. The addon routes all S3 operations through rclone when filename encryption is active, which correctly lists and deletes encrypted files.

**Draft Comment:**

> If you're using [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) with filename encryption enabled, note that retention cleanup routes through rclone instead of Laravel's S3 driver — rclone decrypts the filenames transparently before applying retention logic. This avoids the mismatch where the S3 driver sees encrypted filenames and fails pattern matching.
>
> For the stock retention bug specifically — would need to see the exact S3 provider and path format to diagnose. The `databases.php` overlay in the addon also fixes some edge cases in the S3 delete path.

---

## Resource Backups

### #2389 — [Feature]: Backup Manager in the UI
- **URL:** https://github.com/coollabsio/coolify/issues/2389
- **Labels:** Feature, Bounty ($50), Core Team Only
- **Status:** Open
- **Relevance:** Corelix Platform adds a `ResourceBackupManager` Livewire component that appears on every resource's Configuration page (Application, Service, Database) plus a server-level backup page. Supports volume, configuration, full, and Coolify instance backup types.

**Draft Comment:**

> Implemented in [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) as the Resource Backup Manager.
>
> What it covers: Docker volume snapshots (tar.gz), configuration export (JSON — settings, env vars, compose, labels), full backups (volumes + config), and Coolify instance file backups (`/data/coolify`). Each resource gets its own backup schedule via cron expressions. Retention by count, age, or storage size.
>
> The UI lives on each resource's Configuration page (sidebar item + content panel) and on a dedicated Server > Resource Backups page. Uses the same S3 upload pipeline as database backups — with optional encryption if enabled on the storage destination.
>
> Also includes a Settings > Restore page with JSON backup viewer and environment variable bulk import for restoring into existing resources.

---

### #7456 — [Bug]: 500 Error after Coolify restore on a new server
- **URL:** https://github.com/coollabsio/coolify/issues/7456
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Coolify's native restore copies the database but doesn't handle configuration drift (missing git sources, changed server IDs, etc.). The addon's configuration backup exports a structured JSON that includes all resource metadata, making selective restore possible.

**Draft Comment:**

> The restore process in stock Coolify copies the SQLite/Postgres database wholesale, which breaks when server IDs, git source references, or SSH key paths don't match the new environment.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) takes a different approach for resource-level restores — each resource's configuration is exported as structured JSON (model attributes, environment variables, persistent storages, docker-compose, custom labels). The Restore page lets you inspect the JSON and selectively import environment variables into existing resources, rather than overwriting the entire database.
>
> For full instance migration, the Coolify Instance Backup type captures `/data/coolify` (minus the backups directory itself) as a tar archive to S3. But the restore is still manual and documented — not a one-click operation. The 500 errors typically come from orphaned references that a structured import avoids.

---

### #5434 — [Bug]: Missing git sources after Coolify backup restore
- **URL:** https://github.com/coollabsio/coolify/issues/5434
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Same root cause as #7456 — the database restore doesn't preserve git source configurations that depend on external state (GitHub App installations, SSH keys). The addon's configuration export captures these references explicitly.

**Draft Comment:**

> This happens because Coolify's database restore copies the `git_sources` table, but the GitHub App installation IDs and SSH deploy keys point to the old server's GitHub integration. On a new server, those external references are invalid.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) exports resource configuration as structured JSON — including git source references, environment variables, and deployment settings. The restore workflow lets you review and selectively import what makes sense for the new environment. Git source setup still requires manual GitHub App re-installation, but the export preserves the repository URLs and branch configurations so you know exactly what to reconnect.

---

### #2501 — [Bug]: No restore/import tab for MariaDB in WordPress service
- **URL:** https://github.com/coollabsio/coolify/issues/2501
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** The database import UI in Coolify only shows for standalone databases, not for ServiceDatabase instances inside a service. The addon's encrypted import overlay applies to all database types.

**Draft Comment:**

> Stock Coolify only shows the Import tab for standalone databases (StandalonePostgresql, etc.), not for `ServiceDatabase` instances inside services like WordPress. The WordPress MariaDB container is a ServiceDatabase — it has backup support but no import UI.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) overlays the Import.php Livewire component to add encryption-aware restore, but this is scoped to standalone databases. For ServiceDatabase import, the addon's Resource Backup approach (volume-level tar snapshots) is the workaround — restore by replacing the data volume rather than running SQL import.

---

### #7423 — [Enhancement]: Use pgBackRest for Postgres backups
- **URL:** https://github.com/coollabsio/coolify/issues/7423
- **Labels:** Bounty ($1K), Triage, Enhancement
- **Status:** Open
- **Relevance:** While the addon doesn't implement pgBackRest specifically, its volume-level backup approach provides an alternative for large databases where `pg_dump` is too slow or expensive.

**Draft Comment:**

> pgBackRest with WAL archiving and incremental backups is the correct long-term solution for large Postgres instances. Not something a drop-in addon can safely retrofit.
>
> In the meantime — [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) offers volume-level backups as a parallel option. Instead of `pg_dump`, it snapshots the Docker data volume via `tar czf`. Not incremental, but captures the full data directory including WAL files. Combined with the addon's cron scheduling and S3 upload (with optional encryption), it's a viable stopgap for databases where dump-based backups are too slow.
>
> Tradeoff: volume backups require the container to be in a consistent state (ideally after `pg_start_backup()`/`pg_stop_backup()`). The addon doesn't call these — it's a raw volume snapshot. For crash-consistent recovery, Postgres's built-in WAL replay handles it, but it's not as clean as pgBackRest.

---

### #8139 — [Bug]: Long running database backups get stuck in progress status
- **URL:** https://github.com/coollabsio/coolify/issues/8139
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** The addon's `DatabaseBackupJob` overlay improves error handling and status tracking. For truly long-running backups, the Resource Backup approach (volume snapshots) is often faster than `pg_dump` on large databases.

**Draft Comment:**

> The stuck-in-progress issue typically comes from the backup job timing out or the SSH connection dropping during a large dump, leaving the execution record in `in_progress` state without a cleanup handler.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) overlays `DatabaseBackupJob` with improved error handling — meaningful exceptions instead of silent returns, and better status tracking. For databases large enough to cause timeout issues, the addon also offers volume-level backups (Resource Backups feature) as an alternative — `tar czf` on the data volume is typically faster than a logical dump for 50GB+ databases.

---

### #6542 — [Bug]: Database backup fails when specifying multiple database names
- **URL:** https://github.com/coollabsio/coolify/issues/6542
- **Labels:** Possible Bug, Triage, Linear
- **Status:** Open
- **Relevance:** The addon's DatabaseBackupJob overlay inherits this limitation from stock Coolify. Volume-level backups capture all databases in a single snapshot regardless of naming.

**Draft Comment:**

> The dump command templates in `DatabaseBackupJob` don't handle comma-separated database names — `pg_dump` and `mysqldump` both expect a single database per invocation. The `--databases db1 db2` syntax for mysqldump is different from pg_dump's single-database model.
>
> If you need to back up multiple databases from one container, [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) offers volume-level backups (Resource Backups feature) as a workaround — a single tar snapshot of the data volume captures all databases. Not a substitute for proper per-database logical dumps, but it avoids the multi-name parsing issue entirely.

---

## Custom Template Sources

### #6653 — [Bug]: Editing docker-compose of one-click service template doesn't work
- **URL:** https://github.com/coollabsio/coolify/issues/6653
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Once deployed, a service's compose lives in `docker_compose_raw` in the DB. The addon's custom template system follows the same pattern — templates are write-once at deploy time. Editing post-deployment is a Coolify core UI issue, not a template source issue.

**Draft Comment:**

> This is a Coolify core issue — after a service is deployed from any template (built-in or custom), the compose YAML lives in `Service.docker_compose_raw` in the database. The "Docker Compose" editor on the service page should modify that DB column, not the original template file.
>
> For context — [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) adds custom template sources (external GitHub repos as template origins) and follows the same write-once pattern. The template is consumed at deploy time and has zero runtime dependency. Removing a template source doesn't affect deployed services. Post-deployment editing is entirely a function of Coolify's service UI, which reads from `docker_compose_raw`.

---

### #4849 — [Bug]: Authentik template not working
- **URL:** https://github.com/coollabsio/coolify/issues/4849
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Broken built-in templates highlight why community-maintained template sources are valuable — fixes can ship outside the Coolify release cycle.

**Draft Comment:**

> Broken built-in templates are stuck until the next Coolify release ships a fix. This is one of the reasons [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) supports custom template sources — you can point to a GitHub repo with a corrected Authentik template and deploy it today, without waiting for the upstream fix.
>
> The addon's template system uses the exact same YAML format as Coolify's built-in templates. You copy the template, fix the issue, push to your repo, add it as a source in Settings > Templates, and the corrected template appears in the New Resource page alongside the built-in ones. Built-in templates always take precedence by name, so custom templates with the same name get a source suffix — or you can name it differently.

---

### #3597 — [Bug]: PostHog template broken
- **URL:** https://github.com/coollabsio/coolify/issues/3597
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Same pattern as #4849 — broken template that can be fixed via a custom template source without waiting for upstream.

**Draft Comment:**

> Same situation as other broken built-in templates — the fix is in the YAML, but it's blocked on a Coolify release.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) lets you add a GitHub repo with corrected templates as a custom source. The corrected PostHog template shows up in the New Resource page immediately. No Coolify source changes needed. See the [custom template guide](https://github.com/amirhmoradi/coolify-enhanced/blob/main/docs/custom-templates.md) for the YAML format.

---

### #4813 — [Bug]: Jitsi Meet template broken
- **URL:** https://github.com/coollabsio/coolify/issues/4813
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Same pattern — custom template sources as a workaround for broken built-in templates.

**Draft Comment:**

> If you need Jitsi working now — [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) supports custom template sources from GitHub repos. Fork or create a repo with a fixed Jitsi template (same YAML format as Coolify's built-in), add it as a source in Settings > Templates, and deploy from there. The template format is documented at https://github.com/amirhmoradi/coolify-enhanced/blob/main/docs/custom-templates.md.

---

### #7642 — [Enhancement]: Add SurrealDB with and without TiKV
- **URL:** https://github.com/coollabsio/coolify/issues/7642
- **Labels:** Bounty ($50), Triage, Enhancement
- **Status:** Open
- **Relevance:** Custom template sources let users add SurrealDB without waiting for a built-in template. The enhanced database classification feature also recognizes `surrealdb` in the expanded `DATABASE_DOCKER_IMAGES` list.

**Draft Comment:**

> SurrealDB can be deployed today using [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) in two ways:
>
> 1. **Custom template source** — Create a GitHub repo with a SurrealDB docker-compose template (with or without TiKV), add it as a source in Settings > Templates. Template format is identical to Coolify's built-in YAML. See the [template guide](https://github.com/amirhmoradi/coolify-enhanced/blob/main/docs/custom-templates.md).
>
> 2. **Database classification** — The addon's expanded `DATABASE_DOCKER_IMAGES` list includes `surrealdb`, so it's correctly classified as a `ServiceDatabase` when deployed via any compose method. The `StartDatabaseProxy` overlay also maps the default SurrealDB port (8000) for "Make Publicly Available".
>
> For the TiKV backend variant, a multi-service template (SurrealDB + TiKV + PD) would use the `# type: database` comment or `coolify.database: "true"` label to ensure correct classification of each container.

---

### #8264 — [Enhancement]: WordPress + OpenLiteSpeed template
- **URL:** https://github.com/coollabsio/coolify/issues/8264
- **Labels:** Enhancement, Triage
- **Status:** Open
- **Relevance:** Template requests that can be immediately addressed via custom template sources.

**Draft Comment:**

> Rather than waiting for a built-in template — [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) supports custom template sources from GitHub repos. Create a repo with a WordPress + OpenLiteSpeed docker-compose template, add it as a source in Settings > Templates, and it appears in the New Resource page.
>
> The template format is the same YAML used by Coolify's built-in templates. Documentation and example: https://github.com/amirhmoradi/coolify-enhanced/blob/main/docs/custom-templates.md

---

### #4778 — [Enhancement]: MongoDB Replica Set template
- **URL:** https://github.com/coollabsio/coolify/issues/4778
- **Labels:** Enhancement, Triage
- **Status:** Open
- **Relevance:** Complex multi-container templates like MongoDB replica sets are good candidates for community template repos.

**Draft Comment:**

> MongoDB replica sets need multiple containers with specific initialization logic — not trivial to capture in a single template, but doable with docker-compose + init scripts.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) supports custom template sources where you can host a MongoDB replica set template in a GitHub repo. The `coolify.database: "true"` label ensures each `mongod` container is classified correctly as a `ServiceDatabase`. Template format docs: https://github.com/amirhmoradi/coolify-enhanced/blob/main/docs/custom-templates.md

---

## Enhanced Database Classification

### #7528 — [Enhancement]: Enable database detection and backup support for Docker Compose deployments via GitHub App
- **URL:** https://github.com/coollabsio/coolify/issues/7528
- **Labels:** Bounty ($200), Triage, Enhancement
- **Status:** Open
- **Relevance:** Core classification gap. When deploying via GitHub App (`dockercompose` buildpack), `isDatabaseImage()` doesn't run through the service parsing path, so databases aren't detected. The addon's expanded image list helps for services parsed through `shared.php`, but the GitHub App buildpack path requires additional work.

**Draft Comment:**

> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) partially addresses this:
>
> - The expanded `DATABASE_DOCKER_IMAGES` constant adds ~50 database images — so any image that goes through `isDatabaseImage()` has better coverage.
> - The `isDatabaseImageEnhanced()` wrapper in `shared.php` checks for a `coolify.database=true` Docker label before falling back to the image list. Template authors can force classification via label.
> - The `# type: database` comment convention injects the label into compose YAML during template parsing.
>
> However — the GitHub App / `dockercompose` buildpack path doesn't go through the service template parser. It goes through `parsers.php`, which the addon intentionally does NOT overlay (2484 lines, high change velocity). The expanded `DATABASE_DOCKER_IMAGES` helps at that level, but the label check only covers the two call sites in `shared.php`.
>
> For GitHub App deployments specifically, adding `coolify.database: "true"` as a Docker label in your compose file is the most reliable workaround today.

---

### #6320 — [Bug]: Chatwoot database is being created as a service instead of a database
- **URL:** https://github.com/coollabsio/coolify/issues/6320
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Direct misclassification bug. The PostgreSQL container in the Chatwoot template isn't recognized by `isDatabaseImage()` — likely because the image string format doesn't match the expected patterns.

**Draft Comment:**

> This is a classification bug in `isDatabaseImage()` — the Chatwoot template's PostgreSQL container image isn't matching the `DATABASE_DOCKER_IMAGES` patterns.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) fixes this three ways:
> 1. Expanded `DATABASE_DOCKER_IMAGES` with broader matching patterns for postgres variants
> 2. `isDatabaseImageEnhanced()` wrapper that checks for `coolify.database=true` label before image matching
> 3. Template-level `# type: database` comment that injects the label into all services
>
> For the Chatwoot template specifically — adding `coolify.database: "true"` as a label on the postgres service in the template YAML forces correct classification regardless of image name matching.

---

### #6265 — [Bug]: When converting Database to Application the proxy settings are not added
- **URL:** https://github.com/coollabsio/coolify/issues/6265
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** InfluxDB 3 is classified as ServiceDatabase but needs FQDN support (HTTP UI). The root issue is the binary classification model — some services are both databases and web applications. The addon's `coolify.database=false` label lets template authors force application classification for these hybrid services.

**Draft Comment:**

> Root cause: InfluxDB has both a database wire protocol AND an HTTP UI, but Coolify's classification is binary — `ServiceDatabase` (public port only) or `ServiceApplication` (FQDN/domain support). There's no hybrid option.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) lets you force classification with the `coolify.database` Docker label:
> - `coolify.database: "false"` — forces InfluxDB to be classified as `ServiceApplication`, enabling FQDN/domain configuration
> - `coolify.database: "true"` — forces database classification for the TCP port proxy
>
> For InfluxDB 3 specifically, `coolify.database: "false"` is probably the right choice — deploy as application, set the FQDN for the HTTP UI, and connect to the database port internally via the Docker network name.
>
> The label is per-service in the compose YAML, so multi-container templates can have mixed classification (e.g., `influxdb` as application, `postgres` as database).

---

### #4805 — [Bug]: Database error when setting FQDN in docker-compose
- **URL:** https://github.com/coollabsio/coolify/issues/4805
- **Labels:** Improvement, Low Priority
- **Status:** Open
- **Relevance:** Same root cause as #6265 — InfluxDB classified as ServiceDatabase, which has no `fqdn` column. The `coolify.database: "false"` label override resolves this.

**Draft Comment:**

> Same root cause as #6265 — `isDatabaseImage()` classifies InfluxDB as `ServiceDatabase`, which doesn't have an `fqdn` column. Setting `$SERVICE_FQDN_INFLUXDB` produces `SQLSTATE[42703]: Undefined column`.
>
> Workaround with [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced): add `coolify.database: "false"` as a label on the InfluxDB service in your compose YAML. This forces `ServiceApplication` classification, which supports FQDNs. The label is checked by `isDatabaseImageEnhanced()` before the image name matching.

---

### #8148 — [Bug]: Public Port Mismatch — Service Accessible on Different Port Than UI Shows
- **URL:** https://github.com/coollabsio/coolify/issues/8148
- **Labels:** Waiting for feedback
- **Status:** Open
- **Relevance:** Port mapping mismatch in `StartDatabaseProxy`. The addon's overlay expands the port mapping to ~50 database types and adds fallback logic to extract the correct port from the compose config.

**Draft Comment:**

> This is a port resolution issue in `StartDatabaseProxy` — the action maps database type to internal port, but the mapping is incomplete for many database images. If the wrong port is selected, the nginx proxy forwards to the wrong container port.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) overlays `StartDatabaseProxy` with:
> 1. A `DATABASE_PORT_MAP` covering ~50 database types (ClickHouse, QuestDB, Memgraph, etc.)
> 2. Partial string matching fallback for image variants
> 3. Port extraction from the service's compose config as a last resort
> 4. Clear error message guiding users to set `custom_type` if all resolution methods fail
>
> For ClickHouse specifically — the overlay maps it to port 9000 (native protocol). The HTTP interface on 8123 would need FQDN-based routing via Traefik/Caddy, not the TCP proxy.

---

### #7743 — [Enhancement]: Don't timeout public database proxies after 10 min
- **URL:** https://github.com/coollabsio/coolify/issues/7743
- **Labels:** Bounty ($100), Triage, Enhancement
- **Status:** Open
- **Relevance:** The `StartDatabaseProxy` overlay in the addon generates the nginx stream config — the timeout value could be made configurable there.

**Draft Comment:**

> The 10-minute timeout is hardcoded in the nginx `stream` config generated by `StartDatabaseProxy`. The relevant directives are `proxy_timeout` and `proxy_connect_timeout`.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) overlays `StartDatabaseProxy` for expanded port mapping. The timeout value could be made configurable via an environment variable or per-database setting in a future release. For now, the overlay uses the same default as stock Coolify.

---

### #6068 — [Bug]: Proxy for public databases times out after 10 minutes
- **URL:** https://github.com/coollabsio/coolify/issues/6068
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Duplicate of #7743. Same nginx stream `proxy_timeout` issue.

**Draft Comment:**

> Duplicate of #7743 — the nginx `stream` proxy uses a hardcoded `proxy_timeout`. See that issue for context on the fix path.

---

### #5959 — [Bug]: Coolify sometimes doesn't proxy template databases when set to public
- **URL:** https://github.com/coollabsio/coolify/issues/5959
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** The `StartDatabaseProxy` action fails silently when it can't resolve the database type or port. The addon's overlay adds comprehensive fallback logic and explicit error messages.

**Draft Comment:**

> This typically happens when `StartDatabaseProxy` can't resolve the internal port for the database type — the action fails silently and no iptables rules or nginx proxy container is created.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) overlays `StartDatabaseProxy` with multi-level port resolution: built-in type map → expanded DATABASE_PORT_MAP (~50 types) → partial image string matching → compose config extraction. If all fail, it throws with a message guiding the user to set `custom_type`. No more silent failures.

---

### #4210 — [Bug]: Proxy / "Make it publicly available" disabling on service restart
- **URL:** https://github.com/coollabsio/coolify/issues/4210
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** The proxy container (`{uuid}-proxy`) is not recreated on service restart. The addon's `StartDatabaseProxy` overlay handles the proxy lifecycle, but the restart trigger is a Coolify core issue.

**Draft Comment:**

> The `is_public` flag persists in the database, but the proxy container (`{uuid}-proxy`) is not recreated when the parent service restarts. The restart logic doesn't call `StartDatabaseProxy` — it only restarts the service containers.
>
> This is a Coolify core lifecycle issue. [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) overlays `StartDatabaseProxy` for expanded port mapping and multi-port support, but the restart trigger path is upstream of that overlay. The proxy container should be restarted whenever the parent container restarts — either via Docker's `depends_on` or by hooking the restart event.

---

### #7287 — [Bug]: Incorrectly shows PostgreSQL service as "exited" while container is running
- **URL:** https://github.com/coollabsio/coolify/issues/7287
- **Labels:** Possible Bug, Triage, Linear
- **Status:** Open
- **Relevance:** Related to service misclassification. If a database container is classified as ServiceApplication, the status tracking uses the wrong Docker container name pattern.

**Draft Comment:**

> If the PostgreSQL container in a Chatwoot deployment is misclassified as `ServiceApplication` instead of `ServiceDatabase` (see #6320), the status tracking looks for the wrong container name pattern. `ServiceDatabase` containers have a different naming convention than `ServiceApplication` ones.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) fixes the classification via expanded `DATABASE_DOCKER_IMAGES` and the `coolify.database` label convention. Once the container is correctly classified as `ServiceDatabase`, status tracking uses the right container name.

---

### #5100 — [Bug]: Database container names only have the Coolify ID
- **URL:** https://github.com/coollabsio/coolify/issues/5100
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Container naming is a Coolify core issue in the deployment job, not directly related to classification. Noted for awareness.

**Draft Comment:**

> Container naming for standalone databases is set in the deployment job — the container gets the UUID as its name without a descriptive prefix. This is a Coolify core issue in the container creation logic, not related to database classification.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) doesn't modify container naming. The addon's network management feature does use container names for DNS resolution within managed networks, so descriptive names would be beneficial.

---

## Network Management & Isolation

### #2495 — [Feature]: Container Network tab
- **URL:** https://github.com/coollabsio/coolify/issues/2495
- **Labels:** Feature, Bounty ($10), Enhancement
- **Status:** Open
- **Relevance:** Corelix Platform implements a full network management system — server-level NetworkManager UI, per-resource NetworkAssignment UI, and environment-level auto-isolation.

**Draft Comment:**

> Implemented in [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) as the Network Management feature.
>
> What it provides:
> - **Server > Networks page** — lists all managed Docker networks with type badges (environment, shared, proxy), container membership counts, and create/delete controls
> - **Per-resource network assignment** — each Application, Service, and Database has a Networks section showing current memberships with connect/disconnect controls
> - **Environment auto-isolation** — each environment gets its own Docker bridge network (`ce-env-{uuid}`). Resources within an environment can communicate by container name via DNS
> - **Shared networks** — user-created networks for cross-environment communication
> - **Proxy network isolation** — dedicated `ce-proxy-{server_uuid}` network for Traefik/Caddy, so the proxy doesn't have network-level access to internal-only services
>
> Three isolation modes: `none` (manual only), `environment` (auto-create per-env networks), `strict` (also disconnects from default `coolify` network).
>
> Uses post-deployment hooks — no overlay of `ApplicationDeploymentJob.php`. After Coolify deploys normally, `NetworkReconcileJob` connects containers to the correct managed networks via `docker network connect`.

---

### #7318 — [Bug]: Docker networks --driver overlay fails on standalone Docker
- **URL:** https://github.com/coollabsio/coolify/issues/7318
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** The addon's `NetworkService::resolveNetworkDriver()` auto-detects the correct driver — `overlay` for Swarm managers, `bridge` for standalone Docker. Prevents this exact error.

**Draft Comment:**

> Overlay networks require Docker Swarm mode — they fail on standalone Docker with "This node is not a swarm manager."
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) handles this automatically via `resolveNetworkDriver()` — all network creation methods check whether the server is a Swarm manager (`docker info --format '{{.Swarm.ControlAvailable}}'`). Swarm managers get `--driver overlay`, standalone servers get `--driver bridge`. No manual driver selection needed.
>
> For Swarm environments, the addon also supports `--opt encrypted` for IPsec encryption between nodes, and `--attachable` for standalone containers to join overlay networks.

---

### #4873 — [Bug]: Docker network scope: local vs swarm
- **URL:** https://github.com/coollabsio/coolify/issues/4873
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Same driver auto-detection. The addon's Phase 3 (Swarm support) handles the scope distinction transparently.

**Draft Comment:**

> The scope difference (local vs swarm) comes from the network driver — `bridge` creates local-scope networks, `overlay` creates swarm-scope networks. Coolify doesn't auto-detect which driver to use based on the server's Swarm status.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) auto-detects the correct driver in `resolveNetworkDriver()`. On Swarm managers, all managed networks use `overlay` with `--attachable`. On standalone Docker, they use `bridge`. The driver selection is transparent — users don't need to specify it.

---

### #5597 — [Bug]: Predefined network doesn't work for docker compose services
- **URL:** https://github.com/coollabsio/coolify/issues/5597
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Coolify's compose parsing rewrites network definitions. The addon's post-deployment hook approach sidesteps this — networks are connected after Coolify finishes deployment, not during compose parsing.

**Draft Comment:**

> Coolify's `parsers.php` rewrites the `networks:` section of docker-compose files during service import, which can strip or override predefined network configurations.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) avoids this entirely by using post-deployment hooks. Instead of modifying the compose file, the addon waits for Coolify to finish deployment, then connects containers to managed networks via `docker network connect`. This works regardless of what `parsers.php` does to the compose — the network assignment happens at the Docker level after the container is running.
>
> For explicit network requirements, the per-resource Networks UI lets you manually assign any managed network to a resource. The connection persists across restarts via the `NetworkReconcileJob`.

---

### #7655 — [Enhancement]: Enable sharing env vars or configs between containers
- **URL:** https://github.com/coollabsio/coolify/issues/7655
- **Labels:** Bounty ($75), Triage, Enhancement
- **Status:** Open
- **Relevance:** Shared networks enable cross-container communication, which is the infrastructure prerequisite for service discovery. The addon doesn't handle env var sharing directly, but network connectivity is the foundation.

**Draft Comment:**

> Network connectivity is the prerequisite for cross-container communication. By default, Coolify puts all containers on the `coolify` network, but there's no structured way to control which containers can reach each other.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) provides the network layer:
> - **Environment networks** — all resources in the same environment automatically share a network and can resolve each other by container name via Docker DNS
> - **Shared networks** — user-created networks for cross-environment communication (e.g., a shared Redis that multiple environments need to reach)
>
> Env var sharing itself (propagating values like database URLs between resources) is a higher-level feature that would need Coolify core support — something like a `$SERVICE_URL_*` convention that resolves to the internal Docker DNS name. The network isolation ensures that only containers on the same network can actually reach each other, regardless of whether they have the connection string.

---

### #5362 — [Bug]: Multiple Supabase instances — public port creates connection mixing
- **URL:** https://github.com/coollabsio/coolify/issues/5362
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** Network isolation prevents this class of issue. When each Supabase instance runs in its own environment network, cross-instance communication is impossible unless explicitly shared.

**Draft Comment:**

> When multiple Supabase instances share the default `coolify` network, Docker DNS can resolve container names across instances — leading to connection mixing where one instance's client accidentally reaches another's database.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) prevents this with environment network isolation. Each environment gets its own Docker bridge network (`ce-env-{uuid}`). DNS resolution is scoped to the network — containers in Environment A cannot resolve container names in Environment B. In `strict` mode, the addon also disconnects containers from the default `coolify` network, eliminating the shared namespace entirely.

---

### #4690 — [Bug]: Mongo "Make it publicly available" overwrites nginx:stable-alpine image
- **URL:** https://github.com/coollabsio/coolify/issues/4690
- **Labels:** Possible Bug, Triage
- **Status:** Open
- **Relevance:** The addon's `StartDatabaseProxy` overlay uses the same nginx image pull. This is a Docker image tagging issue — the proxy should use a pinned image tag to avoid conflicts.

**Draft Comment:**

> The `StartDatabaseProxy` action pulls `nginx:stable-alpine` for the TCP proxy container. If another service uses the same tag, Docker overwrites the local image. This is a Docker image naming issue — the proxy should use a unique image name or a pinned digest.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) overlays `StartDatabaseProxy` for port mapping and multi-port proxy, but inherits the same image tag. A pinned image reference (e.g., `nginx:stable-alpine@sha256:...`) would prevent the overwrite.

---

### #8431 — [Bug]: Downloading Supabase DB Backup fails with "Team not found."
- **URL:** https://github.com/coollabsio/coolify/issues/8431
- **Labels:** Possible Bug
- **Status:** Open
- **Relevance:** Team resolution for ServiceDatabase backups. The addon's resource backup downloads use the same team context resolution.

**Draft Comment:**

> The "Team not found" error comes from the backup download endpoint not correctly resolving the team for a `ServiceDatabase` instance. ServiceDatabase belongs to a Service, which belongs to an Environment, which belongs to a Project, which belongs to a Team. The chain is longer than for standalone databases.
>
> [corelix-platform](https://github.com/amirhmoradi/coolify-enhanced) uses the same team resolution chain for resource backup downloads. The addon's `ResourceBackupController` traverses `resource → environment → project → team` explicitly. If you're hitting this on stock Coolify, it's likely a missing relationship in the download endpoint's authorization check.

---

## Summary

| Feature Area | Issues Tracked | Key Issues |
|-------------|---------------|------------|
| Granular Permissions | 3 | #2378, #6894, #5293 |
| Encrypted S3 Backups | 2 | #7776, #5698 |
| Resource Backups | 7 | #2389, #7456, #5434, #2501, #7423, #8139, #6542 |
| Custom Template Sources | 6 | #6653, #4849, #3597, #4813, #7642, #8264, #4778 |
| Enhanced Database Classification | 10 | #7528, #6320, #6265, #4805, #8148, #7743, #6068, #5959, #4210, #7287, #5100 |
| Network Management | 6 | #2495, #7318, #4873, #5597, #7655, #5362, #4690, #8431 |
| **Total** | **34** | |

### Issues with Bounties

| Issue | Bounty | Feature Area |
|-------|--------|-------------|
| #6894 | $1,000 | Granular Permissions |
| #7423 | $1,000 | Resource Backups |
| #7528 | $200 | Database Classification |
| #7743 | $100 | Database Classification |
| #7655 | $75 | Network Management |
| #2389 | $50 | Resource Backups |
| #7642 | $50 | Custom Templates |
| #2495 | $10 | Network Management |
| #2378 | Bounty (unspecified) | Granular Permissions |

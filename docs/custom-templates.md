# Custom Template Sources

This guide covers how to create, host, and use custom service templates with Coolify Enhanced. Custom template sources let you add external GitHub repositories containing docker-compose templates that appear alongside Coolify's built-in one-click services in the **New Resource** page.

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Template Format](#template-format)
  - [Metadata Headers](#metadata-headers)
  - [Docker Compose Body](#docker-compose-body)
  - [Environment Variables](#environment-variables)
  - [Magic Environment Variables](#magic-environment-variables)
  - [Volumes and Storage](#volumes-and-storage)
  - [Health Checks](#health-checks)
  - [Logos](#logos)
- [Database Classification](#database-classification)
- [Repository Structure](#repository-structure)
- [Adding a Template Source](#adding-a-template-source)
- [API Management](#api-management)
- [How It Works](#how-it-works)
- [Name Collision Handling](#name-collision-handling)
- [Troubleshooting](#troubleshooting)
- [Reference: Complete Example](#reference-complete-example)

---

## Overview

Coolify ships with 200+ built-in one-click service templates (Ghost, Gitea, Uptime Kuma, n8n, etc.). Custom Template Sources lets you extend this list with your own templates hosted in any GitHub repository — public or private.

Key characteristics:

- **Same format as Coolify** — Templates use the exact same YAML structure as Coolify's built-in templates.
- **Seamless integration** — Custom templates appear in the New Resource page alongside built-in ones, with a small label identifying their source.
- **Auto-sync** — Templates are fetched periodically from GitHub (default: every 6 hours).
- **Write-once** — After deploying a service from a template, the compose YAML is stored in the database. Removing a template source has zero impact on running services.
- **Private repo support** — Use a GitHub Personal Access Token for private repositories.

## Quick Start

1. Create a GitHub repository with a `templates/compose/` folder.
2. Add a YAML template file (see [Template Format](#template-format)).
3. In Coolify, go to **Settings > Templates**.
4. Click **+ Add Source** and enter your repository URL.
5. Click **Save & Sync**.
6. Go to **New Resource** — your template now appears in the Services list.

## Template Format

A custom template is a standard docker-compose YAML file with special comment headers that provide metadata to Coolify.

### Metadata Headers

Add these comment lines at the **top** of your YAML file, before any YAML content:

```yaml
# documentation: https://docs.example.com/
# slogan: A brief description of your service.
# tags: tag1,tag2,tag3
# category: self-hosting
# logo: svgs/myservice.svg
# port: 8080
```

| Header | Required | Description |
|--------|----------|-------------|
| `documentation` | No | URL to the service's documentation. Displayed as a link icon on the service card. An `?utm_source=coolify.io` parameter is appended automatically. |
| `slogan` | No | Short description shown below the service name. Keep it under 80 characters. Defaults to the filename in Title Case. |
| `tags` | No | Comma-separated list of search tags. Helps users find the template via the search bar. |
| `category` | No | Category for filtering (e.g., `monitoring`, `cms`, `ai`, `development`). Supports multiple categories separated by commas: `category: monitoring,devops`. |
| `logo` | No | Path to the service logo. Can be a relative path (resolved from the template folder) or an absolute URL (`https://...`). SVG format is strongly preferred. Defaults to `svgs/default.webp`. |
| `port` | No | The primary port the service listens on. Used for generating `SERVICE_URL_*` variables. |
| `env_file` | No | Path to an `.env` file (relative to the template folder) containing default environment variables. |
| `type` | No | `database` or `application`. Overrides automatic classification for all services in the template. See [Database Classification](#database-classification). |
| `ignore` | No | Set to `true` to exclude this file from the template list. Useful for work-in-progress templates. |
| `minversion` | No | Minimum Coolify version required (e.g., `4.0.0-beta.300`). Defaults to `0.0.0`. |

### Docker Compose Body

The rest of the file is a standard docker-compose YAML definition. Coolify requires a `services` section at minimum:

```yaml
# documentation: https://github.com/louislam/uptime-kuma
# slogan: A self-hosted monitoring tool.
# tags: monitoring,status,uptime
# category: monitoring
# logo: svgs/uptime-kuma.svg
# port: 3001

services:
  uptime-kuma:
    image: louislam/uptime-kuma:2
    environment:
      - SERVICE_URL_UPTIMEKUMA_3001
    volumes:
      - uptime-kuma-data:/app/data
    healthcheck:
      test: ["CMD-SHELL", "extra/healthcheck"]
      interval: 5s
      timeout: 5s
      retries: 10
```

### Environment Variables

Coolify detects environment variables using `${VARIABLE_NAME}` syntax and displays them in the UI for editing.

**Variable types:**

| Syntax | Behavior |
|--------|----------|
| `VARIABLE=hardcoded` | Hardcoded value. Not visible in the Coolify UI. |
| `VARIABLE=${MY_VAR}` | Editable in UI. User must provide a value. |
| `VARIABLE=${MY_VAR:-default}` | Editable in UI. Falls back to `default` if not set. |
| `VARIABLE=${MY_VAR:?}` | **Required.** Deployment fails if not set. Shown with red highlight in UI. |
| `VARIABLE=${MY_VAR:?default}` | Required with a default value. |

**Shared variables** from Coolify's shared environment section can be referenced using:

```yaml
- MY_VAR={{environment.SHARED_VARIABLE}}
```

Example:

```yaml
services:
  myapp:
    image: myapp:latest
    environment:
      - DATABASE_URL=${DATABASE_URL:?}              # Required, must be set
      - PORT=${PORT:-8080}                          # Optional, defaults to 8080
      - DEBUG=${DEBUG:-false}                       # Optional, defaults to false
      - SECRET_KEY=${SECRET_KEY:?changeme}          # Required, with default hint
      - SMTP_HOST={{environment.SMTP_HOST}}         # From shared environment
```

### Magic Environment Variables

Coolify generates dynamic service variables using the pattern `SERVICE_<TYPE>_<IDENTIFIER>`:

| Pattern | Description | Example |
|---------|-------------|---------|
| `SERVICE_URL_<NAME>_<PORT>` | Generates the service URL for a specific port | `SERVICE_URL_MYAPP_8080` |
| `SERVICE_FQDN_<NAME>_<PORT>` | Generates a fully-qualified domain name | `SERVICE_FQDN_MYAPP_8080` |
| `SERVICE_USER_<NAME>` | Generates a random 16-character username | `SERVICE_USER_MYSQL` |
| `SERVICE_PASSWORD_<NAME>` | Generates a random password | `SERVICE_PASSWORD_MYSQL` |
| `SERVICE_PASSWORD_64_<NAME>` | Generates a 64-character password | `SERVICE_PASSWORD_64_ADMIN` |
| `SERVICE_BASE64_<NAME>` | Generates a random 32-character base64 string | `SERVICE_BASE64_SECRETKEY` |
| `SERVICE_BASE64_64_<NAME>` | Generates a 64-character base64 string | `SERVICE_BASE64_64_TOKEN` |
| `SERVICE_BASE64_128_<NAME>` | Generates a 128-character base64 string | `SERVICE_BASE64_128_KEY` |

These are placed as bare values in `environment` arrays (no `=` sign):

```yaml
services:
  myapp:
    image: myapp:latest
    environment:
      - SERVICE_URL_MYAPP_8080            # Generates URL for this service
      - SERVICE_FQDN_MYAPP_8080           # Generates FQDN
      - ADMIN_USER=$SERVICE_USER_ADMIN    # Random username
      - ADMIN_PASS=$SERVICE_PASSWORD_ADMIN # Random password
      - SECRET=$SERVICE_BASE64_64_SECRET  # Random base64 token
```

**Important:** Identifiers that contain underscores cannot include ports. Use hyphens in identifiers when specifying a port: `SERVICE_URL_MY-APP_8080` not `SERVICE_URL_MY_APP_8080`.

### Volumes and Storage

**Named volumes** are the standard approach:

```yaml
services:
  myapp:
    image: myapp:latest
    volumes:
      - myapp-data:/app/data
      - myapp-config:/app/config
```

**Bind mounts with directory creation** — use `is_directory: true` to have Coolify create the directory:

```yaml
volumes:
  - type: bind
    source: ./data
    target: /app/data
    is_directory: true
```

**File generation with content** — Coolify can create configuration files with dynamic content (not available in standard Docker Compose):

```yaml
volumes:
  - type: bind
    source: ./config.yml
    target: /app/config.yml
    content: |
      database:
        host: ${DB_HOST:-localhost}
        port: ${DB_PORT:-5432}
```

Alternatively, use the top-level `configs` element for file management.

### Health Checks

Always include health checks so Coolify can track service readiness:

```yaml
services:
  myapp:
    image: myapp:latest
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 5s
      timeout: 20s
      retries: 10
```

For services that don't need health tracking (e.g., one-off migration containers), use `exclude_from_hc: true`:

```yaml
services:
  migrations:
    image: myapp:latest
    command: ["migrate"]
    exclude_from_hc: true
```

### Logos

- **SVG format** is strongly preferred.
- **Relative paths** are resolved from the template folder and converted to raw GitHub URLs (e.g., `logo: ../logos/myservice.svg`).
- **Absolute URLs** are used directly (e.g., `logo: https://example.com/logo.svg`).
- If no logo is specified, the default Coolify placeholder is used.

Relative path example with this repo structure:

```
my-templates/
├── templates/
│   └── compose/
│       └── myservice.yaml     # logo: ../logos/myservice.svg
└── logos/
    └── myservice.svg
```

## Database Classification

Coolify classifies each service in a docker-compose template as either a **database** or an **application**. Databases get features like "Make Publicly Available" (TCP proxy), scheduled backups, and database import. Coolify Enhanced expands the automatic recognition to 50+ additional database images, but for custom or uncommon databases, you may need to explicitly classify services.

### Template-Level: `# type: database`

Add the `# type:` metadata header to classify **all services** in the template:

```yaml
# documentation: https://memgraph.com/docs
# slogan: Real-time graph database
# tags: graph,database,cypher
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    volumes:
      - memgraph-data:/var/lib/memgraph
```

Valid values: `database` or `application`. This injects `coolify.database` labels into all services during parsing.

### Per-Service: `coolify.database` Label

For multi-service templates with mixed types (e.g., a database + admin UI), use the Docker label on individual services:

```yaml
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    # Gets coolify.database=true from # type: database header

  memgraph-lab:
    image: memgraph/lab:latest
    labels:
      coolify.database: "false"  # Override: web UI, not a database
```

Per-service labels always take precedence over the template-level `# type:` header.

### When Do You Need This?

You **don't** need to specify `# type:` if your database image is already in Coolify Enhanced's expanded recognition list (50+ images including Memgraph, Milvus, Qdrant, Neo4j, Cassandra, etc.). The classification is automatic.

You **should** specify `# type: database` when:
- Using a custom or private database image that isn't in the recognition list
- Using a non-standard image tag that doesn't contain the database name
- You want to ensure correct classification regardless of future list changes

### Label Format

The `coolify.database` label accepts both Docker Compose label formats:

```yaml
# Map format
labels:
  coolify.database: "true"

# Array format
labels:
  - coolify.database=true
```

Boolean parsing is flexible: accepts `true/false`, `1/0`, `yes/no`, `on/off` (case-insensitive).

### Multi-Port Proxy: `coolify.proxyPorts` Label

Databases that expose multiple ports (e.g., Memgraph with bolt on 7687 and log viewer on 7444) can declare them via the `coolify.proxyPorts` label. This enables the multi-port proxy UI in Coolify, allowing users to independently toggle and assign public ports for each internal port.

```yaml
services:
  memgraph:
    image: memgraph/memgraph:latest
    labels:
      coolify.database: "true"
      coolify.proxyPorts: "7687:bolt,7444:log-viewer"
```

**Label format:** `"internalPort:label,internalPort:label,..."`

| Component | Description |
|-----------|-------------|
| `internalPort` | The container port number (integer) |
| `label` | Human-readable name shown in the UI (e.g., "bolt", "log-viewer", "admin-ui") |

**Key points:**

- When present, the "Make Publicly Available" section shows a per-port table instead of a single toggle
- Each port can be independently enabled/disabled with its own public port number
- The label name is case-sensitive: must be `coolify.proxyPorts` (not `coolify.proxyports`)
- Works alongside `coolify.database: "true"` — the service must be classified as a database for the proxy to work
- If the label is absent, the standard single-port proxy UI is shown (fully backward compatible)

**Example: Neo4j with bolt + HTTP + HTTPS:**

```yaml
services:
  neo4j:
    image: neo4j:5
    labels:
      coolify.database: "true"
      coolify.proxyPorts: "7687:bolt,7474:browser,7473:browser-ssl"
```

## Repository Structure

A template source repository must contain YAML files in a configurable folder path. The default expected structure is:

```
your-repo/
├── templates/
│   └── compose/
│       ├── service-a.yaml
│       ├── service-b.yaml
│       └── service-c.yml
├── logos/                     # Optional: store logos here
│   ├── service-a.svg
│   └── service-b.svg
└── README.md
```

**Key rules:**

- Template files must have `.yaml` or `.yml` extension.
- Only files in the configured folder are scanned (no recursive subdirectory scanning).
- The template name is derived from the filename (without extension): `my-service.yaml` becomes `my-service`, displayed as "My Service" in the UI.
- Maximum 500 templates per source (configurable).
- Maximum 1MB per template file.

You can customize the folder path when adding a source (e.g., `compose/` or `services/templates/`).

## Adding a Template Source

### Via the UI

1. Go to **Settings > Templates** in your Coolify dashboard.
2. Click **+ Add Source**.
3. Fill in:
   - **Name**: A display name for this source (e.g., "My Company Templates").
   - **Repository URL**: The GitHub repository URL (e.g., `https://github.com/myorg/coolify-templates`).
   - **Branch**: The branch to fetch from (default: `main`).
   - **Folder Path**: Path to the templates folder (default: `templates/compose`).
   - **Auth Token** (optional): A GitHub Personal Access Token for private repositories.
4. Click **Save & Sync**.

The source will be validated (checks GitHub connectivity and lists files), then templates are fetched and cached.

### Auto-Sync

By default, all enabled template sources are automatically synced every 6 hours. This is configurable via the `COOLIFY_TEMPLATE_SYNC_FREQUENCY` environment variable (cron expression):

```bash
# Every 6 hours (default)
COOLIFY_TEMPLATE_SYNC_FREQUENCY="0 */6 * * *"

# Every hour
COOLIFY_TEMPLATE_SYNC_FREQUENCY="0 * * * *"

# Disable auto-sync
COOLIFY_TEMPLATE_SYNC_FREQUENCY=
```

You can also trigger a manual sync from the Settings > Templates page.

## API Management

Template sources can be managed via the REST API.

### List Sources

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  https://your-coolify.example.com/api/v1/template-sources
```

### Create Source

```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Templates",
    "repository_url": "https://github.com/myorg/coolify-templates",
    "branch": "main",
    "folder_path": "templates/compose"
  }' \
  https://your-coolify.example.com/api/v1/template-sources
```

### Trigger Sync

```bash
# Sync a single source
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  https://your-coolify.example.com/api/v1/template-sources/{uuid}/sync

# Sync all enabled sources
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  https://your-coolify.example.com/api/v1/template-sources/sync-all
```

### Delete Source

```bash
curl -s -X DELETE -H "Authorization: Bearer $TOKEN" \
  https://your-coolify.example.com/api/v1/template-sources/{uuid}
```

Deleting a source removes the cached templates but has **no effect on deployed services** — they continue running with the compose stored in the database.

## How It Works

```
GitHub Repository
       │
       ▼
[Sync triggered (manual or scheduled)]
       │
       ▼
SyncTemplateSourceJob
  ├── List YAML files via GitHub Contents API
  │   (falls back to Trees API for large directories)
  ├── Download each file from raw.githubusercontent.com
  ├── Parse metadata headers (# key: value)
  ├── Validate YAML has `services` section
  ├── Validate docker-compose for injection safety
  ├── Base64-encode compose content
  └── Cache result to /data/coolify/custom-templates/{source-uuid}/templates.json
       │
       ▼
[User opens New Resource page]
       │
       ▼
get_service_templates()
  ├── Load Coolify's built-in templates
  ├── Load all enabled sources' cached templates
  ├── Handle name collisions (built-in always wins)
  └── Return merged collection
       │
       ▼
Services grid renders all templates
  └── Custom templates show a source label badge
```

## Name Collision Handling

When a custom template has the same name as a built-in template or another custom template:

1. **Built-in templates always win.** A custom template named `ghost` gets renamed to `ghost-{source-slug}` (e.g., `ghost-my-company`).
2. **Custom-to-custom collisions.** If two different sources both have a template named `myservice`, both get the source slug suffix.

The source slug is derived from the source name you provide when adding it.

## Troubleshooting

### Templates not appearing

1. Check that the source is **enabled** in Settings > Templates.
2. Click **Sync** to trigger a manual refresh.
3. Check for sync errors — the status badge shows `synced`, `failed`, or `syncing`.
4. Expand the source's template list to verify templates were parsed correctly.
5. Ensure your YAML files have a `services` section — files without it are skipped.

### Sync fails with authentication error

- For private repositories, add a GitHub Personal Access Token with `repo` scope.
- For GitHub Enterprise, ensure the token has access to the repository.

### Sync fails with "not found"

- Verify the repository URL, branch name, and folder path.
- Public repos should work without authentication. Private repos need a token.
- Check that the folder path exists and contains `.yaml` or `.yml` files.

### GitHub API rate limits

- **Unauthenticated requests:** 60 per hour.
- **Authenticated requests:** 5,000 per hour.
- If you have many sources or large repositories, add an auth token to avoid rate limiting.

### Template fails validation

Some templates may be skipped during sync if they fail docker-compose injection validation. Check the Coolify logs for warnings:

```bash
docker logs coolify 2>&1 | grep "TemplateSourceService"
```

---

## Reference: Complete Example

Here's a complete example template for a Plausible Analytics service with a PostgreSQL database and ClickHouse for analytics:

```yaml
# documentation: https://plausible.io/docs/self-hosting
# slogan: Lightweight and privacy-friendly Google Analytics alternative.
# tags: analytics,privacy,web,statistics,plausible
# category: analytics
# logo: svgs/plausible.svg
# port: 8000

services:
  plausible:
    image: ghcr.io/plausible/community-edition:v2
    command: sh -c "sleep 10 && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run"
    environment:
      - SERVICE_FQDN_PLAUSIBLE_8000
      - BASE_URL=$SERVICE_FQDN_PLAUSIBLE
      - SECRET_KEY_BASE=$SERVICE_BASE64_64_PLAUSIBLE
      - TOTP_VAULT_KEY=$SERVICE_BASE64_PLAUSIBLE
      - DATABASE_URL=postgres://postgres:$SERVICE_PASSWORD_POSTGRES@plausible-db:5432/plausible_db
      - CLICKHOUSE_DATABASE_URL=http://plausible-events-db:8123/plausible_events_db
      - DISABLE_REGISTRATION=${DISABLE_REGISTRATION:-invite_only}
      - MAILER_EMAIL=${MAILER_EMAIL:-hello@example.com}
      - SMTP_HOST_ADDR=${SMTP_HOST_ADDR}
      - SMTP_HOST_PORT=${SMTP_HOST_PORT:-25}
      - SMTP_USER_NAME=${SMTP_USER_NAME}
      - SMTP_USER_PWD=${SMTP_USER_PWD}
      - SMTP_HOST_SSL_ENABLED=${SMTP_HOST_SSL_ENABLED:-false}
      - SMTP_RETRIES=${SMTP_RETRIES:-2}
    depends_on:
      plausible-db:
        condition: service_healthy
      plausible-events-db:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://localhost:8000/api/health"]
      interval: 10s
      timeout: 5s
      retries: 5

  plausible-db:
    image: postgres:16-alpine
    volumes:
      - plausible-db-data:/var/lib/postgresql/data
    environment:
      - POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES
      - POSTGRES_DB=plausible_db
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres"]
      interval: 5s
      timeout: 5s
      retries: 10

  plausible-events-db:
    image: clickhouse/clickhouse-server:24.3-alpine
    volumes:
      - plausible-events-data:/var/lib/clickhouse
      - type: bind
        source: ./clickhouse-config.xml
        target: /etc/clickhouse-server/config.d/logging.xml
        content: |
          <clickhouse>
            <logger>
              <level>warning</level>
              <console>true</console>
            </logger>
            <listen_host>0.0.0.0</listen_host>
          </clickhouse>
      - type: bind
        source: ./clickhouse-user-config.xml
        target: /etc/clickhouse-server/users.d/logging.xml
        content: |
          <clickhouse>
            <profiles>
              <default>
                <log_queries>0</log_queries>
                <log_query_threads>0</log_query_threads>
              </default>
            </profiles>
          </clickhouse>
    ulimits:
      nofile:
        soft: 262144
        hard: 262144
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:8123/ping"]
      interval: 5s
      timeout: 5s
      retries: 10
```

A simpler single-service example is also provided in [`docs/examples/`](examples/):

- [`whoami.yaml`](examples/whoami.yaml) — A minimal template for the Traefik Whoami test container.

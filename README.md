# Coolify Enhanced

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Build and Publish Docker Image](https://github.com/amirhmoradi/coolify-enhanced/actions/workflows/docker-publish.yml/badge.svg)](https://github.com/amirhmoradi/coolify-enhanced/actions/workflows/docker-publish.yml)
[![Docker Image](https://img.shields.io/badge/ghcr.io-coolify--enhanced-blue)](https://ghcr.io/amirhmoradi/coolify-enhanced)

**The missing enterprise features for Coolify v4 — granular permissions, encrypted backups, volume/config backups, and custom service templates.**

Coolify Enhanced is a drop-in addon for [Coolify](https://coolify.io) that adds the access control, backup security, and template extensibility features that teams need when running Coolify in production. It installs in under 2 minutes, requires zero changes to your existing setup, and can be removed cleanly at any time.

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

All four features are **independent** — enable only what you need. When disabled, Coolify behaves exactly as stock.

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

### Custom Template Sources
- Add any GitHub repository (public or private) as a template source
- Templates appear alongside Coolify's built-in services on the New Resource page
- **Filter by source** — dropdown to show All, Coolify Official, or a specific custom source
- Auto-sync on configurable schedule (default: every 6 hours)
- Same YAML format as Coolify's built-in templates — zero learning curve
- Name collision handling — built-in templates always take precedence
- Deployed services are independent of template sources (write-once)

---

## Screenshots

### Access Matrix — Team Permission Management

<!-- SCREENSHOT: Access Matrix on the Team > Admin page showing the user/project/environment permission grid with dropdowns -->
![Access Matrix](<!-- INSERT_SCREENSHOT_URL: access-matrix.png -->)

*The Access Matrix provides a unified view of all users, projects, and environments with per-cell permission controls.*

### S3 Storage — Encryption Settings

<!-- SCREENSHOT: S3 Storage detail page showing the encryption toggle, password fields, filename encryption dropdown -->
![Encryption Settings](<!-- INSERT_SCREENSHOT_URL: encryption-settings.png -->)

*Enable per-storage encryption with a single toggle. Configure encryption password, salt, and filename encryption mode.*

### Resource Backups — Application Configuration Page

<!-- SCREENSHOT: Application configuration page with Resource Backups sidebar item selected, showing backup schedules and executions -->
![Resource Backups](<!-- INSERT_SCREENSHOT_URL: resource-backups.png -->)

*Schedule volume, configuration, or full backups for any application, database, or service with independent cron expressions.*

### Resource Backups — Server Overview

<!-- SCREENSHOT: Server sidebar with Resource Backups item, showing all resource backups for the server -->
![Server Resource Backups](<!-- INSERT_SCREENSHOT_URL: server-resource-backups.png -->)

*View and manage all resource backups across a server from a single page.*

### Settings — Restore & Import

<!-- SCREENSHOT: Settings > Restore page showing the JSON backup viewer and env var bulk import -->
![Restore Backup](<!-- INSERT_SCREENSHOT_URL: restore-backup.png -->)

*Browse configuration backup contents, bulk-import environment variables, and follow step-by-step restoration guides.*

### Custom Template Sources — Settings Page

<!-- SCREENSHOT: Settings > Templates page showing added sources with sync status, template count, and template previews -->
![Custom Template Sources](<!-- INSERT_SCREENSHOT_URL: custom-template-sources.png -->)

*Add GitHub repositories as template sources, view sync status, preview discovered templates, and trigger manual syncs.*

### New Resource Page — Source Filter & Labels

<!-- SCREENSHOT: New Resource page showing service cards with custom template source labels and the source filter dropdown -->
![New Resource with Custom Templates](<!-- INSERT_SCREENSHOT_URL: new-resource-source-filter.png -->)

*Custom templates appear alongside built-in services with source labels. Use the source filter dropdown to narrow by origin.*

### Instance File Backup — Settings Page

<!-- SCREENSHOT: Settings > Backup page showing the Instance File Backup section below the native database backup -->
![Instance File Backup](<!-- INSERT_SCREENSHOT_URL: instance-file-backup.png -->)

*Schedule full `/data/coolify` directory backups (excluding backup directories) from the Settings page.*

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

That's it. Navigate to **Team > Admin** to see the Access Matrix, open any **S3 Storage** page for encryption settings, and check **Settings > Templates** to add custom template sources.

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
| **Enabled** (`COOLIFY_ENHANCED=true`) | All features active — permissions enforced, encryption available, templates loaded |
| **Disabled** (`COOLIFY_ENHANCED=false`) | Standard Coolify behavior — all team members have full access, no encryption, no custom templates |

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
| **Middleware injection** | Access Matrix on Team Admin page |
| **File overlays in Docker image** | Encryption-aware backup/restore jobs, custom template loading |
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

custom_template_sources
-----------------------
id / uuid
name / slug
repository_url / branch / folder_path
auth_token (encrypted)
is_enabled
sync_status / last_synced_at / sync_error
```

For the full architecture document including flow diagrams, security considerations, and extensibility points, see [Architecture](docs/architecture.md).

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
| Open source | MIT | MIT | Partial (CE/BE) | Apache 2.0 |
| Self-hosted PaaS | Yes | Yes | Container mgmt | Yes |

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes
4. Push to the branch
5. Open a Pull Request

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

**Built for teams that self-host with Coolify and need production-grade access control, backup security, and template flexibility.**

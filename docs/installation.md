# Installation Guide

This guide covers all methods of installing the Coolify Enhanced package.

## Prerequisites

- SSH/root access to your server
- Docker and Docker Compose (installed automatically if using the Coolify installer)
- Basic familiarity with Docker

## Quick Start (Recommended)

The fastest way to get started is using the setup script, which works on both fresh servers and existing Coolify installations.

### Interactive Setup

```bash
git clone https://github.com/amirhmoradi/coolify-enhanced.git
cd coolify-enhanced
sudo bash install.sh
```

This opens an interactive menu with options:
1. **Install Coolify** - Install Coolify from the official repository (for fresh servers)
2. **Install Enhanced Addon** - Install the enhanced addon
3. **Uninstall Enhanced Addon** - Remove the addon
4. **Check Installation Status** - See what's installed and running
5. **Full Setup** - Install Coolify + addon in one step

After each action, you're returned to the menu to continue with the next step.

### CLI Arguments (for automation)

```bash
# Fresh server: install everything non-interactively
sudo bash install.sh --install-coolify --install-addon --unattended

# Install only Coolify
sudo bash install.sh --install-coolify

# Install only the addon (Coolify already running)
sudo bash install.sh --install-addon

# Install addon using local build
sudo bash install.sh --install-addon --local

# Check installation status
sudo bash install.sh --status

# Uninstall addon
sudo bash install.sh --uninstall

# Show all options
sudo bash install.sh --help
```

### What the addon installer does

1. Verify your Coolify installation at `/data/coolify/source/`
2. Pull the pre-built image from GHCR (or build locally with `--local`)
3. Create `docker-compose.custom.yml` (Coolify natively supports this file)
4. Set the `COOLIFY_ENHANCED=true` environment variable
5. Restart Coolify via `upgrade.sh`
6. Verify the installation

### Manual Install

#### 1. Create Custom Compose File

Create `/data/coolify/source/docker-compose.custom.yml`:

```yaml
services:
  coolify:
    image: ghcr.io/amirhmoradi/coolify-enhanced:latest
    environment:
      - COOLIFY_ENHANCED=true
```

> **Note:** Coolify's install/upgrade scripts natively support `docker-compose.custom.yml`. This file is merged with the main compose file and survives Coolify updates.

#### 2. Restart Coolify

```bash
cd /data/coolify/source
bash upgrade.sh
```

#### 3. Verify Installation

1. Log into Coolify as an admin or owner
2. Navigate to **Team > Admin** to see the Access Matrix below the admin content
3. Navigate to any **S3 Storage** page to see the Encryption Settings section
4. Configure per-user project and environment permissions

---

## Installation Methods

### Method 1: Docker Compose Custom File (Recommended)

Best for: Simple installations, easy updates

**Pros:**
- No custom builds required
- Easy to update
- Survives Coolify updates (docker-compose.custom.yml is not overwritten)

**Steps:**

1. Create the custom compose file as shown in Quick Start
2. Pull the latest image:
   ```bash
   docker pull ghcr.io/amirhmoradi/coolify-enhanced:latest
   ```
3. Restart Coolify:
   ```bash
   cd /data/coolify/source
   bash upgrade.sh
   ```

### Method 2: Build Custom Image Locally

Best for: Custom modifications, air-gapped environments

**Pros:**
- Full control over build
- Can add additional customizations

**Steps:**

1. Clone the package repository:
   ```bash
   git clone https://github.com/amirhmoradi/coolify-enhanced.git
   cd coolify-enhanced
   ```

2. Build the image:
   ```bash
   docker build \
     --build-arg COOLIFY_VERSION=latest \
     -t coolify-enhanced:local \
     -f docker/Dockerfile .
   ```

3. Create `/data/coolify/source/docker-compose.custom.yml`:
   ```yaml
   services:
     coolify:
       image: coolify-enhanced:local
       environment:
         - COOLIFY_ENHANCED=true
   ```

4. Restart Coolify:
   ```bash
   cd /data/coolify/source
   bash upgrade.sh
   ```

Or use the automated installer:
```bash
sudo bash install.sh --local
```

---

## Configuration

### Environment Variables

Add these to `/data/coolify/source/.env`:

| Variable | Default | Description |
|----------|---------|-------------|
| `COOLIFY_ENHANCED` | `false` | Enable/disable enhanced features |

> For backward compatibility, `COOLIFY_GRANULAR_PERMISSIONS=true` is also supported.

### Feature Flag

The package is controlled by a feature flag. When disabled:
- All team members have full access (default Coolify behavior)
- UI components show a warning message
- Permission tables remain but aren't enforced
- Encryption settings are preserved but not used

To enable:
```bash
echo "COOLIFY_ENHANCED=true" >> /data/coolify/source/.env
```

To disable:
```bash
# Edit .env and set to false
COOLIFY_ENHANCED=false
```

---

## Database Migrations

Migrations run automatically on container startup via s6-overlay.

### Manual Migration

If needed, run migrations manually:

```bash
docker exec coolify php artisan migrate \
  --path=vendor/amirhmoradi/coolify-enhanced/database/migrations \
  --force
```

### Rollback

To rollback migrations:

```bash
docker exec coolify php artisan migrate:rollback \
  --path=vendor/amirhmoradi/coolify-enhanced/database/migrations
```

### Check Migration Status

```bash
docker exec coolify php artisan migrate:status
```

---

## Verifying Installation

### Check Package is Loaded

```bash
docker exec coolify php artisan package:discover
```

Look for `amirhmoradi/coolify-enhanced` in the output.

### Check API Routes are Registered

```bash
docker exec coolify php artisan route:list | grep permissions
```

Should show:
```
GET|HEAD  api/v1/permissions/project ...
POST      api/v1/permissions/project ...
...
```

### Check Migrations

```bash
docker exec coolify php artisan migrate:status | grep project_user
```

Should show migration as "Ran".

### Check Feature Flag

```bash
docker exec coolify php artisan tinker --execute="var_dump(config('coolify-enhanced.enabled'))"
```

Should output `bool(true)` if enabled.

---

## Updating

### Pre-built Image

```bash
cd /data/coolify/source
docker pull ghcr.io/amirhmoradi/coolify-enhanced:latest
bash upgrade.sh
```

### Self-built Image

```bash
cd coolify-enhanced
git pull
docker build \
  --build-arg COOLIFY_VERSION=latest \
  -t coolify-enhanced:local \
  -f docker/Dockerfile .

cd /data/coolify/source
bash upgrade.sh
```

---

## Reverting to Original Coolify

You can safely revert to the original Coolify image at any time. This section explains the impact and provides step-by-step instructions.

### Why Reverting is Safe

This package is designed to be **non-destructive**:

1. **Overlay files are image-specific** - Modified Coolify files only exist in the custom Docker image; reverting to the official image restores originals
2. **Database tables are isolated** - Extra tables (`project_user`, `environment_user`) and columns (`encryption_enabled`, etc.) are simply ignored by the original Coolify
3. **Extra columns are ignored** - Laravel's Eloquent ORM only reads columns defined in its models; additional columns won't cause errors
4. **Feature flag design** - You can disable features without removing the package

### Impact Analysis

| Component | After Reverting | Notes |
|-----------|-----------------|-------|
| Core Coolify functionality | Works normally | No impact whatsoever |
| Projects, resources, deployments | Fully preserved | All your data remains intact |
| Users and teams | Fully preserved | User accounts work as before |
| Team roles (Owner, Admin, Member) | Work normally | Standard Coolify role behavior |
| Granular permission settings | Stored but not enforced | Data remains in DB tables |
| Encryption settings | Stored but not used | Settings remain in s3_storages columns |
| Encrypted backups | Still encrypted on S3 | Cannot restore without the addon |
| Access Matrix UI | Not available | UI not in original image |
| Encryption Form UI | Not available | UI not in original image |
| Permission API endpoints | Not available | API routes not registered |

**Key points:**
- All team members will have full access to all projects (standard Coolify v4 behavior) after reverting
- Encrypted backups on S3 remain encrypted; you need the addon (or rclone with the same password) to restore them

---

### Revert Instructions

#### Automated Uninstall (Recommended)

```bash
sudo bash uninstall.sh
```

The script will:
1. Optionally clean up database tables (prompted)
2. Remove `docker-compose.custom.yml` (with backup)
3. Remove the environment variable from `.env`
4. Restart Coolify via `upgrade.sh`

#### Manual Uninstall

```bash
cd /data/coolify/source

# Remove the custom compose file
rm docker-compose.custom.yml

# Remove the environment variable
sed -i '/COOLIFY_ENHANCED/d' .env

# Restart Coolify with original image
bash upgrade.sh
```

---

### Database Cleanup (Optional)

The package's database tables and columns will remain after reverting but cause no harm. If you want to completely remove them:

#### Option 1: Using Artisan (if custom image is still running)

Before reverting, run the migration rollback:

```bash
docker exec coolify php artisan migrate:rollback \
  --path=vendor/amirhmoradi/coolify-enhanced/database/migrations
```

#### Option 2: Direct SQL (after reverting)

Connect to your Coolify database and execute:

```sql
-- Remove package tables
DROP TABLE IF EXISTS environment_user;
DROP TABLE IF EXISTS project_user;

-- Remove added columns from users table
ALTER TABLE users DROP COLUMN IF EXISTS is_global_admin;
ALTER TABLE users DROP COLUMN IF EXISTS status;

-- Remove encryption columns from s3_storages table
ALTER TABLE s3_storages DROP COLUMN IF EXISTS encryption_enabled;
ALTER TABLE s3_storages DROP COLUMN IF EXISTS encryption_password;
ALTER TABLE s3_storages DROP COLUMN IF EXISTS encryption_salt;
ALTER TABLE s3_storages DROP COLUMN IF EXISTS filename_encryption;
ALTER TABLE s3_storages DROP COLUMN IF EXISTS directory_name_encryption;

-- Remove is_encrypted column from backup executions
ALTER TABLE scheduled_database_backup_executions DROP COLUMN IF EXISTS is_encrypted;
```

**How to connect to the database:**

```bash
# If using Coolify's bundled PostgreSQL
docker exec -it coolify-db psql -U coolify -d coolify

# Or check your .env for database credentials
grep DB_ /data/coolify/source/.env
```

---

### Re-enabling Later

If you revert and later decide to use the enhanced features again:

1. **Your data is preserved** (if you didn't run database cleanup)
2. Simply follow the [Quick Start](#quick-start-recommended) installation again
3. All previously configured permission settings and encryption settings will be restored

---

### Comparison: Disable vs Full Revert

| Action | Disable Feature Flag | Full Revert |
|--------|---------------------|-------------|
| Permissions enforced | No | No |
| Encryption active | No | No |
| Custom UI available | Yes (shows "disabled" state) | No |
| Permission data preserved | Yes | Yes (unless cleaned) |
| Encryption settings preserved | Yes | Yes (unless cleaned) |
| Can re-enable quickly | Yes (change env var) | Yes (reinstall image) |
| Disk space | Uses custom image | Uses original image |
| Coolify updates | Manual (rebuild image) | Automatic |

**Recommendation:** Use "Disable Feature Flag" for temporary testing. Use "Full Revert" if you've decided not to use the package.

---

## Troubleshooting

### Package Not Loading

**Symptoms:**
- No Access Matrix on the Team > Admin page
- No Encryption Form on storage pages
- API endpoints return 404

**Solutions:**
1. Check container logs:
   ```bash
   docker logs coolify 2>&1 | grep -i enhanced
   ```
2. Clear caches:
   ```bash
   docker exec coolify php artisan cache:clear
   docker exec coolify php artisan config:clear
   docker exec coolify php artisan view:clear
   ```
3. Verify image:
   ```bash
   docker inspect coolify | grep Image
   ```
4. Verify docker-compose.custom.yml is loaded:
   ```bash
   cd /data/coolify/source
   docker compose config | grep image
   ```

### Migrations Not Running

**Symptoms:**
- Database errors about missing tables
- 500 errors when accessing permission features

**Solutions:**
1. Check s6 service log:
   ```bash
   docker exec coolify cat /var/log/s6-rc/addon-migration/current
   ```
2. Run migrations manually:
   ```bash
   docker exec coolify php artisan migrate --force \
     --path=vendor/amirhmoradi/coolify-enhanced/database/migrations
   ```

### Feature Flag Not Working

**Symptoms:**
- Permissions not being enforced
- "Features are disabled" message

**Solutions:**
1. Check .env file:
   ```bash
   grep COOLIFY_ENHANCED /data/coolify/source/.env
   ```
2. Check config is loaded:
   ```bash
   docker exec coolify php artisan config:show coolify-enhanced
   ```
3. Clear config cache:
   ```bash
   docker exec coolify php artisan config:clear
   ```

### Permission Denied After Enabling

**Symptoms:**
- Team members can't access projects
- 403 errors

**Solutions:**
1. Grant access to existing team members (this should happen automatically via migration)
2. Check user's team role (owner/admin bypasses all checks)
3. Manually grant access via the Access Matrix or API

---

## MCP Server Setup (AI Assistant Integration)

The Coolify Enhanced MCP Server lets AI assistants (Claude Desktop, Cursor, VS Code, Kiro IDE) manage your Coolify infrastructure through natural language. It runs on your **local workstation** — not on the Coolify server.

### Prerequisites

- Node.js 18+
- A Coolify instance with API enabled
- An API token (create in Coolify: **Settings > Keys & Tokens > API tokens**)

### Quick Setup

No installation needed — run directly via `npx`:

```bash
COOLIFY_BASE_URL=https://coolify.example.com \
COOLIFY_ACCESS_TOKEN=your-token \
npx @amirhmoradi/coolify-enhanced-mcp
```

### Configure Your AI Client

#### Claude Desktop

Add to your `claude_desktop_config.json` (macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`, Windows: `%APPDATA%\Claude\claude_desktop_config.json`):

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

#### Cursor / VS Code / Kiro IDE

Add to your workspace or global MCP settings:

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

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `COOLIFY_BASE_URL` | Yes | — | Coolify instance URL (e.g., `https://coolify.example.com`) |
| `COOLIFY_ACCESS_TOKEN` | Yes | — | API token with read/write/deploy scopes |
| `COOLIFY_ENHANCED` | No | auto-detect | Set `true` to force-enable enhanced tools |
| `COOLIFY_MCP_TIMEOUT` | No | `30000` | API request timeout (milliseconds) |
| `COOLIFY_MCP_RETRIES` | No | `3` | Retry attempts for failed requests |

### Feature Auto-Detection

The MCP server automatically detects whether your Coolify instance has the coolify-enhanced addon installed. If detected, 27 additional tools for permissions, resource backups, custom templates, and network management become available alongside the 72 core tools.

You can override auto-detection by setting `COOLIFY_ENHANCED=true`.

### Verify It Works

After configuring your AI client, try asking:

- "List all my Coolify servers"
- "Show me the projects in my Coolify instance"
- "What applications are running?"

The AI should use the MCP tools to query your Coolify instance and return results.

### MCP Server Development (Local)

If you want to develop or modify the MCP server:

```bash
cd mcp-server
npm install
npm run build    # Compile TypeScript
npm run dev      # Watch mode (recompile on change)
npm start        # Run the compiled server
```

For the full tool reference, see [mcp-server/README.md](../mcp-server/README.md).

---

## Support

- **Issues:** https://github.com/amirhmoradi/coolify-enhanced/issues
- **Discussions:** https://github.com/amirhmoradi/coolify-enhanced/discussions

---

## Version Compatibility

| Package Version | Coolify Version | Notes |
|-----------------|-----------------|-------|
| 1.0.x | 4.0.0-beta.x | Initial release |

**Note:** This package is for Coolify v4. Coolify v5 may include similar built-in features. A migration guide will be provided when v5 is released.

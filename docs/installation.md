# Installation Guide

This guide covers all methods of installing the Coolify Granular Permissions package.

## Prerequisites

- Running Coolify v4 installation
- Docker and Docker Compose
- SSH access to your Coolify server
- Basic familiarity with Docker

## Quick Start (Recommended)

The fastest way to install is using the pre-built Docker image method.

### 1. Stop Coolify

```bash
cd /data/coolify/source
docker compose down
```

### 2. Create Override File

Create or edit `/data/coolify/source/docker-compose.override.yml`:

```yaml
services:
  coolify:
    image: ghcr.io/amirhmoradi/coolify-granular-permissions:latest
    environment:
      - COOLIFY_GRANULAR_PERMISSIONS=true
```

### 3. Start Coolify

```bash
docker compose up -d
```

### 4. Verify Installation

1. Log into Coolify
2. Check for "User Management" in admin settings
3. Check for "Access" tab in project settings

---

## Installation Methods

### Method 1: Docker Compose Override (Recommended)

Best for: Simple installations, easy updates

**Pros:**
- No custom builds required
- Easy to update
- Survives Coolify updates

**Steps:**

1. Create override file as shown in Quick Start
2. Pull the latest image:
   ```bash
   docker pull ghcr.io/amirhmoradi/coolify-granular-permissions:latest
   ```
3. Restart Coolify:
   ```bash
   cd /data/coolify/source
   docker compose up -d
   ```

### Method 2: Modify docker-compose.prod.yml

Best for: Permanent installations, more control

**Pros:**
- All configuration in one file
- Clear visibility of changes

**Cons:**
- May be overwritten by Coolify updates

**Steps:**

1. Edit `/data/coolify/source/docker-compose.prod.yml`
2. Change the coolify service image:
   ```yaml
   services:
     coolify:
       # Change from:
       # image: "ghcr.io/coollabsio/coolify:${LATEST_IMAGE:-latest}"
       # To:
       image: "ghcr.io/amirhmoradi/coolify-granular-permissions:latest"
       environment:
         # Add this line:
         - COOLIFY_GRANULAR_PERMISSIONS=true
   ```
3. Restart:
   ```bash
   docker compose down && docker compose up -d
   ```

### Method 3: Build Custom Image Locally

Best for: Custom modifications, air-gapped environments

**Pros:**
- Full control over build
- Can add additional customizations

**Steps:**

1. Clone the package repository:
   ```bash
   git clone https://github.com/amirhmoradi/coolify-granular-permissions.git
   cd coolify-granular-permissions
   ```

2. Build the image:
   ```bash
   docker build \
     --build-arg COOLIFY_VERSION=latest \
     -t coolify-granular-permissions:local \
     -f docker/Dockerfile .
   ```

3. Update docker-compose to use local image:
   ```yaml
   services:
     coolify:
       image: coolify-granular-permissions:local
       environment:
         - COOLIFY_GRANULAR_PERMISSIONS=true
   ```

4. Start Coolify:
   ```bash
   cd /data/coolify/source
   docker compose up -d
   ```

---

## Configuration

### Environment Variables

Add these to `/data/coolify/source/.env`:

| Variable | Default | Description |
|----------|---------|-------------|
| `COOLIFY_GRANULAR_PERMISSIONS` | `false` | Enable/disable the feature |

### Feature Flag

The package is controlled by a feature flag. When disabled:
- All team members have full access (default Coolify behavior)
- UI components show a warning message
- Permission tables remain but aren't enforced

To enable:
```bash
echo "COOLIFY_GRANULAR_PERMISSIONS=true" >> /data/coolify/source/.env
```

To disable:
```bash
# Edit .env and set to false
COOLIFY_GRANULAR_PERMISSIONS=false
```

---

## Database Migrations

Migrations run automatically on container startup via s6-overlay.

### Manual Migration

If needed, run migrations manually:

```bash
docker exec coolify php artisan migrate \
  --path=vendor/amirhmoradi/coolify-granular-permissions/database/migrations \
  --force
```

### Rollback

To rollback migrations:

```bash
docker exec coolify php artisan migrate:rollback \
  --path=vendor/amirhmoradi/coolify-granular-permissions/database/migrations
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

Look for `amirhmoradi/coolify-granular-permissions` in the output.

### Check Routes are Registered

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
docker exec coolify php artisan tinker --execute="var_dump(config('coolify-permissions.enabled'))"
```

Should output `bool(true)` if enabled.

---

## Updating

### Pre-built Image

```bash
cd /data/coolify/source
docker compose pull coolify
docker compose up -d
```

### Self-built Image

```bash
cd coolify-granular-permissions
git pull
docker build \
  --build-arg COOLIFY_VERSION=latest \
  -t coolify-granular-permissions:local \
  -f docker/Dockerfile .

cd /data/coolify/source
docker compose up -d
```

---

## Uninstalling

### 1. Disable Feature Flag

```bash
# Edit /data/coolify/source/.env
COOLIFY_GRANULAR_PERMISSIONS=false
```

### 2. Restore Original Image

Edit `docker-compose.override.yml` or `docker-compose.prod.yml`:

```yaml
services:
  coolify:
    image: "ghcr.io/coollabsio/coolify:latest"
```

Or delete `docker-compose.override.yml` entirely.

### 3. Restart

```bash
cd /data/coolify/source
docker compose down
docker compose up -d
```

### 4. (Optional) Remove Database Tables

Connect to the database and drop tables:

```sql
DROP TABLE IF EXISTS environment_user;
DROP TABLE IF EXISTS project_user;
ALTER TABLE users DROP COLUMN IF EXISTS is_global_admin;
ALTER TABLE users DROP COLUMN IF EXISTS status;
```

---

## Troubleshooting

### Package Not Loading

**Symptoms:**
- No "Access" tab in projects
- No "User Management" in admin

**Solutions:**
1. Check container logs:
   ```bash
   docker logs coolify 2>&1 | grep -i permission
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
     --path=vendor/amirhmoradi/coolify-granular-permissions/database/migrations
   ```

### Feature Flag Not Working

**Symptoms:**
- Permissions not being enforced
- "Granular permissions are disabled" message

**Solutions:**
1. Check .env file:
   ```bash
   grep COOLIFY_GRANULAR_PERMISSIONS /data/coolify/source/.env
   ```
2. Check config is loaded:
   ```bash
   docker exec coolify php artisan config:show coolify-permissions
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
3. Manually grant access via UI or API

---

## Support

- **Issues:** https://github.com/amirhmoradi/coolify-granular-permissions/issues
- **Discussions:** https://github.com/amirhmoradi/coolify-granular-permissions/discussions

---

## Version Compatibility

| Package Version | Coolify Version | Notes |
|-----------------|-----------------|-------|
| 1.0.x | 4.0.0-beta.x | Initial release |

**Note:** This package is for Coolify v4. Coolify v5 may include similar built-in features. A migration guide will be provided when v5 is released.

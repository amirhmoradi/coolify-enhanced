# Coolify Granular Permissions

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Build and Publish Docker Image](https://github.com/amirhmoradi/coolify-granular-permissions/actions/workflows/docker-publish.yml/badge.svg)](https://github.com/amirhmoradi/coolify-granular-permissions/actions/workflows/docker-publish.yml)
[![Docker Image](https://img.shields.io/badge/ghcr.io-coolify--granular--permissions-blue)](https://ghcr.io/amirhmoradi/coolify-granular-permissions)

**Granular user role and project-level access management for Coolify v4**

> **Note**: This addon is designed for Coolify v4. Coolify v5 is expected to include similar functionality natively. When v5 is released with built-in granular permissions, we will provide a migration guide.

## TL;DR - Show me:
<img width="2378" height="1964" alt="coolify-granular-permissions-screenshot" src="https://github.com/user-attachments/assets/0fd6b8ee-d9a8-4154-b09e-a6dbe2d4e46a" />



## Overview

This Laravel package extends Coolify with fine-grained access control:

- **Project-level permissions**: Grant users access to specific projects only
- **Permission levels**: View Only, Deploy, Full Access
- **Environment overrides**: Fine-tune access per environment within each project
- **Access Matrix UI**: Unified table to manage all users, projects, and environments at a glance
- **API support**: Full REST API for permission management
- **Install/Uninstall scripts**: Automated setup with Coolify detection
- **Backward compatible**: Feature flag for safe rollout

## Requirements

- Coolify v4.x
- Docker & Docker Compose
- Root access to the Coolify server

## Installation

### Quick Install (Recommended)

```bash
git clone https://github.com/amirhmoradi/coolify-granular-permissions.git
cd coolify-granular-permissions
sudo bash install.sh
```

Running without arguments opens an interactive menu where you can:
1. **Install Coolify** from the official repository (for fresh servers)
2. **Install the Permissions Addon** (requires Coolify)
3. **Uninstall** the addon
4. **Check Status** of your installation
5. **Full Setup** (Coolify + addon in one step)

For automation or CI, use CLI arguments:

```bash
# Fresh server: install Coolify + addon non-interactively
sudo bash install.sh --install-coolify --install-addon --unattended

# Just install the addon (Coolify already running)
sudo bash install.sh --install-addon

# Check what's installed
sudo bash install.sh --status
```

Run `sudo bash install.sh --help` for all options.

### Manual Install

Create `/data/coolify/source/docker-compose.custom.yml`:

```yaml
services:
  coolify:
    image: ghcr.io/amirhmoradi/coolify-granular-permissions:latest
    environment:
      - COOLIFY_GRANULAR_PERMISSIONS=true
```

Restart Coolify:

```bash
cd /data/coolify/source
bash upgrade.sh
```

> **Note:** Coolify natively supports `docker-compose.custom.yml` — it is automatically merged with the main compose file and survives upgrades.

### Build Locally

```bash
git clone https://github.com/amirhmoradi/coolify-granular-permissions.git
cd coolify-granular-permissions
sudo bash install.sh --local
```

Or build manually:

```bash
docker build \
  --build-arg COOLIFY_VERSION=latest \
  -t coolify-custom:latest \
  -f docker/Dockerfile .
```

**Available Image Tags:**
- `latest` - Latest stable release (built against latest Coolify)
- `vX.Y.Z` - Specific release version (e.g., `v1.0.0`)
- `coolify-X.Y.Z` - Built against specific Coolify version (e.g., `coolify-4.0.0-beta.365`)
- `sha-XXXXXX` - Specific commit SHA for traceability

## Uninstallation

```bash
sudo bash uninstall.sh
```

The uninstaller will:
- Optionally clean up database tables (prompted)
- Remove `docker-compose.custom.yml` (with backup)
- Remove environment variables
- Restart Coolify with the original image

For manual uninstall, see [Installation Guide](docs/installation.md#reverting-to-original-coolify).

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `COOLIFY_GRANULAR_PERMISSIONS` | `false` | Enable/disable the granular permissions system |

### Configuration File

Publish the config file for customization:

```bash
php artisan vendor:publish --tag=coolify-permissions-config
```

This creates `config/coolify-permissions.php` with options for:

- Permission levels and their capabilities
- Roles that bypass permission checks
- Permission cascade behavior
- Auto-grant settings for new projects

## Usage

### Access Matrix UI

The access management interface is injected into Coolify's existing **Team > Admin** page. Navigate to `/team/admin` as an admin or owner to see the **Granular Access Management** section.

The Access Matrix provides:
- A unified table showing all users, projects, and environments
- Per-cell permission dropdowns (None, View Only, Deploy, Full Access)
- Environment-level inheritance from project permissions with optional overrides
- **All/None** buttons per-row (set all resources for a user) and per-column (set all users for a resource)
- Search/filter for users
- Visual indicators for role bypass (owner/admin), inheritance, and permission levels

### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/projects/{uuid}/access` | List users with project access |
| POST | `/api/v1/projects/{uuid}/access` | Grant project access |
| PATCH | `/api/v1/projects/{uuid}/access/{user_id}` | Update permissions |
| DELETE | `/api/v1/projects/{uuid}/access/{user_id}` | Revoke access |
| GET | `/api/v1/projects/{uuid}/access/{user_id}/check` | Check permission |

### API Example

```bash
# Grant deploy access to a user
curl -X POST "https://your-coolify.com/api/v1/projects/abc123/access" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"user_id": 5, "permission_level": "deploy"}'
```

## Permission Levels

| Level | View | Deploy | Manage | Delete |
|-------|------|--------|--------|--------|
| `view_only` | Yes | No | No | No |
| `deploy` | Yes | Yes | No | No |
| `full_access` | Yes | Yes | Yes | Yes |

## Role Hierarchy

| Role | Access Level |
|------|--------------|
| Owner | Full access to all team resources (bypasses permission checks) |
| Admin | Full access to all team resources (bypasses permission checks) |
| Member | Requires explicit project access |
| Viewer | Requires explicit project access (read-only by default) |

## How It Works

### Permission Check Flow

```
User Action -> Policy Check -> Is Granular Enabled?
                                    |
                    +---------------+---------------+
                    | No                            | Yes
                    v                               v
              Allow (v4 default)           Check User Role
                                                    |
                                    +---------------+---------------+
                                    | Owner/Admin                   | Member/Viewer
                                    v                               v
                              Allow (bypass)              Check Project Access
                                                                    |
                                                    +---------------+---------------+
                                                    | Has Access                    | No Access
                                                    v                               v
                                              Check Permission                   Deny
                                                    |
                                            +-------+-------+
                                            | Has Perm      | No Perm
                                            v               v
                                          Allow           Deny
```

### Access Matrix

```
+----------+-----------+-----------+-------+-------+-----------+------+
| User     | Role      | Project A               | Project B          |
|          |           | Project | Prod  | Stage | Project | Dev    |
+----------+-----------+---------+-------+-------+---------+--------+
| John     | owner     | bypass  |bypass |bypass | bypass  |bypass  |
| Jane     | member    | full    | inh.  | dep.  | view    | inh.   |
| Bob      | viewer    | none    | none  | view  | deploy  | inh.   |
+----------+-----------+---------+-------+-------+---------+--------+
```

### Database Schema

```
+---------------+       +---------------+       +---------------+
|    users      |       | project_user  |       |   projects    |
+---------------+       +---------------+       +---------------+
| id            |--+    | id            |    +--| id            |
| name          |  |    | user_id       |----+  | name          |
| email         |  +----| project_id    |-------| team_id       |
| is_global_    |       | permissions   |       +---------------+
|   admin       |       +---------------+
| status        |
+---------------+

+-------------------+
| environment_user  |
+-------------------+
| id                |
| user_id           |
| environment_id    |
| permissions       |
+-------------------+
```

## Upgrading Coolify

When upgrading Coolify to a new version:

1. Update the `COOLIFY_VERSION` build argument
2. Rebuild your custom image
3. Test in a staging environment
4. Deploy to production

```bash
docker build \
  --build-arg COOLIFY_VERSION=v4.0.1 \
  -t coolify-custom:v4.0.1 \
  -f docker/Dockerfile \
  .
```

## Reverting to Original Coolify

```bash
sudo bash uninstall.sh
```

Or manually:

```bash
cd /data/coolify/source
rm docker-compose.custom.yml
sed -i '/COOLIFY_GRANULAR_PERMISSIONS/d' .env
bash upgrade.sh
```

**Reverting is non-destructive** - your projects, users, and data remain intact. All team members will have full access to all projects (standard Coolify v4 behavior).

For detailed instructions including database cleanup options, see the [Installation Guide](docs/installation.md#reverting-to-original-coolify).

## Migrating to Coolify v5

When Coolify v5 releases with built-in granular permissions:

1. We will analyze v5's permission model
2. Provide a migration script to transfer your permission data
3. Update this README with migration instructions

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Support

- [GitHub Issues](https://github.com/amirhmoradi/coolify-granular-permissions/issues)
- [Coolify Discord](https://discord.gg/coolify)

## Acknowledgments

- [Coolify](https://coolify.io) - The amazing self-hostable platform this addon extends
- [Dokploy](https://dokploy.com) - Inspiration for the permission model

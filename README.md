# Coolify Granular Permissions

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Build and Publish Docker Image](https://github.com/amirhmoradi/coolify-granular-permissions/actions/workflows/docker-publish.yml/badge.svg)](https://github.com/amirhmoradi/coolify-granular-permissions/actions/workflows/docker-publish.yml)
[![Docker Image](https://img.shields.io/badge/ghcr.io-coolify--granular--permissions-blue)](https://ghcr.io/amirhmoradi/coolify-granular-permissions)

**Granular user role and project-level access management for Coolify v4**

> **Note**: This addon is designed for Coolify v4. Coolify v5 is expected to include similar functionality natively. When v5 is released with built-in granular permissions, we will provide a migration guide.

## Overview

This Laravel package extends Coolify with fine-grained access control:

- **Project-level permissions**: Grant users access to specific projects only
- **Permission levels**: View Only, Deploy, Full Access
- **New Viewer role**: Read-only access for stakeholders
- **Environment overrides**: Fine-tune access per environment
- **API support**: Full REST API for permission management
- **Backward compatible**: Feature flag for safe rollout

## Requirements

- Coolify v4.x
- Docker & Docker Compose
- Access to build custom Docker images

## Installation

### Option 1: Using Pre-built Docker Image (Recommended)

Pre-built images are automatically published to GitHub Container Registry on every release.

```bash
# Pull the latest image
docker pull ghcr.io/amirhmoradi/coolify-granular-permissions:latest

# Update your Coolify deployment
cd /data/coolify/source
```

Create or edit `docker-compose.override.yml`:

```yaml
services:
  coolify:
    image: ghcr.io/amirhmoradi/coolify-granular-permissions:latest
    environment:
      - COOLIFY_GRANULAR_PERMISSIONS=true
```

**Available Image Tags:**
- `latest` - Latest stable release (built against latest Coolify)
- `vX.Y.Z` - Specific release version (e.g., `v1.0.0`)
- `coolify-X.Y.Z` - Built against specific Coolify version (e.g., `coolify-4.0.0-beta.365`)
- `sha-XXXXXX` - Specific commit SHA for traceability

Restart Coolify:

```bash
docker compose -f docker-compose.prod.yml up -d
```

### Option 2: Build Your Own Image

```bash
# Clone this repository
git clone https://github.com/amirhmoradi/coolify-granular-permissions.git
cd coolify-granular-permissions

# Build the Docker image
docker build \
  --build-arg COOLIFY_VERSION=latest \
  -t coolify-custom:latest \
  -f docker/Dockerfile \
  .

# Push to your registry
docker tag coolify-custom:latest your-registry/coolify-custom:latest
docker push your-registry/coolify-custom:latest
```

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

### Web UI

1. **Admin Users Page** (`/admin/users`)
   - Create new users
   - Assign users to teams
   - Toggle global admin status
   - Suspend/unsuspend users

2. **Project Access Page** (`/project/{uuid}/access`)
   - Grant users access to specific projects
   - Set permission levels (View Only, Deploy, Full Access)
   - Bulk grant/revoke access

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
| `view_only` | ✅ | ❌ | ❌ | ❌ |
| `deploy` | ✅ | ✅ | ❌ | ❌ |
| `full_access` | ✅ | ✅ | ✅ | ✅ |

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
User Action → Policy Check → Is Granular Enabled?
                                    │
                    ┌───────────────┴───────────────┐
                    │ No                            │ Yes
                    ▼                               ▼
              Allow (v4 default)           Check User Role
                                                    │
                                    ┌───────────────┴───────────────┐
                                    │ Owner/Admin                   │ Member/Viewer
                                    ▼                               ▼
                              Allow (bypass)              Check Project Access
                                                                    │
                                                    ┌───────────────┴───────────────┐
                                                    │ Has Access                    │ No Access
                                                    ▼                               ▼
                                              Check Permission                   Deny
                                                    │
                                            ┌───────┴───────┐
                                            │ Has Perm      │ No Perm
                                            ▼               ▼
                                          Allow           Deny
```

### Database Schema

```
┌──────────────┐       ┌──────────────┐       ┌──────────────┐
│    users     │       │ project_user │       │   projects   │
├──────────────┤       ├──────────────┤       ├──────────────┤
│ id           │──┐    │ id           │    ┌──│ id           │
│ name         │  │    │ user_id      │────┘  │ name         │
│ email        │  └────│ project_id   │───────│ team_id      │
│ is_global_   │       │ permissions  │       └──────────────┘
│   admin      │       └──────────────┘
│ status       │
└──────────────┘

┌──────────────────┐
│ environment_user │
├──────────────────┤
│ id               │
│ user_id          │
│ environment_id   │
│ permissions      │
└──────────────────┘
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

You can safely revert to the original Coolify image at any time. **Reverting is non-destructive** - your projects, users, and data remain intact.

### Quick Revert

```bash
cd /data/coolify/source

# If using docker-compose.override.yml (recommended method)
rm docker-compose.override.yml

# Remove environment variable
sed -i '/COOLIFY_GRANULAR_PERMISSIONS/d' .env

# Restart with original image
docker compose down && docker compose up -d
```

### What Happens After Reverting

| Component | Status |
|-----------|--------|
| Core Coolify | ✅ Works normally |
| Projects & deployments | ✅ Fully preserved |
| Users & teams | ✅ Fully preserved |
| Permission settings | ⚠️ Stored but not enforced |
| Permission UI/API | ❌ Not available |

All team members will have full access to all projects (standard Coolify v4 behavior).

### Re-enabling Later

Your permission data is preserved in the database. Simply reinstall the custom image and your settings will be restored.

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

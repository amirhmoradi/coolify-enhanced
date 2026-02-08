# CLAUDE.md

This file provides guidance to **Claude Code** and other AI assistants when working with this codebase.

> **For detailed AI agent instructions, see [AGENTS.md](AGENTS.md)**

## Project Overview

This is a Laravel package that extends Coolify v4 with granular user role and project-level access management. It does NOT modify Coolify directly but extends it via Laravel's service provider and policy override system.

## Quick Reference

### Package Structure

```
coolify-granular-permissions/
├── src/
│   ├── CoolifyPermissionsServiceProvider.php  # Main service provider
│   ├── Services/
│   │   └── PermissionService.php              # Core permission logic
│   ├── Models/
│   │   ├── ProjectUser.php                    # Project access pivot
│   │   └── EnvironmentUser.php                # Environment override pivot
│   ├── Policies/                              # Laravel policies
│   ├── Http/
│   │   ├── Controllers/Api/                   # API controllers
│   │   └── Middleware/
│   │       └── InjectPermissionsUI.php        # UI injection middleware
│   └── Livewire/
│       └── AccessMatrix.php                   # Access matrix component
├── database/migrations/                        # Database migrations
├── resources/views/livewire/
│   └── access-matrix.blade.php                # Matrix table view
├── routes/                                     # API routes
├── config/                                     # Package configuration
├── docker/                                     # Docker build files
├── install.sh                                  # Automated installer
└── uninstall.sh                                # Automated uninstaller
```

### Key Files

| File | Purpose |
|------|---------|
| `src/Services/PermissionService.php` | All permission checking logic |
| `src/Livewire/AccessMatrix.php` | Unified access management UI |
| `src/Http/Middleware/InjectPermissionsUI.php` | Injects UI into Coolify pages |
| `src/Models/ProjectUser.php` | Permission levels and helpers |
| `config/coolify-permissions.php` | Configuration options |
| `docker/Dockerfile` | Custom Coolify image build |
| `docker/docker-compose.custom.yml` | Compose override template |
| `install.sh` | Setup script (menu + CLI args) |
| `uninstall.sh` | Standalone uninstall script |

### Development Commands

```bash
# No local development - this is deployed via Docker
# Build custom image
docker build --build-arg COOLIFY_VERSION=latest -t coolify-custom:latest -f docker/Dockerfile .

# Setup menu (interactive)
sudo bash install.sh

# Install Coolify on a fresh server
sudo bash install.sh --install-coolify

# Install the permissions addon
sudo bash install.sh --install-addon

# Full setup (Coolify + addon) non-interactive
sudo bash install.sh --install-coolify --install-addon --unattended

# Check installation status
sudo bash install.sh --status

# Uninstall addon (via menu or standalone)
sudo bash install.sh --uninstall
sudo bash uninstall.sh
```

### Permission Levels

- `view_only`: Can view resources
- `deploy`: Can view and deploy
- `full_access`: Can view, deploy, manage, delete

### Role Bypass

Owners and Admins bypass all permission checks. Only Members and Viewers need explicit project access.

### UI Injection

The Access Matrix UI is injected into Coolify's `/team/admin` page via the `InjectPermissionsUI` middleware. No standalone web routes exist — the UI is always part of the existing Coolify page. API routes remain available for programmatic access.

## Important Notes

1. **This is an addon** - It doesn't modify Coolify core files
2. **Feature flag** - Set `COOLIFY_GRANULAR_PERMISSIONS=true` to enable
3. **docker-compose.custom.yml** - Coolify natively supports this file for overrides
4. **v5 compatibility** - Coolify v5 may include similar features; migration guide will be provided
5. **Backward compatible** - When disabled, behaves like standard Coolify

## See Also

- [AGENTS.md](AGENTS.md) - Detailed AI agent instructions
- [docs/architecture.md](docs/architecture.md) - Architecture details
- [docs/api.md](docs/api.md) - API documentation
- [docs/installation.md](docs/installation.md) - Installation guide

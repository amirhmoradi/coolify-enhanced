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
│   ├── Http/Controllers/Api/                  # API controllers
│   └── Livewire/                              # Livewire components
├── database/migrations/                        # Database migrations
├── resources/views/livewire/                   # Blade views
├── routes/                                     # Web and API routes
├── config/                                     # Package configuration
└── docker/                                     # Docker build files
```

### Key Files

| File | Purpose |
|------|---------|
| `src/Services/PermissionService.php` | All permission checking logic |
| `src/Models/ProjectUser.php` | Permission levels and helpers |
| `config/coolify-permissions.php` | Configuration options |
| `docker/Dockerfile` | Custom Coolify image build |

### Development Commands

```bash
# No local development - this is deployed via Docker
# Build custom image
docker build --build-arg COOLIFY_VERSION=latest -t coolify-custom:latest -f docker/Dockerfile .
```

### Permission Levels

- `view_only`: Can view resources
- `deploy`: Can view and deploy
- `full_access`: Can view, deploy, manage, delete

### Role Bypass

Owners and Admins bypass all permission checks. Only Members and Viewers need explicit project access.

## Important Notes

1. **This is an addon** - It doesn't modify Coolify core files
2. **Feature flag** - Set `COOLIFY_GRANULAR_PERMISSIONS=true` to enable
3. **v5 compatibility** - Coolify v5 may include similar features; migration guide will be provided
4. **Backward compatible** - When disabled, behaves like standard Coolify

## See Also

- [AGENTS.md](AGENTS.md) - Detailed AI agent instructions
- [docs/architecture.md](docs/architecture.md) - Architecture details
- [docs/api.md](docs/api.md) - API documentation

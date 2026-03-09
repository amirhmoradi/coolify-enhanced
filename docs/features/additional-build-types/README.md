# Additional Build Types

Adds Railpack, Heroku Buildpacks, and Paketo Buildpacks as build type options alongside Coolify's existing Nixpacks, Dockerfile, Docker Compose, and Static.

## Build Types

| Type | Tool | Builder Image | Description |
|------|------|---------------|-------------|
| Railpack | `railpack` CLI | N/A (BuildKit) | Railway's successor to Nixpacks. Active development, better caching |
| Heroku | `pack` CLI (CNB) | `heroku/builder:24` | Heroku Cloud Native Buildpacks |
| Paketo | `pack` CLI (CNB) | `paketobuildpacks/builder-jammy-base` | Paketo open-source buildpacks |

## Overlay Files

- `src/Overrides/Enums/BuildPackTypes.php` — Adds 3 enum cases (RAILPACK, HEROKU, PAKETO)
- `src/Overrides/Jobs/ApplicationDeploymentJob.php` — Deploy routing + build methods
- `src/Overrides/Views/livewire/project/application/general.blade.php` — Dropdown options in app settings
- `src/Overrides/Views/livewire/project/new/public-git-repository.blade.php` — Dropdown in new resource creation
- `src/Overrides/Views/livewire/project/new/github-private-repository.blade.php` — Dropdown in new resource creation
- `src/Overrides/Views/livewire/project/new/github-private-repository-deploy-key.blade.php` — Dropdown in new resource creation

## How It Works

1. User selects build type in the Build Pack dropdown (General settings or new resource creation)
2. On deploy, `ApplicationDeploymentJob::decide_what_to_do()` routes to the type-specific deploy method
3. Each deploy method follows the same pattern: clone repo → build image → generate compose → push to registry → rolling update
4. Railpack installs on-demand via curl inside the helper container; `pack` CLI is pre-installed in the helper image
5. Environment variables are passed to both tools via `--env KEY=VALUE` flags

## Key Technical Details

- **No database migrations** — `build_pack` is a plain string column, no DB constraints
- **No Livewire component overlays needed** — existing `updatedBuildPack()` handles unknown types gracefully (disables `is_static` for non-nixpacks)
- **API validation auto-updates** — `Rule::enum(BuildPackTypes::class)` in controllers auto-accepts new enum cases
- **PR deploys supported** — `deploy_pull_request()` method branches on build type for image building
- **Build server mode supported** — new deploy methods check `$this->use_build_server` like existing ones

## Related Links

- [Railpack Docs](https://railpack.com)
- [Cloud Native Buildpacks](https://buildpacks.io)
- [Heroku Builder Images](https://github.com/heroku/cnb-builder-images)
- [Paketo Buildpacks](https://paketo.io)
- [coollabsio/coolify#7983](https://github.com/coollabsio/coolify/issues/7983) — Railpack migration request
- [coollabsio/coolify#6682](https://github.com/coollabsio/coolify/issues/6682) — Node.js version limitations in Nixpacks

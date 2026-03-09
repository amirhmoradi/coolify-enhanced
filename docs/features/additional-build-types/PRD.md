# Additional Build Types — PRD

## Problem Statement
Coolify v4 only supports 4 build types (Nixpacks, Dockerfile, Docker Compose, Static). Nixpacks is now in maintenance mode, deprecated by Railway in favor of Railpack. Users have no access to Heroku Buildpacks or Paketo Buildpacks for building applications.

## Goals
1. Add Railpack, Heroku Buildpacks, and Paketo Buildpacks as selectable build types
2. Each type auto-detects language/framework — no user configuration needed
3. Use sensible defaults: Heroku builder v24, Paketo base builder
4. Zero impact on existing build type behavior

## Solution Design
Overlay approach extending the existing `BuildPackTypes` enum, `ApplicationDeploymentJob`, and UI Blade views. Each new build type gets a dedicated `deploy_*_buildpack()` method that follows the established pattern (clone → build → compose → push → rolling update).

### Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Overlay `ApplicationDeploymentJob.php` | Build routing is in a private method — no hook/event system available |
| On-demand Railpack install | Avoids custom helper image; adds ~2-3s per build (acceptable) |
| Reuse `pack` CLI from helper image | Already installed at `/usr/local/bin/pack` in coolify-helper |
| No `could_set_build_commands()` change | Railpack/Heroku/Paketo auto-detect — no user commands needed |
| No Livewire component overlays | Existing `updatedBuildPack()` gracefully handles unknown types |

## Files Modified
See `README.md` in this folder for full overlay list.

## Risks
- `ApplicationDeploymentJob.php` overlay is ~4300 lines — must be kept in sync with upstream Coolify
- Railpack install script URL may change — monitor Railway's GitHub releases
- `pack build` pulls builder images on first use (~500MB Heroku, ~800MB Paketo)
- First deploy with a new build type may be slow due to builder image pull

## Testing Checklist
- [ ] Deploy a Node.js app with Railpack build type
- [ ] Deploy a Python app with Heroku Buildpacks
- [ ] Deploy a Java app with Paketo Buildpacks
- [ ] Switch existing app from Nixpacks to Railpack and redeploy
- [ ] PR deploy works with each new build type
- [ ] API create/update with new build_pack values accepted
- [ ] Build server mode works with new build types
- [ ] Docker Compose and Dockerfile build types still work unchanged
- [ ] New resource creation shows all build type options

# Additional Build Types Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add Railpack, Heroku Buildpacks, and Paketo Buildpacks as build type options in Coolify, extending the existing Nixpacks/Dockerfile/Docker Compose/Static selection.

**Architecture:** Full overlay approach — overlay `BuildPackTypes.php` (add 3 enum cases), `ApplicationDeploymentJob.php` (add routing + deploy methods), and `general.blade.php` (add dropdown options). Railpack builds use `railpack build`, Heroku/Paketo builds use the existing `pack` CLI (already in the helper image) with different builder images. Each new build type gets its own `deploy_*_buildpack()` method following the established pattern.

**Tech Stack:** PHP 8.2+ / Laravel (Coolify v4), Docker, Railpack CLI, Cloud Native Buildpacks `pack` CLI, Heroku builder images (`heroku/builder:24`), Paketo builder images (`paketobuildpacks/builder-jammy-base`)

---

## Background

### Current Build Types (from `app/Enums/BuildPackTypes.php`)
- `nixpacks` — Auto-detect builder (generates Dockerfile, then `docker build`)
- `static` — Pre-built static sites served with Nginx
- `dockerfile` — Custom Dockerfile
- `dockercompose` — Docker Compose files

### New Build Types to Add
- `railpack` — Railway's successor to Nixpacks (active development, better caching)
- `heroku` — Heroku Cloud Native Buildpacks via `pack build --builder heroku/builder:24`
- `paketo` — Paketo Buildpacks via `pack build --builder paketobuildpacks/builder-jammy-base`

### Key Facts
- The **coolify-helper** Docker image already ships `pack` CLI (v0.38.2) — Heroku and Paketo need NO helper image changes
- Only **Railpack** needs the binary added — installed on-demand during deploy via `curl`
- Both `pack build` and `railpack build` produce Docker images directly (no intermediate Dockerfile generation like Nixpacks)
- Environment variables are passed via `--env KEY=VALUE` flags for both `pack` and `railpack`
- Existing `Rule::enum(BuildPackTypes::class)` validation in API controllers auto-updates when enum is overlaid

### Relevant GitHub Issues
- [coollabsio/coolify#7983](https://github.com/coollabsio/coolify/issues/7983) — "Migrate from Nixpacks to Railpack" — Nixpacks deprecated by Railway
- [coollabsio/coolify#7841](https://github.com/coollabsio/coolify/issues/7841) — Rails rebuild issue with Nixpacks
- [coollabsio/coolify#6682](https://github.com/coollabsio/coolify/issues/6682) — Node.js version issues with Nixpacks

### Build Command Reference

| Build Type | Command | Produces |
|------------|---------|----------|
| Railpack | `railpack build --name <image> <dir>` | Docker image via BuildKit |
| Heroku | `pack build <image> --builder heroku/builder:24 --path <dir> --docker-host inherit` | OCI image |
| Paketo | `pack build <image> --builder paketobuildpacks/builder-jammy-base --path <dir> --docker-host inherit` | OCI image |

---

## Overlay Files Summary

| # | File | Coolify Path | Lines | Change Description |
|---|------|--------------|----|-----|
| 1 | `BuildPackTypes.php` | `app/Enums/BuildPackTypes.php` | 12 → 15 | Add 3 enum cases |
| 2 | `ApplicationDeploymentJob.php` | `app/Jobs/ApplicationDeploymentJob.php` | ~4130 | Add routing + 5 new methods (~150 lines) |
| 3 | `general.blade.php` | `resources/views/livewire/project/application/general.blade.php` | 596 → ~600 | Add 3 `<option>` tags to Build Pack dropdown |
| 4 | 3× new-resource Blade views | `resources/views/livewire/project/new/*.blade.php` | small | Add 3 `<option>` tags each |

No new migrations needed. No new columns needed. No model changes needed (build_pack is a plain string column, not constrained by DB).

---

## Task 1: Overlay BuildPackTypes Enum

**Files:**
- Create: `src/Overrides/Enums/BuildPackTypes.php`

**Step 1: Create the overlay enum**

Copy `docs/coolify-source/app/Enums/BuildPackTypes.php` and add the three new cases:

```php
<?php

namespace App\Enums;

enum BuildPackTypes: string
{
    case NIXPACKS = 'nixpacks';
    case STATIC = 'static';
    case DOCKERFILE = 'dockerfile';
    case DOCKERCOMPOSE = 'dockercompose';
    // [CORELIX ENHANCED: Additional build types]
    case RAILPACK = 'railpack';
    case HEROKU = 'heroku';
    case PAKETO = 'paketo';
}
```

**Step 2: Add COPY to Dockerfile**

Add to `docker/Dockerfile` after the existing overlay COPY commands:

```dockerfile
# Override BuildPackTypes enum with additional build types (railpack, heroku, paketo)
COPY --chown=www-data:www-data src/Overrides/Enums/BuildPackTypes.php \
    /var/www/html/app/Enums/BuildPackTypes.php
```

**Step 3: Commit**

```bash
git add src/Overrides/Enums/BuildPackTypes.php docker/Dockerfile
git commit -m "feat: add railpack, heroku, paketo to BuildPackTypes enum"
```

---

## Task 2: Overlay ApplicationDeploymentJob — Routing

**Files:**
- Create: `src/Overrides/Jobs/ApplicationDeploymentJob.php`

This is the largest overlay. Copy the full `docs/coolify-source/app/Jobs/ApplicationDeploymentJob.php` and make the following surgical changes.

**Step 1: Copy the source file**

```bash
cp docs/coolify-source/app/Jobs/ApplicationDeploymentJob.php \
   src/Overrides/Jobs/ApplicationDeploymentJob.php
```

**Step 2: Modify `decide_what_to_do()` — add routing for new build types**

Find the `decide_what_to_do()` method (around line 456). Change:

```php
// BEFORE:
} elseif ($this->application->build_pack === 'static') {
    $this->deploy_static_buildpack();
} else {
    $this->deploy_nixpacks_buildpack();
}
```

To:

```php
// AFTER:
} elseif ($this->application->build_pack === 'static') {
    $this->deploy_static_buildpack();
// [CORELIX ENHANCED: Additional build types]
} elseif ($this->application->build_pack === 'railpack') {
    $this->deploy_railpack_buildpack();
} elseif ($this->application->build_pack === 'heroku') {
    $this->deploy_cnb_buildpack('heroku/builder:24');
} elseif ($this->application->build_pack === 'paketo') {
    $this->deploy_cnb_buildpack('paketobuildpacks/builder-jammy-base');
// [END CORELIX ENHANCED]
} else {
    $this->deploy_nixpacks_buildpack();
}
```

**Step 3: Modify `deploy_pull_request()` — handle new build types in PR deploys**

Find the `deploy_pull_request()` method (around line 1878). It has this section:

```php
if ($this->application->build_pack === 'dockerfile') {
    $this->add_build_env_variables_to_dockerfile();
}
$this->build_image();
```

Replace with:

```php
if ($this->application->build_pack === 'dockerfile') {
    $this->add_build_env_variables_to_dockerfile();
}
// [CORELIX ENHANCED: Additional build types for PR deploys]
if ($this->application->build_pack === 'railpack') {
    $this->build_railpack_image();
} elseif ($this->application->build_pack === 'heroku') {
    $this->build_cnb_image('heroku/builder:24');
} elseif ($this->application->build_pack === 'paketo') {
    $this->build_cnb_image('paketobuildpacks/builder-jammy-base');
} else {
    $this->build_image();
}
// [END CORELIX ENHANCED]
```

**Step 4: Add COPY to Dockerfile**

Add to `docker/Dockerfile`:

```dockerfile
# Override ApplicationDeploymentJob with additional build type support
COPY --chown=www-data:www-data src/Overrides/Jobs/ApplicationDeploymentJob.php \
    /var/www/html/app/Jobs/ApplicationDeploymentJob.php
```

**Step 5: Commit**

```bash
git add src/Overrides/Jobs/ApplicationDeploymentJob.php docker/Dockerfile
git commit -m "feat: add build type routing for railpack, heroku, paketo in deployment job"
```

---

## Task 3: Add Railpack Deploy Method

**Files:**
- Modify: `src/Overrides/Jobs/ApplicationDeploymentJob.php`

**Step 1: Add `deploy_railpack_buildpack()` method**

Append this method at the end of the class (before the closing `}`), following the same pattern as `deploy_nixpacks_buildpack()`:

```php
// [CORELIX ENHANCED: Railpack build type]
private function deploy_railpack_buildpack()
{
    if ($this->use_build_server) {
        $this->server = $this->build_server;
    }
    $this->application_deployment_queue->addLogEntry("Starting Railpack deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
    $this->prepare_builder_image();
    $this->check_git_if_build_needed();
    $this->generate_image_names();
    if (! $this->force_rebuild) {
        $this->check_image_locally_or_remotely();
        if ($this->should_skip_build()) {
            return;
        }
    }
    $this->clone_repository();
    $this->cleanup_git();
    $this->generate_compose_file();
    $this->save_buildtime_environment_variables();
    $this->generate_build_env_variables();
    $this->build_railpack_image();
    $this->save_runtime_environment_variables();
    $this->push_to_docker_registry();
    $this->rolling_update();
}
```

**Step 2: Add `build_railpack_image()` private method**

```php
private function build_railpack_image()
{
    $this->application_deployment_queue->addLogEntry('----------------------------------------');
    $this->application_deployment_queue->addLogEntry('Building Docker image with Railpack.');
    $this->application_deployment_queue->addLogEntry('To check the current progress, click on Show Debug Logs.');

    $this->application_deployment_queue->addLogEntry('Installing Railpack...', hidden: true);
    $this->execute_remote_command(
        [executeInDocker($this->deployment_uuid, 'curl -fsSL https://raw.githubusercontent.com/railwayapp/railpack/main/install.sh | bash'), 'hidden' => true],
    );

    $env_flags = '';
    if ($this->pull_request_id === 0) {
        foreach ($this->application->nixpacks_environment_variables as $env) {
            if (! is_null($env->real_value) && $env->real_value !== '') {
                $escaped = escapeshellarg($env->real_value);
                $env_flags .= " --env {$env->key}={$escaped}";
            }
        }
    } else {
        foreach ($this->application->nixpacks_environment_variables_preview as $env) {
            if (! is_null($env->real_value) && $env->real_value !== '') {
                $escaped = escapeshellarg($env->real_value);
                $env_flags .= " --env {$env->key}={$escaped}";
            }
        }
    }

    $no_cache = $this->force_rebuild ? ' --no-cache' : '';
    $build_command = "railpack build --name {$this->production_image_name}{$env_flags}{$no_cache} {$this->workdir}";

    $base64_build_command = base64_encode($build_command);
    $this->execute_remote_command(
        [
            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
            'hidden' => true,
        ],
        [
            executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
            'hidden' => true,
        ]
    );
    $this->application_deployment_queue->addLogEntry('Railpack build completed.');
}
// [END CORELIX ENHANCED: Railpack build type]
```

**Step 3: Commit**

```bash
git add src/Overrides/Jobs/ApplicationDeploymentJob.php
git commit -m "feat: add railpack deploy and build methods"
```

---

## Task 4: Add CNB (Heroku/Paketo) Deploy Method

**Files:**
- Modify: `src/Overrides/Jobs/ApplicationDeploymentJob.php`

**Step 1: Add `deploy_cnb_buildpack()` method**

```php
// [CORELIX ENHANCED: Cloud Native Buildpacks (Heroku/Paketo)]
private function deploy_cnb_buildpack(string $builder)
{
    if ($this->use_build_server) {
        $this->server = $this->build_server;
    }
    $builderLabel = str_contains($builder, 'heroku') ? 'Heroku' : 'Paketo';
    $this->application_deployment_queue->addLogEntry("Starting {$builderLabel} Buildpack deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
    $this->prepare_builder_image();
    $this->check_git_if_build_needed();
    $this->generate_image_names();
    if (! $this->force_rebuild) {
        $this->check_image_locally_or_remotely();
        if ($this->should_skip_build()) {
            return;
        }
    }
    $this->clone_repository();
    $this->cleanup_git();
    $this->generate_compose_file();
    $this->save_buildtime_environment_variables();
    $this->generate_build_env_variables();
    $this->build_cnb_image($builder);
    $this->save_runtime_environment_variables();
    $this->push_to_docker_registry();
    $this->rolling_update();
}
```

**Step 2: Add `build_cnb_image()` private method**

```php
private function build_cnb_image(string $builder)
{
    $builderLabel = str_contains($builder, 'heroku') ? 'Heroku' : 'Paketo';
    $this->application_deployment_queue->addLogEntry('----------------------------------------');
    $this->application_deployment_queue->addLogEntry("Building Docker image with {$builderLabel} Buildpacks (builder: {$builder}).");
    $this->application_deployment_queue->addLogEntry('To check the current progress, click on Show Debug Logs.');

    $this->application_deployment_queue->addLogEntry("Pulling builder image: {$builder}", hidden: true);
    $this->execute_remote_command(
        [executeInDocker($this->deployment_uuid, "docker pull {$builder}"), 'hidden' => true],
    );

    $env_flags = '';
    if ($this->pull_request_id === 0) {
        foreach ($this->application->nixpacks_environment_variables as $env) {
            if (! is_null($env->real_value) && $env->real_value !== '') {
                $escaped = escapeshellarg($env->real_value);
                $env_flags .= " --env {$env->key}={$escaped}";
            }
        }
    } else {
        foreach ($this->application->nixpacks_environment_variables_preview as $env) {
            if (! is_null($env->real_value) && $env->real_value !== '') {
                $escaped = escapeshellarg($env->real_value);
                $env_flags .= " --env {$env->key}={$escaped}";
            }
        }
    }

    $no_cache = $this->force_rebuild ? ' --clear-cache' : '';
    $build_command = "pack build {$this->production_image_name} --builder {$builder} --path {$this->workdir} --docker-host inherit{$env_flags}{$no_cache}";

    $base64_build_command = base64_encode($build_command);
    $this->execute_remote_command(
        [
            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
            'hidden' => true,
        ],
        [
            executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
            'hidden' => true,
        ]
    );
    $this->application_deployment_queue->addLogEntry("{$builderLabel} Buildpack build completed.");
}
// [END CORELIX ENHANCED: Cloud Native Buildpacks]
```

**Step 3: Commit**

```bash
git add src/Overrides/Jobs/ApplicationDeploymentJob.php
git commit -m "feat: add CNB deploy and build methods for heroku and paketo"
```

---

## Task 5: Overlay General Blade View — Build Pack Dropdown

**Files:**
- Create: `src/Overrides/Views/livewire/project/application/general.blade.php`

**Step 1: Copy the source Blade view**

```bash
mkdir -p src/Overrides/Views/livewire/project/application/
cp docs/coolify-source/resources/views/livewire/project/application/general.blade.php \
   src/Overrides/Views/livewire/project/application/general.blade.php
```

**Step 2: Add new options to the Build Pack dropdown**

Find the dropdown (around line 33-38):

```html
<x-forms.select x-bind:disabled="shouldDisable()" wire:model.live="buildPack" label="Build Pack"
    required>
    <option value="nixpacks">Nixpacks</option>
    <option value="static">Static</option>
    <option value="dockerfile">Dockerfile</option>
    <option value="dockercompose">Docker Compose</option>
</x-forms.select>
```

Replace with:

```html
<x-forms.select x-bind:disabled="shouldDisable()" wire:model.live="buildPack" label="Build Pack"
    required>
    <option value="nixpacks">Nixpacks</option>
    {{-- Corelix Enhanced: Additional build types --}}
    <option value="railpack">Railpack</option>
    <option value="heroku">Heroku Buildpacks</option>
    <option value="paketo">Paketo Buildpacks</option>
    {{-- End Corelix Platform --}}
    <option value="static">Static</option>
    <option value="dockerfile">Dockerfile</option>
    <option value="dockercompose">Docker Compose</option>
</x-forms.select>
```

**Step 3: Add COPY to Dockerfile**

Add to `docker/Dockerfile`:

```dockerfile
# Override application general view with additional build type options
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/application/general.blade.php \
    /var/www/html/resources/views/livewire/project/application/general.blade.php
```

**Step 4: Commit**

```bash
git add src/Overrides/Views/livewire/project/application/general.blade.php docker/Dockerfile
git commit -m "feat: add railpack, heroku, paketo to build pack dropdown"
```

---

## Task 6: Overlay New Resource Creation Views

**Files:**
- Create: `src/Overrides/Views/livewire/project/new/public-git-repository.blade.php`
- Create: `src/Overrides/Views/livewire/project/new/github-private-repository.blade.php`
- Create: `src/Overrides/Views/livewire/project/new/github-private-repository-deploy-key.blade.php`

These three Blade views each have a Build Pack dropdown that needs the new options. The change is identical to Task 5 — add the 3 new `<option>` tags to each dropdown.

**Step 1: Copy source files**

```bash
cp docs/coolify-source/resources/views/livewire/project/new/public-git-repository.blade.php \
   src/Overrides/Views/livewire/project/new/public-git-repository.blade.php

cp docs/coolify-source/resources/views/livewire/project/new/github-private-repository.blade.php \
   src/Overrides/Views/livewire/project/new/github-private-repository.blade.php

cp docs/coolify-source/resources/views/livewire/project/new/github-private-repository-deploy-key.blade.php \
   src/Overrides/Views/livewire/project/new/github-private-repository-deploy-key.blade.php
```

**Step 2: In each file, find the Build Pack `<x-forms.select>` and add the 3 new options**

The pattern is the same in all three files. After `<option value="nixpacks">Nixpacks</option>`, add:

```html
{{-- Corelix Enhanced: Additional build types --}}
<option value="railpack">Railpack</option>
<option value="heroku">Heroku Buildpacks</option>
<option value="paketo">Paketo Buildpacks</option>
{{-- End Corelix Platform --}}
```

**Step 3: Add COPY lines to Dockerfile**

Add to `docker/Dockerfile`:

```dockerfile
# Override new resource creation views with additional build type options
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/public-git-repository.blade.php \
    /var/www/html/resources/views/livewire/project/new/public-git-repository.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/github-private-repository.blade.php \
    /var/www/html/resources/views/livewire/project/new/github-private-repository.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/github-private-repository-deploy-key.blade.php \
    /var/www/html/resources/views/livewire/project/new/github-private-repository-deploy-key.blade.php
```

Note: `select.blade.php` already has an overlay in this project (for custom template source labels). The new resource creation flow uses these 3 other views for the build pack selection.

**Step 4: Commit**

```bash
git add src/Overrides/Views/livewire/project/new/public-git-repository.blade.php \
        src/Overrides/Views/livewire/project/new/github-private-repository.blade.php \
        src/Overrides/Views/livewire/project/new/github-private-repository-deploy-key.blade.php \
        docker/Dockerfile
git commit -m "feat: add new build types to new resource creation dropdowns"
```

---

## Task 7: Update Documentation

**Files:**
- Create: `docs/features/additional-build-types/PRD.md`
- Create: `docs/features/additional-build-types/plan.md` (symlink or copy of this file)
- Create: `docs/features/additional-build-types/README.md`
- Modify: `AGENTS.md` — add build types section
- Modify: `CLAUDE.md` — add build types section
- Modify: `README.md` — mention new build types in feature list

**Step 1: Create feature documentation folder and files**

`docs/features/additional-build-types/README.md`:

```markdown
# Additional Build Types

Adds Railpack, Heroku Buildpacks, and Paketo Buildpacks as build type options alongside Coolify's existing Nixpacks, Dockerfile, Docker Compose, and Static.

## Build Types

| Type | Tool | Builder Image | Description |
|------|------|---------------|-------------|
| Railpack | `railpack` CLI | N/A (BuildKit) | Railway's successor to Nixpacks. Active development, better caching |
| Heroku | `pack` CLI (CNB) | `heroku/builder:24` | Heroku Cloud Native Buildpacks |
| Paketo | `pack` CLI (CNB) | `paketobuildpacks/builder-jammy-base` | Paketo open-source buildpacks |

## Overlay Files

- `src/Overrides/Enums/BuildPackTypes.php` — Adds enum cases
- `src/Overrides/Jobs/ApplicationDeploymentJob.php` — Deploy routing + build methods
- `src/Overrides/Views/livewire/project/application/general.blade.php` — Dropdown options
- `src/Overrides/Views/livewire/project/new/*.blade.php` — New resource dropdowns

## How It Works

1. User selects build type in the Build Pack dropdown (General settings or new resource creation)
2. On deploy, `ApplicationDeploymentJob::decide_what_to_do()` routes to the type-specific deploy method
3. Each deploy method follows the same pattern: clone → build image → compose → push → rolling update
4. Railpack installs on-demand via curl; `pack` CLI is pre-installed in the helper image

## Related Issues

- [coollabsio/coolify#7983](https://github.com/coollabsio/coolify/issues/7983) — Railpack migration request
- [coollabsio/coolify#6682](https://github.com/coollabsio/coolify/issues/6682) — Node.js version limitations in Nixpacks
```

`docs/features/additional-build-types/PRD.md`:

```markdown
# Additional Build Types — PRD

## Problem
Coolify only supports 4 build types (Nixpacks, Dockerfile, Docker Compose, Static). Nixpacks is in maintenance mode and deprecated by Railway. Users need access to Railpack (Nixpacks successor), Heroku Buildpacks, and Paketo Buildpacks.

## Goals
1. Add Railpack, Heroku Buildpacks, and Paketo Buildpacks as selectable build types
2. Each type auto-detects language/framework — no user configuration needed
3. Sensible defaults: Heroku builder v24, Paketo base builder
4. No changes to existing build type behavior

## Solution
Overlay approach: extend BuildPackTypes enum, add deploy methods to ApplicationDeploymentJob, add dropdown options to UI views. Reuse existing deployment pipeline (clone, compose, push, rolling update).

## Files Modified
See README.md in this folder for full overlay list.

## Risks
- `ApplicationDeploymentJob.php` overlay is 4130+ lines — must be kept in sync with upstream Coolify
- Railpack installed on-demand adds ~2-3s to first build (cached after)
- `pack build` pulls builder images on first use (~500MB for Heroku, ~800MB for Paketo)

## Testing Checklist
- [ ] Deploy a Node.js app with Railpack build type
- [ ] Deploy a Python app with Heroku Buildpacks
- [ ] Deploy a Java app with Paketo Buildpacks
- [ ] Switch existing app from Nixpacks to Railpack and redeploy
- [ ] PR deploy works with each new build type
- [ ] API create/update with new build_pack values accepted
- [ ] Build server mode works with new build types
```

**Step 2: Update AGENTS.md and CLAUDE.md**

Add a new section documenting the build types feature, overlay files, and pitfalls. Key additions:
- Add to Package Context: "**Additional Build Types** — Railpack, Heroku Buildpacks, Paketo Buildpacks"
- Add overlay files to the overlay table
- Add pitfalls about `ApplicationDeploymentJob.php` overlay maintenance and `pack`/`railpack` CLI behavior

**Step 3: Commit**

```bash
git add docs/features/additional-build-types/ AGENTS.md CLAUDE.md README.md
git commit -m "docs: add additional build types feature documentation"
```

---

## Key Pitfalls and Notes

1. **`ApplicationDeploymentJob.php` is the largest overlay** (~4130 lines). Mark all enhanced additions with `[CORELIX ENHANCED: ...]` comments. Use diff carefully when syncing with upstream.

2. **Railpack is installed on-demand** — `curl -fsSL https://raw.githubusercontent.com/railwayapp/railpack/main/install.sh | bash` runs inside the helper container at deploy time. This adds ~2-3 seconds to the first build. The binary persists for the duration of the deployment but is discarded when the helper container is removed. Future optimization: build a custom helper image with railpack pre-installed.

3. **`pack` CLI is already in the helper image** — No changes needed for Heroku/Paketo builds. The `pack` binary is at `/usr/local/bin/pack` in the coolify-helper image.

4. **Builder images are large** — `heroku/builder:24` is ~500MB, `paketobuildpacks/builder-jammy-base` is ~800MB. First build will be slow due to image pull. Subsequent builds use cached images.

5. **`pack build --docker-host inherit`** — Required so `pack` uses the Docker socket already mounted in the helper container, rather than trying to use its own Docker connection.

6. **PR deploys need the same routing** — `deploy_pull_request()` calls `build_image()` which only handles nixpacks/dockerfile. Must add branches for railpack/heroku/paketo.

7. **`could_set_build_commands()` returns false for new types** — This is correct. Railpack auto-detects; Heroku/Paketo auto-detect. Users don't configure install/build/start commands.

8. **`updatedBuildPack()` in General.php handles new types gracefully** — The existing code disables `is_static` for any non-nixpacks build type. No overlay needed for General.php Livewire component.

9. **New resource creation Livewire components handle new types gracefully** — The `updatedBuildPack()` in `PublicGitRepository.php`, `GithubPrivateRepository.php`, and `GithubPrivateRepositoryDeployKey.php` only have explicit branches for `nixpacks` and `static`. New build types keep the default port (3000) and is_static settings. No component overlays needed — only Blade view overlays for dropdown options.

10. **API validation auto-updates** — `Rule::enum(BuildPackTypes::class)` is used in `ApplicationsController.php` and `api.php`. Overlaying the enum file automatically makes the API accept the new values. API docs strings have hardcoded enum arrays — these are cosmetic and can be updated later.

11. **Environment variables for pack/railpack** — Both `pack build --env KEY=VALUE` and `railpack build --env KEY=VALUE` support environment variable injection. We reuse the existing `nixpacks_environment_variables` relationship which returns build-time env vars for the application.

12. **`--docker-host inherit` for pack** — Without this flag, `pack` tries to create its own Docker connection. Inside the helper container, the Docker socket is already mounted. `inherit` tells `pack` to use the host's Docker daemon.

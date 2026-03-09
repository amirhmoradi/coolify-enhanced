# Feature Flag Gating & Free Edition Build Pipeline — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add compile-time feature flags to gate pro features, with an automated GitHub Actions pipeline that strips pro code and pushes a free community edition to the public `corelix-platform` repository.

**Architecture:** Central feature registry (`config/features.php`) + `Feature` helper class + Blade directive + route middleware + Python stripping script + GitHub Actions workflow. Two-layer stripping: file-level exclusion (`.free-edition-ignore`) + inline markers (`PREMIUM:*:START/END`). Two tiers only: Free and Pro.

**Tech Stack:** PHP 8.2+ (Laravel), Blade, Python 3, GitHub Actions, Docker, Composer

**Key Design Decisions:**
- **Two tiers only:** Free and Pro (no Enterprise tier)
- **Overlay strategy:** Pro-only overlays are skipped entirely in free builds (Coolify original stays)
- **MCP strategy:** Keep conditional tool registration; exclude pro tool modules from tsconfig in free builds
- **Public repo name:** `corelix-platform` (existing repo becomes free edition target)
- **Whitelabeling:** Pro-only feature; all whitelabel files/markers stripped from free build

---

## Default Feature Split

| Feature | Tier |
|---|---|
| Enhanced Database Classification | **free** |
| Enhanced UI Themes | **free** |
| Encrypted S3 Backups | **free** |
| Resource Backups | **free** |
| Custom Template Sources | **free** |
| Additional Build Types | **free** |
| MCP Enhanced Tools | **free** |
| Granular Permissions | **free** |
| Network Management | **free** |
| Proxy Isolation | **free** |
| Swarm Overlay Encryption | **free** |
| Cluster Management | **pro** |
| Whitelabeling | **pro** |
| Docker Registry Management | **pro** (planned) |
| Audit Trail | **pro** (planned) |

---

## Phase 1: Feature Registry and Helpers (Tasks 1-7)

### Task 1: Create Feature Registry Config

**Files:** Create `config/features.php`

Central declaration of all 15 features with key, name, description, tier (`free`/`pro`), default (`true` for all in private repo), category, and optional `parent` key for sub-features. Include `edition` (env `CORELIX_EDITION`, default `pro`) and `upgrade_url` (env `CORELIX_UPGRADE_URL`). Each feature overridable via `FEATURE_<KEY>` env var.

### Task 2: Create Feature Helper Class

**Files:** Create `src/Support/Feature.php`

Static helper class: `enabled(key)`, `disabled(key)`, `tier(key)`, `meta(key)`, `all()`, `allEnabled()`, `edition()`, `upgradeUrl()`, `registry()`, `frontendFlags()`, `flush()`. Resolves flags from config registry with env var overrides. Sub-feature dependency: if parent disabled, child auto-disabled. Cached per-request via static property.

### Task 3: Register Feature Config in Service Provider

**Files:** Modify `src/CorelixPlatformServiceProvider.php` `register()` method. Add `$this->mergeConfigFrom(__DIR__.'/../config/features.php', 'features');`

### Task 4: Register Blade Directive

**Files:** Modify `src/CorelixPlatformServiceProvider.php`. Add `registerBladeDirectives()` method using `Blade::if('feature', ...)`. Register BEFORE the `corelix-platform.enabled` early-return so the directive exists even when CORELIX_PLATFORM is false.

### Task 5: Create Feature Route Middleware

**Files:** Create `src/Http/Middleware/FeatureMiddleware.php`. Returns 402 JSON for API requests, redirect-back for web requests when feature disabled. Register via `$this->app['router']->aliasMiddleware('feature', FeatureMiddleware::class)`.

### Task 6: Inject Frontend Feature Flags

**Files:** Modify `src/Overrides/Views/layouts/base.blade.php`. Add `<script>window.__FEATURES__ = @json(Feature::frontendFlags());</script>` after CSRF meta tag.

### Task 7: Create Features API Endpoint

**Files:** Modify `routes/api.php`. Add `GET /features` returning edition, features map, and upgrade_url.

---

## Phase 2: Upsell Component and Service Provider Gating (Tasks 8-12)

### Task 8: Create Upsell Card Blade Component

**Files:** Create `resources/views/components/upsell-card.blade.php`. Takes `feature` prop, renders feature name, description, "Pro" badge, and upgrade link. Uses Coolify dark theme colors.

### Task 9: Gate Service Provider with Feature Checks and PREMIUM Markers

**Files:** Modify `src/CorelixPlatformServiceProvider.php`. Add `PREMIUM:CLUSTER_MANAGEMENT:START/END` around cluster registration, cluster Livewire components, cluster policies, cluster schedulers. Add `PREMIUM:WHITELABELING:START/END` around WhitelabelSettings Livewire component.

### Task 10: Gate API Routes

**Files:** Modify `routes/api.php`. Wrap cluster routes in `feature:CLUSTER_MANAGEMENT` middleware and `PREMIUM:CLUSTER_MANAGEMENT:START/END` markers. All free-tier routes (permissions, backups, templates, networks) get NO markers.

### Task 11: Gate Web Routes

**Files:** Modify `routes/web.php`. Wrap cluster routes in `PREMIUM:CLUSTER_MANAGEMENT:START/END`. Wrap branding route in `PREMIUM:WHITELABELING:START/END`.

### Task 12: Gate Overlay Views

**Files:** Modify settings navbar (Branding tab marker), base layout (whitelabel meta tags marker, `window.__FEATURES__` injection), Dockerfile (PAAS_* ARGs/ENVs marker), docker-publish.yml (PAAS_* build-args marker), composer.json (whitelabel autoload marker), config/coolify-enhanced.php (whitelabel config block marker).

---

## Phase 3: Code Stripping Pipeline (Tasks 13-17)

### Task 13: Create `.free-edition-ignore`

**Files:** Create `.free-edition-ignore`. Lists all cluster management files (30+ files), all whitelabeling files (6 files), BUSINESS_STRATEGY.md, the strip script itself, and MCP `clusters.ts`.

### Task 14: Create Python Stripping Script

**Files:** Create `scripts/strip-premium-code.py`. Scans for `PREMIUM:*:START/END` blocks in PHP/Blade/YAML/Dockerfile. Checks `FEATURE_<KEY>` env vars. Removes disabled blocks. Validates paired markers. Handles `composer.json` specially via JSON parse/rewrite. Reports summary. Exit 1 on validation failure.

### Task 15: Create Validation Script

**Files:** Create `scripts/validate-free-build.sh`. Grep checks: no PREMIUM markers, no excluded class references, no whitelabel files. Composer dump-autoload check. Report pass/fail.

### Task 16: Create Local Build Script

**Files:** Create `scripts/build-free.sh`. Copies codebase to temp, sources `.env.free`, runs exclusions, runs strip, runs validate. Optional `--docker` flag.

### Task 17: Create `.env.free` Template

**Files:** Create `.env.free`. Sets `CORELIX_EDITION=free`, all free features `true`, all pro features `false`.

---

## Phase 4: GitHub Actions Pipeline (Tasks 18-19)

### Task 18: Create Free Edition Build Workflow

**Files:** Create `.github/workflows/build-free-edition.yml`. Triggers: push to main, workflow_dispatch, weekly cron. Steps: checkout, Python setup, read vars, file exclusions, inline stripping, validation, free tests, Docker build test, clone public repo, commit, push.

### Task 19: Update Docker Publish Workflow

**Files:** Modify `.github/workflows/docker-publish.yml`. Add `CORELIX_EDITION=pro` label. PREMIUM markers already added in Task 12.

---

## Phase 5: Integration and Testing (Tasks 20-22)

### Task 20: Add Inline PREMIUM Markers to Existing Codebase

All shared files needing markers (see Task 12 for full list). Key files: service provider, routes, overlay views, Dockerfile, docker-publish.yml, composer.json, config, CLAUDE.md, AGENTS.md.

### Task 21: Create Free Edition Tests

**Files:** Create `tests/FreeEdition/FeatureGatingTest.php`. Tests tagged `@group free`: pro API returns 402, free features work, upsell renders, registry correct, Feature::enabled returns correct values.

### Task 22: Update Documentation

**Files:** Modify README.md (add Pro section), CLAUDE.md (feature flag architecture), AGENTS.md (development guidelines).

---

## Phase 6: Proof of Concept (Task 23)

### Task 23: Gate First Pro Feature End-to-End

Gate `CLUSTER_MANAGEMENT` fully: API 402, web redirect, stripping removes all cluster code, validation passes, Docker builds, tests pass. Then gate `WHITELABELING`: files absent, composer.json clean, Dockerfile clean, navbar clean, base layout clean.

---

## Whitelabeling-Specific Integration

15 touchpoints requiring markers or exclusion:

| # | File | Action |
|---|---|---|
| 1 | `src/Services/WhitelabelConfig.php` | File-excluded |
| 2 | `src/Helpers/whitelabel.php` | File-excluded |
| 3 | `src/Livewire/WhitelabelSettings.php` | File-excluded |
| 4 | `resources/views/livewire/whitelabel-settings.blade.php` | File-excluded |
| 5 | `docker/whitelabel.sh` | File-excluded |
| 6 | `docker/brands/` | Directory-excluded |
| 7 | `config/coolify-enhanced.php` | Inline marker: `whitelabel` block |
| 8 | `src/CorelixPlatformServiceProvider.php` | Inline marker: WhitelabelSettings registration |
| 9 | `routes/web.php` | Inline marker: branding route |
| 10 | `src/Overrides/Views/components/settings/navbar.blade.php` | Inline marker: Branding tab |
| 11 | `src/Overrides/Views/layouts/base.blade.php` | Inline marker: whitelabel meta tags |
| 12 | `resources/views/livewire/appearance-settings.blade.php` | `function_exists('brandName')` guard |
| 13 | `docker/Dockerfile` | Inline marker: PAAS_* ARGs/ENVs + whitelabel.sh |
| 14 | `.github/workflows/docker-publish.yml` | Inline marker: PAAS_* build-args |
| 15 | `composer.json` | Python script removes whitelabel autoload entry |

---

## Dependency Graph

```
Task 1 (config) + Task 2 (Feature class)
    |
    v
Task 3 (SP register)
    |
    +---> Task 4 (Blade directive)
    +---> Task 5 (Middleware)
    +---> Task 6 (Frontend flags)
    +---> Task 7 (API endpoint)
    +---> Task 8 (Upsell card)
    |
    v
Task 9-12 (Gating: SP, API routes, web routes, views)
    |
    +---> Task 13 (.free-edition-ignore)
    +---> Task 14 (Strip script)
    +---> Task 15 (Validate script)
    +---> Task 16 (Local build)
    +---> Task 17 (.env.free)
    |
    v
Task 18 (GH Actions) --> Task 19 (Update docker-publish)
    |
    v
Task 20 (Add inline markers) + Task 21 (Tests) + Task 22 (Docs)
    |
    v
Task 23 (Proof of concept)
```

## Key Risks

| Risk | Mitigation |
|---|---|
| Stripping breaks shared files | Validation script + free-build tests |
| Whitelabel `function_exists()` guards needed | Already guarded in appearance-settings |
| `composer.json` JSON stripping fragile | Python handles JSON parse/rewrite (no regex) |
| Cluster overlay (swarm.blade.php) leaks | In `.free-edition-ignore` + validated |
| MCP clusters.ts reference in mcp-server.ts | Conditional import via `enhanced` boolean |

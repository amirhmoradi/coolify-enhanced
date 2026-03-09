# Feature Flag Gating & Free Edition Build Pipeline

## Overview

Compile-time feature flags that gate pro features behind a two-tier system (Free / Pro), with an automated GitHub Actions pipeline that strips pro code and publishes a clean free community edition to the public `corelix-platform` repository.

## Two-Tier Model

| Tier | Description |
|---|---|
| **Free** | Ships in both editions. The community gets a powerful, complete product. |
| **Pro** | Stripped from the free build. Only available in the paid edition. |

### Feature Split (Default)

**Free Tier** (11 features):
- Enhanced Database Classification
- Enhanced UI Themes
- Encrypted S3 Backups
- Resource Backups
- Custom Template Sources
- Additional Build Types
- MCP Enhanced Tools
- Granular Permissions
- Network Management
- Proxy Isolation
- Swarm Overlay Encryption

**Pro Tier** (4 features):
- Cluster Management
- Whitelabeling
- Multiple Docker Registry Management (planned)
- Audit Trail with Export/Retention (planned)

Tier assignments are configurable via `config/features.php` and `FEATURE_<KEY>` environment variables.

## Components

### Feature Registry (`config/features.php`)

Central declaration of all features with metadata: key, name, description, tier, default, category, optional parent.

### Feature Helper (`src/Support/Feature.php`)

```php
use CorelixIo\Platform\Support\Feature;

Feature::enabled('CLUSTER_MANAGEMENT');    // bool
Feature::disabled('CLUSTER_MANAGEMENT');   // bool
Feature::tier('CLUSTER_MANAGEMENT');       // 'pro'
Feature::meta('CLUSTER_MANAGEMENT');       // full metadata array
Feature::all();                             // ['KEY' => bool, ...]
Feature::edition();                         // 'free' | 'pro'
Feature::upgradeUrl();                      // configurable URL
```

### Blade Directive

```blade
@feature('CLUSTER_MANAGEMENT')
    {{-- Pro content --}}
@else
    @include('corelix-platform::components.upsell-card', ['feature' => 'CLUSTER_MANAGEMENT'])
@endfeature
```

### Route Middleware

```php
Route::middleware(['feature:CLUSTER_MANAGEMENT'])->group(function () {
    // Pro routes - return HTTP 402 when disabled
});
```

### API

```
GET /api/v1/features -> { edition, features: { KEY: bool }, upgrade_url }
```

Pro endpoints return HTTP 402:
```json
{
    "error": "premium_feature",
    "feature": "CLUSTER_MANAGEMENT",
    "name": "Cluster Management",
    "tier": "pro",
    "upgrade_url": "https://...",
    "message": "Cluster Management requires Pro edition."
}
```

### Frontend

```javascript
if (window.__FEATURES__.CLUSTER_MANAGEMENT) { /* pro path */ }
```

### Upsell Card

Blade component shown when a pro feature is disabled: feature name, description, "Pro" badge, upgrade link.

### Code Stripping (Two Layers)

**Layer 1 - File Exclusion:** `.free-edition-ignore` lists entire files/directories to exclude, including private docs and workflows that should never ship in the public mirror.

When a stripped file is also referenced from a shared Dockerfile or workflow, that shared block must be wrapped in the matching `PREMIUM:KEY:START/END` markers. Example: the whitelabel `docker/whitelabel.sh` + `docker/brands/` block in `docker/Dockerfile`.

When a pro feature introduces database migrations, those migration files must be added to `.free-edition-ignore` too. Otherwise premium schema can leak into the public build even if the PHP code is stripped.

**Layer 2 - Inline Markers:** For shared files:

```php
// --- PREMIUM:CLUSTER_MANAGEMENT:START ---
// ... pro code removed in free build ...
// --- PREMIUM:CLUSTER_MANAGEMENT:END ---
```

```blade
{{-- PREMIUM:WHITELABELING:START --}}
... pro markup removed in free build ...
{{-- PREMIUM:WHITELABELING:END --}}
```

## Build Pipeline

### Private Build (Pro)

No change. All features enabled by default. Standard `docker-publish.yml` workflow. Whitelabeling via `PAAS_*` build args.

### Free Build (Canonical Private Repo)

```
.github/workflows/build-free-edition.yml

Triggers: push to main, workflow_dispatch, weekly cron

1. Read feature flags from repository variables
2. File-level exclusion (.free-edition-ignore)
3. Inline stripping (scripts/strip-premium-code.py)
4. Validation (scripts/validate-free-build.sh)
5. Docker build test
6. Publish a fresh-history snapshot to the public `corelix-platform` repository using explicit username + token HTTPS auth (`PUBLIC_REPO_GIT_USERNAME` + `PUBLIC_REPO_PAT`)
```

### Local Free Build (Canonical Private Repo)

These commands are for private maintainers working in the canonical source repository. The public mirror is a CI-published free snapshot and does not include the private build helpers.

```bash
./scripts/build-free.sh           # Strip + validate
./scripts/build-free.sh --docker  # Also build Docker image
```

## Environment Variables (Canonical Private Repo)

| Variable | Default | Purpose |
|---|---|---|
| `CORELIX_EDITION` | `pro` | Build edition identifier |
| `CORELIX_UPGRADE_URL` | `https://corelix.io/pricing` | Upsell link target |
| `FEATURE_GRANULAR_PERMISSIONS` | `true` | Gate: permissions (free) |
| `FEATURE_ENCRYPTED_S3_BACKUPS` | `true` | Gate: backup encryption (free) |
| `FEATURE_RESOURCE_BACKUPS` | `true` | Gate: resource backups (free) |
| `FEATURE_CUSTOM_TEMPLATE_SOURCES` | `true` | Gate: custom templates (free) |
| `FEATURE_ENHANCED_DATABASE_CLASSIFICATION` | `true` | Gate: DB classification (free) |
| `FEATURE_NETWORK_MANAGEMENT` | `true` | Gate: network management (free) |
| `FEATURE_PROXY_ISOLATION` | `true` | Gate: proxy isolation (free) |
| `FEATURE_SWARM_OVERLAY_ENCRYPTION` | `true` | Gate: Swarm encryption (free) |
| `FEATURE_MCP_ENHANCED_TOOLS` | `true` | Gate: MCP enhanced tools (free) |
| `FEATURE_ENHANCED_UI_THEME` | `true` | Gate: UI themes (free) |
| `FEATURE_ADDITIONAL_BUILD_TYPES` | `true` | Gate: build types (free) |
| `FEATURE_CLUSTER_MANAGEMENT` | `true` | Gate: cluster management (pro) |
| `FEATURE_WHITELABELING` | `true` | Gate: whitelabeling (pro) |
| `FEATURE_DOCKER_REGISTRY_MANAGEMENT` | `true` | Gate: registry management (pro, planned) |
| `FEATURE_AUDIT_TRAIL` | `true` | Gate: audit trail (pro, planned) |

## File List (Canonical Private Repo)

| File | Purpose |
|---|---|
| `config/features.php` | Feature registry |
| `src/Support/Feature.php` | Feature helper class |
| `src/Http/Middleware/FeatureMiddleware.php` | Route middleware |
| `resources/views/components/upsell-card.blade.php` | Upsell placeholder |
| `.free-edition-ignore` | File-level exclusion list |
| `.env.free` | Free edition env template |
| `scripts/strip-premium-code.py` | Inline code stripping |
| `scripts/build-free.sh` | Local free build |
| `scripts/validate-free-build.sh` | Post-strip validation |
| `.github/workflows/build-free-edition.yml` | CI pipeline |
| `tests/FreeEdition/FeatureGatingTest.php` | Gating tests |

## Adding a New Pro Feature

This section is primarily for private maintainers working in the canonical source repository.

1. Add entry to `config/features.php` registry with `'tier' => 'pro'`
2. Add `FEATURE_<KEY>` variable to GitHub Actions repository vars (set to `false`)
3. Gate backend code with `Feature::enabled('KEY')` + inline `PREMIUM:KEY:START/END` markers
4. Gate frontend with `@feature('KEY')` directive
5. Add pro-only files to `.free-edition-ignore`
6. Add pro-only migrations to `.free-edition-ignore` if the feature adds schema changes
7. Add upsell card in `@else` branch of `@feature` directive
8. Write tests for both enabled and disabled states
9. Update `.env.free` with new flag
10. Update public docs and private maintainer docs as appropriate

## Related Documentation

- [PRD](PRD.md) - Product Requirements Document
- [Implementation Plan](plan.md) - Detailed task-by-task plan
- [Whitelabeling PRD](../whitelabeling/PRD.md) - Pro-only whitelabeling feature
- Whitelabeling business strategy is maintained as a private/internal document and is intentionally not mirrored to the public free repository.
- Public contributors should use the repository `README.md` and open a PR in the public mirror. Private maintainer docs are intentionally not mirrored.

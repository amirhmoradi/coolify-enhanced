# Feature Flag Gating & Free Edition Build Pipeline — Product Requirements Document

## Problem Statement

Coolify-enhanced is a single private codebase containing both free (community) and pro features. Currently, all features ship in every build — there is no mechanism to:

1. **Gate pro features** — All enhanced features are available in every Docker image
2. **Produce a free edition** — No automated way to strip pro code and push a clean, community-friendly build to a public repository
3. **Prevent pro code leaks** — No compile-time enforcement ensuring pro code never appears in free builds
4. **Upsell from free to paid** — No UI/API mechanism to indicate pro features exist and guide users toward an upgrade

### Current Feature Gating Landscape

The codebase already uses environment-variable-based feature toggles, but they are **runtime toggles** (code is present but disabled):

| Current Variable | Current Behavior |
|---|---|
| `CORELIX_PLATFORM=true` | Master switch; all code still ships |
| `CORELIX_NETWORK_MANAGEMENT=true` | Sub-feature toggle; code present in image |
| `CORELIX_CLUSTER_MANAGEMENT=true` | Sub-feature toggle; code present in image |
| `PAAS_WHITELABEL=true` | Whitelabel toggle; code present in image |

This approach is insufficient for a free/pro split because:
- All PHP classes, Blade views, JS modules, and migrations ship in every build
- A motivated user can set `CORELIX_PLATFORM=true` and access everything
- No dead-code elimination or stripping occurs

## Goals

1. **Compile-time feature flags** — Pro code must NOT ship in the free edition at all (not just disabled)
2. **Single source of truth** — One private repo where all development happens; public repo (`corelix-platform`) is auto-generated
3. **Central feature registry** — `config/features.php` declares all features with metadata (tier, category, description, default)
4. **Zero pro code in free builds** — No dead code, no hidden endpoints, no pro class files
5. **Free edition is a complete product** — All existing Coolify features + all designated "free tier" features remain fully functional
6. **Automated build pipeline** — GitHub Actions workflow strips pro code and pushes to the public `corelix-platform` repository
7. **Developer experience** — Easy to add new pro features, simulate free builds locally, and verify correctness
8. **Upsell placeholders** — UI cards and API responses indicating pro features exist (never silently hidden)
9. **No runtime license checks** — Free edition has zero phone-home, zero license validation

## Non-Goals

- Runtime feature flag service (LaunchDarkly, Unleash, etc.) — flags are compile-time only
- SaaS billing integration — out of scope; upgrade links point to external URLs
- Dynamic feature unlocking — no mechanism to enable pro features at runtime in free builds
- Per-user feature access — features are build-wide (all-or-nothing per edition)

## Solution Design

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│  Private Repository (single source of truth)                │
│                                                             │
│  ┌──────────────┐  ┌──────────────────────────────────┐    │
│  │ Free Features │  │ Pro Features                     │    │
│  │ (always ship) │  │ (stripped in free build)          │    │
│  └──────────────┘  └──────────────────────────────────┘    │
│                                                             │
│  config/features.php  ← Feature Registry (source of truth) │
│  .free-edition-ignore ← File-level exclusion list           │
│  PREMIUM:*:START/END  ← Inline code markers                 │
│                                                             │
│  GitHub Actions:                                             │
│  ┌──────────────────────────────────────┐                   │
│  │ 1. Read feature registry             │                   │
│  │ 2. Exclude files (.free-edition-ignore)│                  │
│  │ 3. Strip PREMIUM markers (inline)    │                   │
│  │ 4. Validate (grep for leaks)         │                   │
│  │ 5. Test (lint, compile, @free tests) │                   │
│  │ 6. Docker build test                 │                   │
│  │ 7. Push to public repo               │                   │
│  └──────────────────────────────────────┘                   │
│                                                             │
│      ┌─────────────┐         ┌─────────────┐               │
│      │ Pro Docker   │         │ Free Docker │               │
│      │ Image (GHCR) │         │ Image (GHCR)│               │
│      └─────────────┘         └─────────────┘               │
│                                    │                        │
│                              ┌─────▼──────────┐            │
│                              │ Public Repo     │            │
│                              │ corelix-platform│            │
│                              └────────────────┘             │
└─────────────────────────────────────────────────────────────┘
```

### Two-Tier Model: Free and Pro

Only two editions exist. The tier assignment is the **default** and can be changed via config:

| Tier | Description |
|---|---|
| **free** | Ships in both editions; the community gets these features |
| **pro** | Stripped from the free build; only available in the paid edition |

### Feature Registry (`config/features.php`)

Central declaration of every feature with metadata:

```php
return [
    'edition' => env('CORELIX_EDITION', 'pro'),
    'upgrade_url' => env('CORELIX_UPGRADE_URL', 'https://corelix.io/pricing'),
    'registry' => [
        [
            'key' => 'GRANULAR_PERMISSIONS',
            'name' => 'Granular Permissions',
            'description' => 'Project-level and environment-level access control',
            'tier' => 'free',
            'default' => true,
            'category' => 'permissions',
        ],
        // ... more features
    ],
];
```

**Evaluation rules:**
- **Private build (pro)**: all flags default `true` (all features enabled)
- **Free build**: `pro` flags forced to `false` via GitHub Actions variables
- **Free-tier features**: always `true` in both editions

### Flag Evaluation Helpers

**PHP:**
```php
use App\Support\Feature;

if (Feature::enabled('CLUSTER_MANAGEMENT')) { /* pro path */ }
Feature::tier('CLUSTER_MANAGEMENT');  // → 'pro'
Feature::all();                        // → ['KEY' => bool, ...]
```

**Blade:**
```blade
@feature('CLUSTER_MANAGEMENT')
    {{-- pro UI --}}
@else
    @include('corelix-platform::components.upsell-card', ['feature' => 'CLUSTER_MANAGEMENT'])
@endfeature
```

**JavaScript:**
```javascript
if (window.__FEATURES__.CLUSTER_MANAGEMENT) { /* pro path */ }
```

### Gating Patterns

#### Backend (Laravel/PHP)

| Mechanism | Usage |
|---|---|
| `Feature::enabled()` facade | Service classes, jobs, controllers |
| Route middleware `feature:FLAG` | Gate entire route groups |
| Service provider conditional | Skip Livewire registration, routes, schedulers |
| Migration guard | Skip pro DB tables in free builds |
| Job early-exit | Jobs check feature flag in `handle()` |

#### Frontend (Blade/JS)

| Mechanism | Usage |
|---|---|
| `@feature` Blade directive | Gate pro UI sections |
| `window.__FEATURES__` global | JS-side feature checks |
| Inline markers `PREMIUM:*:START/END` | Strip entire blocks at build time |

#### API

| Mechanism | Usage |
|---|---|
| Route middleware `feature:FLAG` | Gate pro API endpoints |
| Response field omission | Hide pro fields when feature disabled |
| HTTP 402 response | `{"error":"premium_feature","feature":"...","upgrade_url":"..."}` |
| `/api/v1/features` endpoint | List enabled features |

### Upsell Placeholders

When a pro feature is disabled (free edition), the UI shows a subtle upsell card:
- Feature name and one-line description (from registry)
- "Pro" badge
- Upgrade link (configurable URL)
- Never silently hides — always indicates something exists

### Code Stripping (Two Layers)

**Layer 1 — File-Level Exclusion** via `.free-edition-ignore`:
```
src/Services/WhitelabelConfig.php
src/Helpers/whitelabel.php
src/Livewire/WhitelabelSettings.php
docker/whitelabel.sh
docker/brands/
# ... etc
```

**Layer 2 — Inline Code Stripping** using structured markers:
```php
// --- PREMIUM:WHITELABELING:START ---
$this->registerWhitelabelComponent();
// --- PREMIUM:WHITELABELING:END ---
```

```blade
{{-- PREMIUM:WHITELABELING:START --}}
<a href="/settings/branding">Branding</a>
{{-- PREMIUM:WHITELABELING:END --}}
```

The stripping script (Python) scans for `PREMIUM:*:START/END` blocks, checks the flag, removes if `false`, validates paired markers (fail build if mismatched).

### Build Validation

1. **Grep for leaks** — any remaining `PREMIUM:` markers, pro namespace imports = build failure
2. **Lint & compile check** — `php artisan route:list`, `composer dump-autoload --strict`
3. **Free edition test suite** — tests tagged `@group free` pass
4. **Docker build test** — image must compile and container must start

## Premium Feature Classification

### Default Tier Assignments

| Flag | Category | Tier | Description | Key Files |
|---|---|---|---|---|
| `GRANULAR_PERMISSIONS` | permissions | **free** | Project/env access control with role-based overrides | `PermissionService`, `AccessMatrix`, `Policies/*`, `Scopes/*` |
| `ENCRYPTED_S3_BACKUPS` | backups | **free** | Per-storage NaCl SecretBox encryption for DB backups | `RcloneService`, `HasS3Encryption`, `DatabaseBackupJob` overlay |
| `RESOURCE_BACKUPS` | backups | **free** | Volume, config, full, instance backups for any resource | `ResourceBackupJob`, `ResourceBackupManager`, `ScheduledResourceBackup` |
| `CUSTOM_TEMPLATE_SOURCES` | deployment | **free** | GitHub repos as external template sources | `TemplateSourceService`, `CustomTemplateSource`, `SyncTemplateSourceJob` |
| `ENHANCED_DATABASE_CLASSIFICATION` | deployment | **free** | 50+ DB images, `coolify.database` label, multi-port proxy | `constants.php`, `shared.php`, `StartDatabaseProxy`, `ServiceDatabase` |
| `NETWORK_MANAGEMENT` | networking | **free** | Per-env Docker network isolation, shared networks | `NetworkService`, `NetworkReconcileJob`, `ManagedNetwork` |
| `PROXY_ISOLATION` | networking | **free** | Dedicated proxy network for FQDN-only routing | Sub-feature of Network Management; `proxy.php`, `docker.php` |
| `SWARM_OVERLAY_ENCRYPTION` | networking | **free** | IPsec encryption for Swarm overlay networks | Sub-feature of Network Management; `NetworkService` |
| `MCP_ENHANCED_TOOLS` | deployment | **free** | Enhanced MCP tools (permissions, backups, templates, networks) | `mcp-server/src/tools/{permissions,resource-backups,templates,networks}.ts` |
| `ENHANCED_UI_THEME` | ui | **free** | Multi-theme selector (Linear, TailAdmin) in Settings > Appearance | `AppearanceSettings`, `EnhancedUiSettings`, theme CSS files |
| `ADDITIONAL_BUILD_TYPES` | deployment | **free** | Railpack, Heroku Buildpacks, Paketo Buildpacks | `BuildPackTypes` enum, `ApplicationDeploymentJob` overlay |
| `CLUSTER_MANAGEMENT` | monitoring | **pro** | Swarm dashboard, node management, visualizer, secrets/configs | `SwarmClusterDriver`, `ClusterDashboard`, `ClusterController` |
| `WHITELABELING` | branding | **pro** | Build-time brand replacement, runtime URL overrides, Settings > Branding | `WhitelabelConfig`, `whitelabel.sh`, `WhitelabelSettings`, `docker/brands/` |
| `DOCKER_REGISTRY_MANAGEMENT` | deployment | **pro** | Multiple Docker registry management with authentication | TBD — new feature |
| `AUDIT_TRAIL` | compliance | **pro** | Audit trail with export/retention for all user actions | TBD — new feature |

### Tier Rationale

- **free** — All current corelix-platform features that have been developed and are fully functional. The community gets a powerful, complete product. This drives adoption and GitHub stars.
- **pro** — Advanced operational features: cluster management (Swarm dashboard), whitelabeling (MSP brand replacement), Docker registry management, and audit trail (compliance). These target MSPs and enterprises who derive direct revenue from the tooling.

## GitHub Actions Variables

The following repository variables control which features are included in the free edition build:

```yaml
# Free tier (always true)
vars.FEATURE_GRANULAR_PERMISSIONS: "true"
vars.FEATURE_ENCRYPTED_S3_BACKUPS: "true"
vars.FEATURE_RESOURCE_BACKUPS: "true"
vars.FEATURE_CUSTOM_TEMPLATE_SOURCES: "true"
vars.FEATURE_ENHANCED_DATABASE_CLASSIFICATION: "true"
vars.FEATURE_NETWORK_MANAGEMENT: "true"
vars.FEATURE_PROXY_ISOLATION: "true"
vars.FEATURE_SWARM_OVERLAY_ENCRYPTION: "true"
vars.FEATURE_MCP_ENHANCED_TOOLS: "true"
vars.FEATURE_ENHANCED_UI_THEME: "true"
vars.FEATURE_ADDITIONAL_BUILD_TYPES: "true"

# Pro tier (false in free build)
vars.FEATURE_CLUSTER_MANAGEMENT: "false"
vars.FEATURE_WHITELABELING: "false"
vars.FEATURE_DOCKER_REGISTRY_MANAGEMENT: "false"
vars.FEATURE_AUDIT_TRAIL: "false"

# Whitelabel build-args (only relevant for pro builds)
vars.PAAS_BRAND_NAME: "Coolify"
vars.PAAS_BRAND_DESCRIPTION: ""
vars.PAAS_DOCS_URL: ""
vars.PAAS_WEBSITE_URL: ""
vars.PAAS_TWITTER_HANDLE: ""
vars.PAAS_WHITELABEL: "false"
vars.PAAS_HIDE_SPONSORSHIP: "false"
vars.PAAS_HIDE_VERSION_LINK: "false"
vars.PAAS_HIDE_ANALYTICS: "true"
```

## User Experience

### Private Build (Pro)

No change from current behavior. All features enabled. `CORELIX_PLATFORM=true` master switch still controls runtime activation. Sub-feature toggles (`CORELIX_NETWORK_MANAGEMENT`, `CORELIX_CLUSTER_MANAGEMENT`) continue to work as independent on/off switches. Whitelabeling via `PAAS_*` build args continues to work.

### Free Build (Community Edition)

1. User installs free edition from public `corelix-platform` repo
2. All Coolify features work normally (no regression)
3. All free-tier enhanced features work normally (permissions, backups, templates, networks, themes, build types, MCP, etc.)
4. Where pro features would appear, user sees upsell cards:
   - "Cluster Management — Available in Pro" with upgrade link
   - "Whitelabeling — Available in Pro" with upgrade link
5. API endpoints for pro features return HTTP 402 with structured JSON
6. No pro PHP classes, views, routes, or migrations exist in the codebase
7. `GET /api/v1/features` returns only enabled features

### Developer Experience

- **Locally**: all features enabled (private build defaults)
- `make build-free` / `./scripts/build-free.sh` to simulate free build
- `.env.free` with all pro flags set to `false`
- Adding a new pro feature requires updating: feature registry, `.free-edition-ignore`, inline markers, upsell card, free-edition tests, docs

## Files Modified / Created

### New Files

| File | Purpose |
|---|---|
| `config/features.php` | Feature registry with metadata |
| `src/Support/Feature.php` | Feature helper class (facade-style) |
| `src/Http/Middleware/FeatureMiddleware.php` | Route middleware for feature gating |
| `resources/views/components/upsell-card.blade.php` | Upsell placeholder component |
| `scripts/strip-premium-code.py` | CI stripping script |
| `scripts/build-free.sh` | Local free build simulation |
| `scripts/validate-free-build.sh` | Post-strip validation |
| `.free-edition-ignore` | File-level exclusion list |
| `.env.free` | Free edition env template |
| `.github/workflows/build-free-edition.yml` | Free edition build pipeline |
| `tests/FreeEdition/FeatureGatingTest.php` | Verify gating correctness |

### Modified Files

| File | Change |
|---|---|
| `src/CorelixPlatformServiceProvider.php` | Wrap pro registrations in `Feature::enabled()` checks + inline markers |
| `config/coolify-enhanced.php` | Reference feature registry for sub-feature defaults |
| `routes/api.php` | Add `feature:` middleware to pro route groups |
| `routes/web.php` | Add `feature:` middleware to pro web routes |
| `src/Overrides/Views/components/settings/navbar.blade.php` | Add `@feature` directives around pro tabs (Branding) |
| `src/Overrides/Views/components/server/sidebar.blade.php` | Add `@feature` directives where needed |
| `src/Overrides/Views/layouts/base.blade.php` | Inject `window.__FEATURES__` global + PREMIUM markers for whitelabel section |
| `docker/Dockerfile` | PREMIUM markers around whitelabel build steps |
| `.github/workflows/docker-publish.yml` | Add edition label metadata |
| `composer.json` | PREMIUM markers around whitelabel autoload entry |

## Overlay File Strategy

**For pro-only overlay files (e.g., whitelabel-related views):** The free build **skips the overlay entirely** — Coolify's original file stays in place. This is option (a): simpler, no partial overlays.

**Implication:** Free-tier overlay files that contain BOTH free and pro code (e.g., `settings/navbar.blade.php` with a Branding tab) use inline `PREMIUM:*:START/END` markers to strip only the pro sections. The overlay itself remains because it also contains free-tier UI (Restore, Templates, Networks, Appearance tabs).

## MCP Server Strategy

**Approach:** Keep the existing conditional tool registration via `CORELIX_PLATFORM` env var. In the free npm package, enhanced tools for pro features (e.g., `clusters.ts`) are excluded from `tsconfig.json` `include` paths during the free build. Core enhanced tools (permissions, backups, templates, networks) remain since those features are free tier.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Stripping script removes too much code | Free build broken | Validation step runs lint + compile + tests before push |
| Mismatched PREMIUM markers | Build failure or code leak | CI validates paired markers; pre-commit hook checks |
| Pro imports in free code | PHP fatal errors | Grep validation step catches any pro namespace references |
| Overlay files partially pro | Incomplete functionality | Inline markers for mixed overlays; file exclusion for pro-only overlays |
| Feature registry out of sync | Features not properly gated | CI step cross-references registry keys against markers |
| Developer forgets to add markers | Pro code leaks to free | Automated scan for pro directory/namespace references in non-excluded files |
| Public repo force-push | Rewrites public commit history | Intentional for fresh-history mirror model; preserve public release context in curated `CHANGELOG.md` and public docs |
| Whitelabeling code references in free build | Build errors from missing `WhitelabelConfig` | All whitelabel references wrapped in PREMIUM markers or guarded by `function_exists()` |

## Testing Checklist

- [ ] Feature registry loads correctly with all features
- [ ] `Feature::enabled()` returns `true` for all features in private build
- [ ] `Feature::enabled()` returns `false` for pro features in free build
- [ ] `@feature` Blade directive renders pro content when enabled
- [ ] `@feature` Blade directive renders upsell card when disabled
- [ ] `feature:FLAG` route middleware returns 402 for disabled features
- [ ] `/api/v1/features` endpoint returns correct feature list
- [ ] Stripping script removes all `PREMIUM:*:START/END` blocks for disabled features
- [ ] Stripping script preserves all code for enabled features (free tier)
- [ ] `.free-edition-ignore` correctly excludes all pro-only files
- [ ] Validation script catches any remaining pro references
- [ ] Free edition Docker image builds successfully
- [ ] Free edition Docker image starts without errors
- [ ] Free edition passes all `@group free` tests
- [ ] Upsell card component renders correctly with feature name and tier
- [ ] No pro PHP classes exist in free build
- [ ] No pro routes exist in free build
- [ ] No pro migrations exist in free build
- [ ] `window.__FEATURES__` object is correctly populated in base layout
- [ ] GitHub Actions workflow completes end-to-end
- [ ] Public `corelix-platform` repo receives clean commit with no pro code
- [ ] Whitelabel helpers (whitelabel.php) fully absent in free build
- [ ] `composer.json` autoload entries for whitelabel stripped in free build
- [ ] Whitelabel Dockerfile steps (PAAS_* ARGs, whitelabel.sh) stripped in free build

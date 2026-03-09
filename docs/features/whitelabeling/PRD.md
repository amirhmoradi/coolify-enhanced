# Whitelabeling — Product Requirements Document

## Problem Statement

Users deploying Coolify for their organizations or as a managed service need to replace the "Coolify" product identity with their own branding. The current system has "Coolify" hardcoded across ~100+ Blade views, meta tags, logos, and documentation links. Manual replacement is fragile and breaks on every Coolify upgrade.

## Goals

1. **Build-time brand replacement** — A single Docker build produces a fully branded image with zero "Coolify" references in the UI
2. **Config-driven** — All brand settings via `PAAS_*` environment variables, settable as GitHub Actions repository variables
3. **Zero new overlay files for branding** — Build-time `sed` across ALL Blade views eliminates the need for overlay file maintenance
4. **Runtime URL overrides** — Documentation, support, and social URLs editable via Settings > Branding without rebuilding
5. **Asset replacement** — Custom logos, favicons, and OG images via a `docker/brands/` convention directory

## Solution Design

### Two-layer Architecture

**Build-time (docker/whitelabel.sh):**
- `sed`-based string replacement across ALL `resources/views/*.blade.php` files
- Runs AFTER overlay files are copied, so both Coolify core and our overlays get branded
- Handles: brand name, description, docs URL, website URL, Twitter handle
- Handles: logo/favicon file copy from `docker/brands/`
- Handles: conditional block removal (analytics, sponsorship)

**Runtime (WhitelabelConfig service):**
- DB → ENV → config default resolution chain
- Powers meta tags in `base.blade.php` overlay via PHP helpers
- Settings > Branding page for URL/color overrides without rebuild

### Config Variables

All use `PAAS_*` prefix (Platform-as-a-Service, brand-agnostic):

| Variable | Default | Build-time | Runtime |
|----------|---------|------------|---------|
| `PAAS_BRAND_NAME` | `Coolify` | Yes (sed) | Read-only |
| `PAAS_BRAND_DESCRIPTION` | `An open-source...` | Yes (sed) | Read-only |
| `PAAS_DOCS_URL` | `https://coolify.io/docs` | Yes (sed) | Editable |
| `PAAS_WEBSITE_URL` | `https://coolify.io` | Yes (sed) | Editable |
| `PAAS_TWITTER_HANDLE` | `@coolifyio` | Yes (sed) | Editable |
| `PAAS_OG_IMAGE` | null | No | Editable |
| `PAAS_BRAND_COLOR` | null | No | Editable |
| `PAAS_HIDE_SPONSORSHIP` | `false` | Yes (removal) | Read-only |
| `PAAS_HIDE_VERSION_LINK` | `false` | Yes (removal) | Read-only |
| `PAAS_HIDE_ANALYTICS` | `true` | Yes (removal) | Read-only |

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Build-time sed instead of overlay files | Zero maintenance burden per Coolify release; covers ALL 316 views |
| `PAAS_*` env var prefix | Brand-agnostic; doesn't reference "Coolify" in the whitelabel config itself |
| Dockerfile ARG→ENV pattern | Build-args become runtime ENVs; Laravel config reads ENVs; single source of truth |
| Separate build-time vs runtime concerns | Brand name is identity (immutable per image); URLs are configuration (mutable) |
| Reuse `enhanced_ui_settings` table | No new migration; generic key-value store already proven |
| `docker/brands/` convention directory | Simple file-based asset replacement; works offline; no URL dependencies |

## Files Modified

- `config/coolify-enhanced.php` — `whitelabel` section
- `docker/Dockerfile` — PAAS_* ARGs, ENV, whitelabel.sh invocation
- `.github/workflows/docker-publish.yml` — Pass `vars.PAAS_*` as build-args
- `src/Overrides/Views/layouts/base.blade.php` — Runtime meta tag helpers
- `src/Overrides/Views/components/settings/navbar.blade.php` — Branding tab + dynamic subtitle
- `resources/views/livewire/appearance-settings.blade.php` — Dynamic brand name in dropdown
- `routes/web.php` — Branding settings route
- `src/CorelixPlatformServiceProvider.php` — Register Livewire component
- `composer.json` — Autoload helpers file

## Files Created

- `src/Services/WhitelabelConfig.php` — Resolution chain service
- `src/Helpers/whitelabel.php` — Global helper functions
- `docker/whitelabel.sh` — Build-time sed processor
- `docker/brands/README.md` — Asset convention docs
- `src/Livewire/WhitelabelSettings.php` — Settings > Branding component
- `resources/views/livewire/whitelabel-settings.blade.php` — Branding settings view

## Coverage

~90% (Tier 3). All user-facing UI text, meta tags, page titles, documentation links, and brand assets. Remaining 10% is in Coolify PHP source strings (error messages, notifications, job logs) which would require Tier 4 overlays.

## Risks

| Risk | Mitigation |
|------|------------|
| sed patterns may miss new Coolify views | Patterns are broad enough to catch common patterns; periodic review on upgrades |
| Special characters in brand name break sed | `sed_escape()` function handles `/`, `&`, and other special chars |
| Build-time brand name can't change at runtime | Intentional: brand identity is per-image; URLs are runtime-configurable |

## Testing Checklist

- [ ] Build with `PAAS_BRAND_NAME=TestBrand` — verify no "Coolify" in UI
- [ ] Build with default args — verify unchanged behavior
- [ ] Place logo.svg in `docker/brands/` — verify logo replacement
- [ ] Settings > Branding page: change Docs URL — verify meta tags update
- [ ] Settings > Branding: Reset to Defaults — verify config fallback
- [ ] Verify `config('app.name') == 'Coolify Cloud'` check is NOT broken by sed

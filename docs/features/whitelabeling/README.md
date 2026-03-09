# Whitelabeling Feature

Replace Coolify's product identity with your own brand — name, logo, colors, URLs — via Docker build arguments and runtime settings.

## Quick Start

1. Fork this repository
2. Set GitHub Actions repository variables:
   - `PAAS_BRAND_NAME` = `MyPaaS`
   - `PAAS_DOCS_URL` = `https://docs.mypaas.io`
   - `PAAS_WHITELABEL` = `true`
3. (Optional) Add brand assets to `docker/brands/`:
   - `logo.svg` — Main product logo
   - `favicon.svg` — Browser tab icon
   - `og-image.png` — Social media preview
4. Push to `main` — the workflow builds a fully branded image

## Architecture

```
GitHub Actions vars (PAAS_*)
    ↓ (build-args)
Dockerfile ARGs → ENV (baked into image)
    ↓
whitelabel.sh (sed across ALL 316 Blade views)
    ↓
Branded Docker Image
    ↓ (runtime)
WhitelabelConfig service (DB → ENV → config resolution)
    ↓
Settings > Branding page (URL overrides without rebuild)
```

## Components

| Component | Purpose |
|-----------|---------|
| `docker/whitelabel.sh` | Build-time sed processor for brand replacement |
| `docker/brands/` | Convention directory for logo/favicon/OG assets |
| `src/Services/WhitelabelConfig.php` | Runtime config resolution (DB → ENV → default) |
| `src/Helpers/whitelabel.php` | Global helper functions for Blade views |
| `src/Livewire/WhitelabelSettings.php` | Settings > Branding admin page |
| `config/coolify-enhanced.php` (`whitelabel`) | All PAAS_* env var mappings |

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PAAS_WHITELABEL` | `false` | Enable whitelabeling |
| `PAAS_BRAND_NAME` | `Coolify` | Product name (replaced everywhere at build time) |
| `PAAS_BRAND_DESCRIPTION` | `An open-source...` | Product tagline |
| `PAAS_DOCS_URL` | `https://coolify.io/docs` | Documentation base URL |
| `PAAS_WEBSITE_URL` | `https://coolify.io` | Main website URL |
| `PAAS_TWITTER_HANDLE` | `@coolifyio` | Twitter/X handle |
| `PAAS_OG_IMAGE` | (none) | OpenGraph preview image URL |
| `PAAS_BRAND_COLOR` | (none) | Primary brand color (hex) |
| `PAAS_HIDE_SPONSORSHIP` | `false` | Remove sponsorship/donation UI |
| `PAAS_HIDE_VERSION_LINK` | `false` | Remove GitHub release links |
| `PAAS_HIDE_ANALYTICS` | `true` | Remove Coolify Cloud analytics |

## Build-time vs Runtime

**Build-time** (immutable per image): Brand name, description, logo, favicon, sponsorship/analytics removal. Set via `PAAS_*` Docker build arguments.

**Runtime** (changeable via Settings): Documentation URL, support URL, website URL, Twitter handle, OG image URL, brand color. Stored in DB with ENV fallback.

## Related Docs

- [PRD](PRD.md) — Full product requirements
- [Theming Impact Analysis](../multi-theme/THEMING_IMPACT_ANALYSIS.md) — Section 11 covers white-labeling feasibility

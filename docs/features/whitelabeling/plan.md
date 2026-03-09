# Whitelabeling — Implementation Plan

## Phase 1: Core Infrastructure

### Config Section
Add `whitelabel` key to `config/coolify-enhanced.php` with all `PAAS_*` env var mappings.

### WhitelabelConfig Service
`src/Services/WhitelabelConfig.php` — Static methods with DB → ENV → config resolution chain. Reuses `EnhancedUiSettings` (key-value store) for DB overrides.

### Global Helpers
`src/Helpers/whitelabel.php` — `whitelabel()`, `brandName()`, `brandDocsUrl()`, `brandLogoUrl()`, `brandFaviconUrl()`, `whitelabelEnabled()`. Autoloaded via `composer.json` `files` array.

## Phase 2: Build-time Processor

### whitelabel.sh Script
`docker/whitelabel.sh` — Bash script invoked from Dockerfile after all overlay copies.

**Replacement patterns:**
1. `$title ?? 'Coolify'` → `$title ?? 'BRAND_NAME'`
2. `content="Coolify"` → `content="BRAND_NAME"` (meta tags)
3. `| Coolify` → `| BRAND_NAME` (title separators, careful not to match `Coolify Cloud`)
4. `settings for Coolify` → `settings for BRAND_NAME`
5. `Coolify instance/PostgreSQL/installation` → `BRAND_NAME instance/...`
6. `Coolify Official` → `BRAND_NAME Official` (template filters)
7. `the Coolify team` / `by Coolify` / `from Coolify`
8. `Default (Coolify)` → `Default (BRAND_NAME)` (theme dropdown)
9. URL replacements: `coolify.io/docs`, `coolify.io`, `@coolifyio`
10. Brand description replacement

**Asset handling:**
- Check `docker/brands/` for `logo.{svg,png}`, `favicon.{svg,ico,png}`, `og-image.{png,jpg}`
- Copy to `public/` replacing Coolify defaults

**Conditional removal:**
- Analytics block (`config('app.name') == 'Coolify Cloud'` block)

### Dockerfile Changes
- Add PAAS_* ARGs with defaults
- Convert ARGs to ENVs via `ENV FOO=${FOO}`
- `COPY --chmod=755 docker/whitelabel.sh` + `RUN` after all overlay copies

### GitHub Actions
- Pass `vars.PAAS_*` as build-args in `docker-publish.yml`

## Phase 3: Runtime UI

### Settings > Branding Page
`WhitelabelSettings` Livewire component:
- Read-only section: build-time config (brand name, toggles, logo preview)
- Editable section: URLs, colors (saved to `enhanced_ui_settings` table)
- Reset to Defaults button

### Route + Navigation
- `GET /settings/branding` → `WhitelabelSettings::class`
- Branding tab in settings navbar (admin/owner only)

## Phase 4: Overlay View Updates

### base.blade.php
- Meta tags: use `WhitelabelConfig` helpers for runtime resolution
- Favicon: use `brandFaviconUrl()` when whitelabel enabled
- These are RUNTIME-resolvable, separate from build-time sed

### appearance-settings.blade.php
- Replace hardcoded "Coolify" with config reads

### settings/navbar.blade.php
- Dynamic subtitle from config
- Add Branding tab link

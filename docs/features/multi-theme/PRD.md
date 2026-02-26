# Multi-Theme System — Product Requirements Document

## Problem Statement

The existing enhanced theme system only supports a single "enhanced" theme via a boolean toggle. Users want theme variety and the ability to choose from multiple visual styles without requiring code changes or configuration file edits.

## Goals

1. **Multi-theme infrastructure** — Theme registry in config, theme selector in Settings > Appearance, CSS scoping via `data-ce-theme` attribute
2. **TailAdmin-inspired theme** — First additional theme beyond Enhanced: clean enterprise dashboard with brand blues, warm grays, polished form controls
3. **Non-invasive CSS-only approach** — No DOM changes; visual overrides only to avoid breaking Coolify's Livewire components
4. **Self-hosted assets** — No external CDN dependencies; fonts (e.g., Outfit WOFF2) bundled in Docker image for air-gapped deployments

## Solution Design

- **Theme registry** — `config/coolify-enhanced.php` defines available themes with `label`, `description`, `css` path, and optional `font_label`
- **Theme selector** — Dropdown in Settings > Appearance; options: Default (stock Coolify), Enhanced, TailAdmin, and any future bundled themes
- **CSS scoping** — Each theme CSS is scoped under `html[data-ce-theme="{slug}"]` and `html.dark[data-ce-theme="{slug}"]`
- **Self-hosted fonts** — WOFF2 font files bundled under `resources/assets/themes/fonts/`; CSS uses relative `url()` paths
- **Persistence** — Key `active_theme` in `enhanced_ui_settings` table; config fallback when DB value is missing or invalid

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| CSS-only visual overrides (no DOM/layout changes) | Avoids breaking Coolify's Livewire components, Alpine.js bindings, and conditional rendering |
| Self-hosted fonts (Outfit WOFF2 bundled) | Supports air-gapped deployments; same pattern as Coolify's Inter font |
| Instance-wide theme selection (admin-controlled) | Simplicity; avoids per-user preference complexity and session/cookie handling |
| Bundled themes only (no user-uploaded CSS) | Security and maintainability; no arbitrary CSS execution |
| Key-value DB storage (`enhanced_ui_settings`) | Reuses existing table; cached theme slug with 60s TTL |
| Theme validation against config registry | `getActiveTheme()` returns null for invalid slugs; prevents injection of non-existent themes |

## Files Modified

- `database/migrations/2024_01_01_000018_convert_theme_to_multi_select.php` — Migrate `enhanced_theme_enabled` to `active_theme` slug
- `src/Models/EnhancedUiSettings.php` — `getActiveTheme()`, `setActiveTheme()`, `getAvailableThemes()`, validation against registry
- `config/coolify-enhanced.php` — `ui_theme.themes` registry with `enhanced` and `tailadmin` entries
- `src/Livewire/AppearanceSettings.php` — Theme dropdown; `saveTheme()` instead of boolean toggle
- `resources/views/livewire/appearance-settings.blade.php` — Select element with theme options, description display
- `src/Overrides/Views/layouts/base.blade.php` — Load theme CSS from config; set `data-ce-theme` to active slug
- `src/CoolifyEnhancedServiceProvider.php` — Publish `themes/` folder; no `enhanced_theme_enabled()` (replaced by `getActiveTheme()`)
- `docker/Dockerfile` — Copy `resources/assets/themes/` to `public/vendor/coolify-enhanced/themes/`

## Files Created

- `resources/assets/themes/enhanced.css` — Linear-inspired theme (moved from `theme.css`)
- `resources/assets/themes/tailadmin.css` — TailAdmin-inspired enterprise dashboard theme
- `resources/assets/themes/fonts/outfit/*.woff2` — Self-hosted Outfit font files for TailAdmin theme

## Risks

| Risk | Mitigation |
|------|-------------|
| Theme CSS must stay in sync with upstream Coolify class changes | Document token mapping; scope overrides under `data-ce-theme`; test after Coolify upgrades |
| New Coolify UI elements may not be styled by themes | Themes override Coolify tokens (`--color-*`); new elements using tokens inherit styling; custom classes need periodic review |

## Testing Checklist

- [ ] Toggle between Default, Enhanced, and TailAdmin in Settings > Appearance
- [ ] Verify light mode and dark mode for each theme (use Coolify's theme switcher)
- [ ] Verify Outfit font loads for TailAdmin theme (no fallback to system font in key areas)
- [ ] Verify "Reload the page" instruction appears and styling applies after reload
- [ ] Verify config fallback when `active_theme` is missing or invalid (default or null)
- [ ] Verify migration: existing `enhanced_theme_enabled=1` becomes `active_theme=enhanced`
- [ ] Verify admin-only access to Appearance page; non-admin users get 403

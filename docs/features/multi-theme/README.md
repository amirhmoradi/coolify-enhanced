# Multi-Theme System

Theme selector in **Settings > Appearance** allowing administrators to switch between multiple visual styles for the Coolify dashboard. All themes are CSS-only (no DOM changes) and self-hosted; no external CDN dependencies.

## What It Does

- **Theme selector** — Dropdown in Settings > Appearance with options: Default, Enhanced, TailAdmin
- **Instance-wide** — Single selection applies to all users; admin-controlled
- **Reload required** — Styling applies after page reload (no live swap)
- **Light and dark** — Each theme supports both Coolify light and dark modes

## Available Themes

| Theme | Description |
|-------|-------------|
| **Default (Coolify)** | Stock Coolify styling; no enhanced theme applied |
| **Enhanced (Linear)** | Deep neutrals, crisp borders, restrained accent usage. Inspired by Linear. |
| **TailAdmin** | Clean enterprise dashboard with brand blues, warm grays, polished form controls. Uses self-hosted Outfit font. |

## Architecture

- **CSS scoping** — Themes are scoped via `data-ce-theme="{slug}"` on `<html>`. Selectors use `html[data-ce-theme="enhanced"]` and `html.dark[data-ce-theme="tailadmin"]` to avoid conflicts.
- **Theme registry** — `config/coolify-enhanced.php` defines `ui_theme.themes` with slug, label, description, CSS path, and optional font label.
- **Persistence** — `active_theme` key in `enhanced_ui_settings` table; cached 60s; config fallback when DB value is missing or invalid.
- **Self-hosted fonts** — TailAdmin uses Outfit WOFF2 bundled under `themes/fonts/outfit/`; same pattern as Coolify's Inter font for air-gapped support.
- **Validation** — `EnhancedUiSettings::getActiveTheme()` validates slug against registry; returns null for invalid themes.

## Adding a New Theme

1. **Create CSS file** — Add `resources/assets/themes/{slug}.css` scoped under `html[data-ce-theme="{slug}"]` and `html.dark[data-ce-theme="{slug}"]`. Override Coolify tokens (`--color-base`, `--color-coollabs`, etc.).
2. **Register in config** — Add entry to `config/coolify-enhanced.php` under `ui_theme.themes`:
   ```php
   '{slug}' => [
       'label' => 'Theme Name',
       'description' => '...',
       'css' => 'themes/{slug}.css',
       'font_label' => null, // or 'Font Name' if custom font
   ],
   ```
3. **Optional fonts** — If the theme uses custom fonts, add WOFF2 files under `resources/assets/themes/fonts/` and reference via relative `url()` in the CSS.
4. **Rebuild Docker image** — Themes are copied into the image at build time; rebuild and redeploy to include the new theme.

## Related Docs

- [PRD.md](PRD.md) — Product requirements and technical decisions
- [plan.md](plan.md) — Implementation plan and code snippets
- [../enhanced-ui-theme/](../enhanced-ui-theme/) — Original single-theme (boolean toggle) design

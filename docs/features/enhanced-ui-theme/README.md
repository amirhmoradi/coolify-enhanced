# Enhanced UI Theme

Optional corporate-grade modern UI theme for Coolify. Activatable under **Settings > Appearance**; **disabled by default**.

## Overview

- **Design system direction** — Linear-inspired enterprise SaaS style (deep neutrals, crisp borders, focused accent usage).
- **CSS and minimal JavaScript only** — no structural DOM or layout changes.
- **Light and dark modes** — clean cool surfaces in light mode and deep neutral layers in dark mode.
- **Same base framework** — Tailwind; theme overrides tokens and key selectors when enabled.
- **Instance-wide** — preference stored in `enhanced_ui_settings`; reload the page after toggling.
- **Admin-controlled** — Appearance tab is visible to owner/admin users.

## Components

| Component | Purpose |
|----------|--------|
| `EnhancedUiSettings` | Key-value store for `enhanced_theme_enabled` (and future UI settings). |
| `enhanced_theme_enabled()` | Global helper used by the base layout overlay to decide whether to inject theme CSS/script. |
| Settings > Appearance | Livewire page with one toggle; saves to `EnhancedUiSettings`. |
| Base layout overlay | Injects `<link href=".../theme.css">` and `data-ce-theme="enhanced"` on `<html>` when enabled. |
| `theme.css` | Scoped overrides under `html[data-ce-theme="enhanced"]` and `html.dark[data-ce-theme="enhanced"]`. |

## File list

- `database/migrations/2024_01_01_000017_create_enhanced_ui_settings_table.php`
- `src/Models/EnhancedUiSettings.php`
- `config/coolify-enhanced.php` — `ui_theme.enabled` (env fallback)
- `src/Livewire/AppearanceSettings.php`
- `resources/views/livewire/appearance-settings.blade.php`
- `src/Overrides/Views/layouts/base.blade.php` — conditional theme link + script
- `src/Overrides/Views/components/settings/navbar.blade.php` — Appearance tab
- `resources/assets/theme.css`
- `routes/web.php` — `settings.appearance`
- `src/CoolifyEnhancedServiceProvider.php` — `enhanced_theme_enabled()` helper, theme asset publish
- `docker/Dockerfile` — copy base overlay and theme.css

## Related docs

- [PRD.md](PRD.md) — Product requirements and acceptance criteria
- [plan.md](plan.md) — Technical implementation plan and verification checklist
- Legacy design notes: `docs/plans/2025-02-25-enhanced-ui-theme-design.md`
- Legacy implementation notes: `docs/plans/2025-02-25-enhanced-ui-theme-implementation-plan.md`

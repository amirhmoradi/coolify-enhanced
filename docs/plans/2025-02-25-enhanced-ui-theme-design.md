# Enhanced UI Theme — Design Document

**Date:** 2025-02-25  
**Branch:** feature/enhanced-ui-theme  
**Status:** Approved for implementation

## 1. Goal

Add an optional, corporate-grade modern UI theme for Coolify that:

- Uses **CSS and minimal JavaScript only** (no structural DOM/layout changes).
- Is **activatable by the user** under Settings, **disabled by default**.
- Supports **light and dark modes** with a sophisticated, modern color palette.
- Keeps Coolify’s base framework (Tailwind) and only overrides tokens and key selectors when the theme is enabled.

## 2. Constraints

- **No structural change:** Do not add/remove nodes or change layout structure; only apply visual overrides via a scoping selector (e.g. `[data-ce-theme="enhanced"]`).
- **Same base framework:** Continue using Tailwind; override via CSS custom properties and scoped overrides, not a different CSS framework.
- **Opt-in and default-off:** Theme is off by default; user turns it on in Settings.
- **Deployable via existing overlay pattern:** Use the same overlay/Docker copy approach as other coolify-enhanced features.

## 3. Approach

### 3.1 Activation and persistence

- **Settings:** New **Appearance** tab in Settings (in the existing settings navbar overlay) with a single toggle: “Use enhanced theme”.
- **Persistence:** Store in a new package table `enhanced_ui_settings` (key-value: e.g. `enhanced_theme_enabled` → boolean). Instance-wide; no per-user table for this phase.
- **Default:** `enhanced_theme_enabled` is `false` (or absent) so the theme is disabled by default.

### 3.2 How the theme is applied (no structure change)

- **Layout overlay:** Add an overlay of Coolify’s `resources/views/layouts/base.blade.php` that:
  - Keeps the original layout unchanged.
  - Before `</head>`, adds a conditional block:
    - If enhanced theme is enabled (see 3.3), output:
      - `<link rel="stylesheet" href="{{ asset('vendor/coolify-enhanced/theme.css') }}">`
      - A small inline script that sets `document.documentElement.setAttribute('data-ce-theme', 'enhanced')` (so it runs after existing theme script and does not remove `.dark`).
- **Theme CSS:** Single file `theme.css` that:
  - Scopes all rules under `[data-ce-theme="enhanced"]` (and `.dark [data-ce-theme="enhanced"]` for dark mode).
  - Overrides Tailwind-compatible tokens (e.g. CSS variables or class-based overrides) for:
    - Backgrounds, surfaces, borders, text colors.
    - Buttons, inputs, cards, tables.
    - Success/warning/error and accent colors.
  - Does not change HTML structure or add new elements.

### 3.3 “Enhanced theme enabled” in the app

- **Helper:** Register a global helper (e.g. `enhanced_theme_enabled()`) that:
  - Returns true only if `config('coolify-enhanced.enabled')` is true and the stored value for `enhanced_theme_enabled` is true.
  - Reads from the new `enhanced_ui_settings` store (or config fallback for installs that haven’t run migrations yet).
- **View:** The base layout overlay calls this helper (or a shared view variable set in a view composer) so the conditional link/script is only output when the theme is enabled.

### 3.4 Design direction (inspiration)

- **References:** Modern SaaS/infra dashboards (e.g. Vercel, Linear, Railway, Render): warm neutrals, single accent, clear hierarchy, minimal clutter.
- **Light mode:** Warm off-white/cream base (#FAFAF9, #F5F5F4), soft gray surfaces, subtle borders, one accent (e.g. indigo/slate blue) for primary actions.
- **Dark mode:** Near-black base (#0F0F0F, #1A1A1A), layered grays for depth, same accent; avoid pure black.
- **Traits:** Slightly rounded corners, consistent spacing, restrained shadows, high contrast for text and CTAs.

## 4. Components

| Component | Purpose |
|----------|--------|
| Migration | Create `enhanced_ui_settings` table (key, value; at least one row for `enhanced_theme_enabled`). |
| Model / settings access | Small model or repository to get/set `enhanced_theme_enabled` (and cache if needed). |
| Config | Optional `config('coolify-enhanced.ui_theme.enabled')` default false; runtime value from DB overrides. |
| Global helper | `enhanced_theme_enabled()` for use in the base layout overlay. |
| Settings UI | New “Appearance” tab + Livewire (or equivalent) page with one toggle; saves to `enhanced_ui_settings`. |
| Routes | e.g. `settings.appearance` pointing to the Appearance settings view. |
| Base layout overlay | Copy of Coolify’s `base.blade.php` with conditional theme link + `data-ce-theme` script. |
| theme.css | Single CSS file, all rules scoped by `[data-ce-theme="enhanced"]` and `.dark [data-ce-theme="enhanced"]`. |
| Dockerfile | Copy overlay `base.blade.php` and `theme.css` (or published asset path) into the image. |

## 5. Files to add or touch

- **New:** `database/migrations/xxxx_create_enhanced_ui_settings_table.php`
- **New:** `src/Models/EnhancedUiSettings.php` (or key-value accessor)
- **New:** `config/coolify-enhanced.php` — add `ui_theme` section (default `enabled` false)
- **New:** `src/Livewire/AppearanceSettings.php` (or equivalent) + view
- **New:** `resources/views/livewire/appearance-settings.blade.php`
- **New:** `resources/assets/theme.css` (or `public/` equivalent) — the enhanced theme
- **New:** `src/Overrides/Views/layouts/base.blade.php` (copy of Coolify base + conditional block)
- **Modify:** `src/Overrides/Views/components/settings/navbar.blade.php` — add “Appearance” tab and link to `settings.appearance`
- **Modify:** `routes/web.php` — register `settings.appearance` route
- **Modify:** `src/CoolifyEnhancedServiceProvider.php` — register helper, optionally view composer, publish theme asset, load theme when enhanced is enabled
- **Modify:** `docker/Dockerfile` — copy layout overlay and theme CSS into the image

## 6. Risks and mitigations

- **Coolify base layout changes:** Upstream changes to `base.blade.php` may conflict. Mitigation: document overlay in CLAUDE.md/AGENTS.md; keep overlay diff minimal (single conditional block).
- **Tailwind version:** Coolify may upgrade Tailwind. Mitigation: use semantic tokens and class overrides that are likely to stay stable; avoid relying on internal Tailwind class names that change between major versions where possible.
- **Cache:** If we cache “enhanced theme enabled,” clear cache when the setting is toggled.

## 7. Success criteria

- With enhanced theme **off:** UI looks exactly as current Coolify (no extra class or stylesheet).
- With enhanced theme **on:** Entire app uses the new palette and refinements (light/dark) without any DOM structure change.
- Toggle in Settings persists and applies after reload; default is off.
- One CSS file and one small script; no new layout structure.

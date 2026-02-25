# Enhanced UI Theme — Product Requirements Document

## Problem Statement

Coolify currently ships with a single visual style. Some teams want a more polished, enterprise-grade appearance without changing any workflow or behavior.

The addon needs an opt-in theme that improves visual design while remaining operationally safe:

1. No layout or DOM structure changes
2. Clear on/off control for admins
3. Works for both light and dark modes
4. Easy to maintain with upstream Coolify updates

## Goals

1. Add an optional enhanced theme under **Settings > Appearance**
2. Keep the theme **disabled by default**
3. Apply styling with **scoped CSS + minimal JS only**
4. Keep feature behavior unchanged when theme is disabled
5. Preserve compatibility with Coolify’s existing Tailwind-based UI
6. Use a **Linear-inspired modern SaaS visual system** (deep neutrals, crisp borders, restrained accent usage)

## Non-Goals

- Per-user theme preference (this release is instance-wide)
- Replacing Tailwind or introducing a new CSS framework
- Structural changes to Coolify’s HTML/layout
- Any changes to permissions/business logic beyond appearance access control

## User Experience

- Owners/admins can open **Settings > Appearance** and toggle **Use enhanced theme**
- The toggle persists in the addon database
- After page reload, the enhanced palette applies globally
- Disabling the toggle returns the UI to default Coolify styling

## Functional Requirements

1. Add `enhanced_ui_settings` table to persist UI settings
2. Add `EnhancedUiSettings` model with get/set helpers and short-lived caching
3. Add `AppearanceSettings` Livewire page and route `settings.appearance`
4. Add “Appearance” tab to settings navbar (owner/admin only)
5. Inject `theme.css` in base layout only when `enhanced_theme_enabled()` is true
6. Add scoped style rules under `[data-ce-theme="enhanced"]`
7. Support config fallback (`coolify-enhanced.ui_theme.enabled`) when DB value is absent

## Technical Design

- **Storage:** Key-value table `enhanced_ui_settings` (`key`, `value`)
- **Activation:** Global helper `enhanced_theme_enabled()`
  - Returns false when addon is disabled
  - Reads DB value with config fallback
- **Rendering:** Base layout overlay conditionally injects:
  - `vendor/coolify-enhanced/theme.css`
  - `data-ce-theme="enhanced"` attribute on `<html>`
- **Styling:** Theme CSS only overrides colors/typography/surfaces; no layout edits

## Files in Scope

- `database/migrations/2024_01_01_000017_create_enhanced_ui_settings_table.php`
- `src/Models/EnhancedUiSettings.php`
- `src/Livewire/AppearanceSettings.php`
- `resources/views/livewire/appearance-settings.blade.php`
- `src/Overrides/Views/layouts/base.blade.php`
- `resources/assets/theme.css`
- `src/Overrides/Views/components/settings/navbar.blade.php`
- `routes/web.php`
- `src/CoolifyEnhancedServiceProvider.php`
- `docker/Dockerfile`

## Risks and Mitigations

1. **Upstream layout drift** (base overlay is a full copy)
   - Mitigation: keep overlay diff minimal and document sync requirement
2. **Theme flash/inconsistent first render**
   - Mitigation: set `data-ce-theme` in head immediately when enabled
3. **Missing DB row on fresh installs**
   - Mitigation: helper and UI use config fallback default
4. **Unauthorized route access confusion**
   - Mitigation: show Appearance tab only to owner/admin and enforce auth in component

## Acceptance Criteria

1. With theme disabled, rendered UI matches stock Coolify visuals
2. With theme enabled, enhanced palette applies in light and dark mode
3. Appearance toggle persists across page reloads
4. Non-owner/admin users do not see Appearance tab
5. No behavioral regressions in existing pages
6. Documentation includes README + PRD + plan in this feature folder

## Test Checklist

- [ ] Migrate database successfully
- [ ] Open `settings.appearance` as owner/admin
- [ ] Toggle theme on, reload, verify global enhanced styling
- [ ] Toggle theme off, reload, verify default styling restored
- [ ] Verify non-owner/admin does not see Appearance tab
- [ ] Confirm no PHP syntax/lint issues in changed files

# Enhanced UI Theme — Technical Implementation Plan

> **Prerequisite:** Read [PRD.md](PRD.md) for goals and acceptance criteria.

## Implementation Strategy

Keep changes narrow and maintainable:

1. Persist theme preference in an addon-owned key/value table
2. Provide a small Livewire settings page for admin control
3. Apply theme through a single layout overlay with conditional CSS injection
4. Scope all style overrides under `data-ce-theme="enhanced"`
5. Preserve stock behavior when theme is disabled
6. Keep visual decisions token-driven (`--ce-*` palette) with a Linear-inspired SaaS style baseline

## Tasks

### Task 1 — Persistence layer

**Files**
- `database/migrations/2024_01_01_000017_create_enhanced_ui_settings_table.php`
- `src/Models/EnhancedUiSettings.php`

**Work**
1. Create `enhanced_ui_settings` table (`key`, `value`, timestamps)
2. Implement helper methods in `EnhancedUiSettings`:
   - `get($key, $default)` with cache
   - `set($key, $value)` with cache invalidation
3. Normalize boolean string values (`1/0`, `true/false`, `yes/no`, `on/off`)

**Validation**
- Run migration in a test environment
- Confirm get/set round-trip for boolean and string values

### Task 2 — Runtime helper and defaults

**Files**
- `config/coolify-enhanced.php`
- `src/CoolifyEnhancedServiceProvider.php`

**Work**
1. Add/maintain `ui_theme.enabled` config default (env fallback)
2. Register `enhanced_theme_enabled()` helper in service provider
3. Use config fallback when DB value is missing/unavailable
4. Publish theme asset for local/dev usage

**Validation**
- Confirm helper returns `false` when addon disabled
- Confirm helper uses config fallback when setting row is absent

### Task 3 — Appearance settings UI

**Files**
- `routes/web.php`
- `src/Livewire/AppearanceSettings.php`
- `resources/views/livewire/appearance-settings.blade.php`
- `src/Overrides/Views/components/settings/navbar.blade.php`

**Work**
1. Add `settings.appearance` route
2. Add owner/admin guard in component `mount()`
3. Add `Use enhanced theme` checkbox with `instantSave="saveEnhancedTheme"`
4. Restrict Appearance tab visibility in settings navbar to owner/admin users

**Validation**
- Owner/admin can see and use Appearance page
- Non-owner/admin cannot see Appearance tab and gets forbidden if route is opened directly

### Task 4 — Theme injection and styling

**Files**
- `src/Overrides/Views/layouts/base.blade.php`
- `resources/assets/theme.css`
- `docker/Dockerfile`

**Work**
1. Keep layout overlay identical to upstream except theme injection block
2. Inject `theme.css` + set `data-ce-theme="enhanced"` only when helper returns true
3. Keep CSS scoped and non-structural
4. Ensure Docker image copies both layout overlay and theme asset

**Validation**
- Diff overlay against upstream and verify only intentional additions
- Toggle ON/OFF and reload to confirm expected visual change

### Task 5 — Documentation compliance

**Files**
- `README.md`
- `AGENTS.md`
- `CLAUDE.md`
- `docs/features/enhanced-ui-theme/README.md`
- `docs/features/enhanced-ui-theme/PRD.md`
- `docs/features/enhanced-ui-theme/plan.md`

**Work**
1. Document activation path and defaults for end users
2. Document architecture/pitfalls for AI agents
3. Ensure feature folder contains required PRD + plan + README

**Validation**
- Confirm links resolve and docs are internally consistent

## Verification Commands

Run after implementation:

```bash
php -l src/CoolifyEnhancedServiceProvider.php
php -l src/Livewire/AppearanceSettings.php
php -l src/Models/EnhancedUiSettings.php
php -l routes/web.php
php -l database/migrations/2024_01_01_000017_create_enhanced_ui_settings_table.php
```

Optional environment verification:

```bash
php artisan migrate:status | rg enhanced_ui_settings
```

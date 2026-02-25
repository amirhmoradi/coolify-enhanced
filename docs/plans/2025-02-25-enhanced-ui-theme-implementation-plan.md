# Enhanced UI Theme — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add an optional, corporate-grade UI theme (CSS + minimal JS only), activatable in Settings and disabled by default.

**Architecture:** Theme applied via scoped CSS (`[data-ce-theme="enhanced"]`) when user enables it; preference stored in new `enhanced_ui_settings` table; base layout overlay injects theme stylesheet and script; Settings gains an "Appearance" tab with one toggle.

**Tech Stack:** Laravel, Livewire, Blade, Tailwind-compatible CSS, Docker overlay.

---

## Task 1: Migration and model for enhanced UI settings

**Files:**
- Create: `database/migrations/2024_01_01_000017_create_enhanced_ui_settings_table.php`
- Create: `src/Models/EnhancedUiSettings.php`

**Steps:**

1. Create migration that creates table `enhanced_ui_settings` with columns: `id` (bigIncrements), `key` (string, unique), `value` (text), `timestamps`.
2. Run `php artisan migrate` (or equivalent in project) to verify.
3. Create model `EnhancedUiSettings` with `$fillable = ['key', 'value']`, `$casts = ['value' => 'string']` (or cast per key if needed). Add static methods: `get(string $key, $default = null)` (find by key, return value) and `set(string $key, $value)` (updateOrCreate by key).
4. Commit: `feat(ui-theme): add enhanced_ui_settings migration and model`

---

## Task 2: Config and global helper

**Files:**
- Modify: `config/coolify-enhanced.php` (add `ui_theme` section)
- Modify: `src/CoolifyEnhancedServiceProvider.php` (register helper; ensure theme is only considered when package is enabled)

**Steps:**

1. In `config/coolify-enhanced.php`, add section:
   ```php
   'ui_theme' => [
       'enabled' => env('COOLIFY_ENHANCED_UI_THEME', false),
   ],
   ```
   (Runtime value will come from DB; this is fallback/default.)
2. In service provider `boot()`, after loading migrations, register global helper (only if package enabled):
   ```php
   if (!function_exists('enhanced_theme_enabled')) {
       function enhanced_theme_enabled(): bool {
           if (!config('coolify-enhanced.enabled', false)) return false;
           try {
               return (bool) \AmirhMoradi\CoolifyEnhanced\Models\EnhancedUiSettings::get('enhanced_theme_enabled', false);
           } catch (\Throwable $e) {
               return false;
           }
       }
   }
   ```
3. Commit: `feat(ui-theme): add ui_theme config and enhanced_theme_enabled() helper`

---

## Task 3: Settings Appearance tab and route

**Files:**
- Modify: `src/Overrides/Views/components/settings/navbar.blade.php` (add Appearance link)
- Modify: `routes/web.php` (add route for settings.appearance)
- Create: `src/Livewire/AppearanceSettings.php`
- Create: `resources/views/livewire/appearance-settings.blade.php`

**Steps:**

1. In settings navbar overlay, add an "Appearance" tab linking to `route('settings.appearance')`, visible when `config('coolify-enhanced.enabled')` is true (same condition as other enhanced tabs).
2. In `routes/web.php`, add route (middleware auth, instance admin if applicable): e.g. `Route::get('/settings/appearance', AppearanceSettings::class)->name('settings.appearance');`
3. Create Livewire component `AppearanceSettings`: mount reads `enhanced_theme_enabled()`; public property `$enhancedThemeEnabled` (bool); method `save()` or `toggleEnhancedTheme()` that calls `EnhancedUiSettings::set('enhanced_theme_enabled', $value)` and optionally clears any view/cache; render view `coolify-enhanced::livewire.appearance-settings`.
4. Create view with title "Appearance", subtitle, and single toggle "Use enhanced theme" bound to `$enhancedThemeEnabled`, saving on change (e.g. wire:model.live + wire:change or button "Save"). Use existing Coolify form components (e.g. `x-forms.checkbox` or equivalent).
5. Commit: `feat(ui-theme): add Settings > Appearance tab and toggle`

---

## Task 4: Base layout overlay (inject theme link and script)

**Files:**
- Create: `src/Overrides/Views/layouts/base.blade.php` (full copy of Coolify's base + conditional block)

**Steps:**

1. Copy `docs/coolify-source/resources/views/layouts/base.blade.php` to `src/Overrides/Views/layouts/base.blade.php`.
2. Before `</head>`, add:
   ```blade
   {{-- Coolify Enhanced: Optional enhanced UI theme --}}
   @if(function_exists('enhanced_theme_enabled') && enhanced_theme_enabled())
   <link rel="stylesheet" href="{{ asset('vendor/coolify-enhanced/theme.css') }}">
   <script>
   (function(){
     document.documentElement.setAttribute('data-ce-theme', 'enhanced');
   })();
   </script>
   @endif
   ```
3. Commit: `feat(ui-theme): overlay base layout to inject theme when enabled`

---

## Task 5: Theme CSS (scoped overrides)

**Files:**
- Create: `resources/assets/theme.css` (or `public/theme.css` in package)

**Steps:**

1. Create `theme.css` with all selectors scoped under `[data-ce-theme="enhanced"]` and `.dark [data-ce-theme="enhanced"]`.
2. Override at least: `--color-base`, `--color-coolgray-*`, backgrounds for body/main/sidebar, borders, buttons, inputs, tables, cards (using Coolify’s existing class names where possible). Light: warm off-white base (#FAFAF9), soft grays; dark: near-black (#0F0F0F / #1A1A1A), layered grays; single accent (e.g. indigo/slate) for primary actions.
3. Ensure no structural changes (no new layout); only colors, borders, shadows, radii.
4. Publish asset: in service provider, `$this->publishes([ __DIR__.'/../resources/assets/theme.css' => public_path('vendor/coolify-enhanced/theme.css') ], 'coolify-enhanced-theme');` and in Dockerfile copy this file to `/var/www/html/public/vendor/coolify-enhanced/theme.css`.
5. Commit: `feat(ui-theme): add scoped theme.css for light and dark`

---

## Task 6: Dockerfile and asset copy

**Files:**
- Modify: `docker/Dockerfile` (copy base layout overlay and theme.css)

**Steps:**

1. Add COPY for base layout: `COPY --chown=www-data:www-data src/Overrides/Views/layouts/base.blade.php /var/www/html/resources/views/layouts/base.blade.php`
2. Ensure theme.css is available in container: either copy from package `resources/assets/theme.css` to `/var/www/html/public/vendor/coolify-enhanced/theme.css` (create directory if needed).
3. Commit: `feat(ui-theme): Dockerfile overlay for base layout and theme asset`

---

## Task 7: Publish theme asset in service provider

**Files:**
- Modify: `src/CoolifyEnhancedServiceProvider.php`

**Steps:**

1. In `boot()`, add publishes for theme: `__DIR__.'/../resources/assets/theme.css' => public_path('vendor/coolify-enhanced/theme.css')` with tag `coolify-enhanced-theme`.
2. For development/local: ensure after `php artisan vendor:publish --tag=coolify-enhanced-theme` the file is at `public/vendor/coolify-enhanced/theme.css`. Document in README if needed.
3. Commit: `chore(ui-theme): publish theme.css asset`

---

## Task 8: Documentation updates

**Files:**
- Modify: `README.md` (user-facing: how to enable enhanced theme, where the toggle is)
- Modify: `CLAUDE.md` (architecture: overlay, helper, table, theme scoping)
- Modify: `AGENTS.md` (same + pitfalls)
- Create: `docs/features/enhanced-ui-theme/README.md` (feature overview, file list)

**Steps:**

1. Add short section in README: "Enhanced UI theme" — optional modern theme, Settings > Appearance, off by default.
2. In CLAUDE.md: add Enhanced UI Theme to project overview; Quick Reference package structure (EnhancedUiSettings, theme.css, base overlay); Key Files table; Common Pitfalls (e.g. theme only applies when enhanced_theme_enabled() and package enabled).
3. In AGENTS.md: same architecture summary; overlay file list; note that base.blade.php is full copy.
4. Create `docs/features/enhanced-ui-theme/README.md` with overview, components, file list, link to design doc.
5. Commit: `docs: document enhanced UI theme feature`

---

## Execution

Implement tasks in order (1 → 8). Run migrations and smoke-test Settings > Appearance and theme on/off after Task 3 and Task 5.

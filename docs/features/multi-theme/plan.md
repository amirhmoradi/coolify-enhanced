# Multi-Theme System — Implementation Plan

> **Prerequisite:** Read [PRD.md](PRD.md) for goals and technical decisions.

## Phase 1 — Migration and Registry

**Goal:** Migrate from boolean `enhanced_theme_enabled` to slug-based `active_theme`; add theme registry.

### Migration

```php
// database/migrations/2024_01_01_000018_convert_theme_to_multi_select.php
// up(): If enhanced_theme_enabled=1, set active_theme='enhanced'; delete old key
// down(): If active_theme non-empty, set enhanced_theme_enabled=1; delete active_theme
```

### Config Registry

```php
// config/coolify-enhanced.php
'ui_theme' => [
    'default' => env('COOLIFY_ENHANCED_UI_THEME', null),
    'themes' => [
        'enhanced' => [
            'label' => 'Enhanced (Linear)',
            'description' => '...',
            'css' => 'themes/enhanced.css',
            'font_label' => null,
        ],
        'tailadmin' => [
            'label' => 'TailAdmin',
            'description' => '...',
            'css' => 'themes/tailadmin.css',
            'font_label' => 'Outfit',
        ],
    ],
],
```

### EnhancedUiSettings

- `getActiveTheme()` — Returns validated slug from DB or config default; returns null if theme not in registry
- `setActiveTheme(?string $theme)` — Persists slug; empty string clears
- `getAvailableThemes()` — Returns `config('coolify-enhanced.ui_theme.themes')`

## Phase 2 — UI and Base Layout

**Goal:** Theme dropdown in Appearance; base layout injects selected theme CSS and `data-ce-theme`.

### AppearanceSettings Livewire

- Replace boolean with `$activeTheme` (nullable string)
- `saveTheme()` calls `setActiveTheme($this->activeTheme)`
- Dispatch success: "Theme updated. Reload the page to see changes."

### Blade View

```blade
<select wire:model="activeTheme" wire:change="saveTheme">
    <option value="">Default (Coolify)</option>
    @foreach ($availableThemes as $slug => $theme)
        <option value="{{ $slug }}">{{ $theme['label'] }}...</option>
    @endforeach
</select>
```

### Base Layout Overlay

```php
$ceActiveTheme = EnhancedUiSettings::getActiveTheme();
$ceThemeConfig = $ceActiveTheme ? config("coolify-enhanced.ui_theme.themes.{$ceActiveTheme}") : null;
@if($ceThemeConfig)
<link rel="stylesheet" href="{{ asset('vendor/coolify-enhanced/' . $ceThemeConfig['css']) }}">
<script>(function(){ document.documentElement.setAttribute('data-ce-theme', '{{ $ceActiveTheme }}'); })();</script>
@endif
```

## Phase 3 — Themes and Assets

**Goal:** Move Enhanced theme to `themes/enhanced.css`; add TailAdmin theme with self-hosted Outfit font.

### File Structure

```
resources/assets/themes/
  enhanced.css      # Scoped: [data-ce-theme="enhanced"]
  tailadmin.css    # Scoped: [data-ce-theme="tailadmin"]; @font-face for Outfit
  fonts/
    outfit/
      outfit-latin.woff2
```

### Dockerfile

```dockerfile
RUN mkdir -p /var/www/html/public/vendor/coolify-enhanced/themes
COPY resources/assets/themes/ /var/www/html/public/vendor/coolify-enhanced/themes/
```

### CSS Scoping Pattern

Each theme file:

- Light: `html[data-ce-theme="{slug}"] { ... }`
- Dark: `html.dark[data-ce-theme="{slug}"] { ... }`
- Override Coolify tokens (`--color-base`, `--color-coollabs`, etc.)

## Verification

```bash
php artisan migrate --path=vendor/amirhmoradi/coolify-enhanced/database/migrations
# Toggle themes in Settings > Appearance; reload; verify light/dark
```

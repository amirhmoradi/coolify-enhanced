# Theming Impact Analysis — Coolify Source

> Detailed assessment of what it takes to apply CSS-only themes (TailAdmin, Metronic,
> etc.) to Coolify v4 without modifying its core source code, plus a white-labeling
> feasibility study.
>
> **Scope:** Read-only analysis. No implementation.
> **Date:** Feb 2026
> **Coolify version analysed:** v4.x (`docs/coolify-source/`)

---

## 1. Executive Summary

Coolify's UI can be themed to approximately **85-90%** coverage using scoped CSS
overrides and `!important` rules alone. The remaining 10-15% involves hardcoded colors
in JavaScript (charts, editor), inline Alpine.js `style` bindings (form focus states),
and a handful of brand SVGs. Full 95%+ coverage requires a small JavaScript shim
alongside the CSS.

**White-labeling** (product identity replacement) is a separate concern analyzed in
Section 11. Coolify's `APP_NAME` env var covers ~30% of brand references; a
configuration-driven overlay approach can reach ~90% with 3-5 overlay files.

| Category | File count | Themeable via CSS | Requires JS/overlay |
|---|---|---|---|
| CSS custom properties (`@theme`) | 1 file, 20 tokens | 100% | -- |
| Custom `@utility` blocks | 24 utilities | 18 of 24 (75%) | 6 use hardcoded hex |
| Blade components | ~31 components | 27 of 31 (87%) | 4 use hardcoded hex |
| Blade view pages | ~316 files | ~236 (75%) | ~80 use standard TW colors |
| JavaScript (charts/editor) | 4 files | 0% | 100% |
| SVG icons | ~90 SVGs | ~85 use `currentColor` | ~5 hardcoded fills |

---

## 2. Coolify's Color Architecture

### 2.1 CSS Custom Properties (20 tokens)

Defined via Tailwind v4 `@theme` directive in `resources/css/app.css`:

```
Base:       --color-base: #101010
Grays:      --color-coolgray-100: #181818  through  --color-coolgray-500: #323232
Brand:      --color-coollabs: #6b16ed  (purple)
            --color-coollabs-50/100/200/300
Semantic:   --color-success: #22C55E
            --color-error: #dc2626
            --color-warning: #fcd452  (+full 50-900 scale)
```

**Theming impact:** These are the primary lever. A theme CSS file that redefines these
tokens under a scoped selector (`html[data-ce-theme="X"]`) automatically recolors every
Tailwind utility that references them (`bg-coolgray-100`, `text-coollabs`, `border-warning`, etc.).

### 2.2 Dark Mode

- Class-based: `.dark` on `<html>`, toggled via `localStorage.theme`
- Custom variant: `@custom-variant dark (&:where(.dark, .dark *))`
- Default for most users: dark mode

**Theming impact:** Themes must provide both `html[data-ce-theme="X"]` (light) and
`html.dark[data-ce-theme="X"]` (dark) token blocks. The dark mode variant does not need
custom handling since Tailwind's `.dark` class coexists with our `data-ce-theme` attribute.

---

## 3. Themeable Surface Area (What Works Today)

### 3.1 Backgrounds and Surfaces

| Tailwind class | Context | Themeable? |
|---|---|---|
| `dark:bg-base` | Page background | Yes (maps to `--color-base`) |
| `dark:bg-coolgray-100` | Sidebar, cards, panels | Yes |
| `dark:bg-coolgray-200` | Active menu items, tabs | Yes |
| `bg-white` | Light-mode cards/panels | Yes (CSS override) |
| `bg-neutral-100/200/300` | Light-mode hover/subtle | Yes (CSS override) |

### 3.2 Text Colors

| Tailwind class | Context | Themeable? |
|---|---|---|
| `dark:text-white` | Primary text | Yes |
| `dark:text-neutral-400` | Secondary/muted text | Yes |
| `text-coollabs` / `dark:text-warning` | Brand accent | Yes |
| `text-success` / `text-error` | Semantic | Yes |
| `text-neutral-500/600` | Descriptions | Yes |

### 3.3 Borders

| Tailwind class | Context | Themeable? |
|---|---|---|
| `dark:border-coolgray-200/300/400` | Panel/card borders | Yes |
| `border-neutral-200/300` | Light-mode borders | Yes |

### 3.4 Components Using Only Theme Tokens (fully themeable)

- **Status badges** (`badge-success`, `badge-warning`, `badge-error`)
- **Navbars** (main, settings, team, security, notification — 5 components)
- **Modals** (modal, modal-confirmation, modal-input, slide-over, confirm-modal — 5 components)
- **Sidebar** (server/sidebar, settings/sidebar)
- **Cards** (`coolbox`, `box` utilities)
- **Buttons** (`button` utility)
- **Menu items** (`menu-item`, `menu-item-active`, `sub-menu-item`)
- **Tags** (`tag` utility)
- **Alerts** (`alert-success`, `alert-error`)
- **Callouts** (warning, danger, info, success variants)
- **Banners**, **Loading indicators**, **Scrollbars**
- **Checkboxes** (uses Tailwind utilities, no hardcoded colors)

---

## 4. Partially Themeable (CSS Override Possible, but Requires Specificity)

### 4.1 Standard Tailwind Color Classes (~80 files)

Coolify uses standard Tailwind colors (`red-*`, `green-*`, `blue-*`, `yellow-*`,
`purple-*`, `pink-*`, `orange-*`) alongside its custom tokens. These bypass the custom
property system entirely.

**Most common occurrences:**

| Color family | Usage | Files affected |
|---|---|---|
| `red-*` (50-900) | Error states, validation, danger | ~25 files |
| `green-*` (50-900) | Success states, health checks | ~20 files |
| `blue-*` (50-900) | Info states, deployment | ~10 files |
| `yellow-*` (400-600) | Caution, warnings | ~8 files |
| `purple-*` (100-700) | Deployment status | ~5 files |
| `pink-*` (300-500) | Admin/sponsor icons | ~3 files |
| `orange-*` (400) | Application icons | ~2 files |
| `gray-*` / `neutral-*` | Everywhere | ~80 files |

**Key files using standard colors:**
- `livewire/server/show.blade.php` — server status indicators
- `livewire/project/application/deployment/index.blade.php` — deployment states
- `livewire/project/database/backup-executions.blade.php` — backup status
- `livewire/storage/form.blade.php` — S3 validation badges
- `livewire/server/security/terminal-access.blade.php` — access status

**Theming approach:** These CAN be overridden using high-specificity CSS selectors:
```css
html[data-ce-theme="tailadmin"] .text-red-500 { color: #f04438 !important; }
html[data-ce-theme="tailadmin"] .bg-green-50 { background-color: #ecfdf3 !important; }
```
But this requires enumerating every standard Tailwind color class used in Coolify.

**Estimated override selectors needed:** ~60-80 rules to cover all standard color variants.

**Risk:** Coolify may add new standard-color usages in future releases, requiring theme
updates.

### 4.2 Callout Component Variants

The `callout.blade.php` component uses per-variant standard Tailwind colors:
- Warning: `bg-warning-50`, `border-warning-300`, `text-warning-800` (uses theme token)
- Danger: `bg-red-50`, `border-red-300`, `text-red-800` (standard Tailwind)
- Info: `bg-blue-50`, `border-blue-300`, `text-blue-800` (standard Tailwind)
- Success: `bg-green-50`, `border-green-300`, `text-green-800` (standard Tailwind)

**Theming approach:** Override the standard color variants with themed equivalents.

---

## 5. Resistant to CSS-Only Theming

### 5.1 Hardcoded Hex Colors in Form Utilities (HIGH IMPACT)

The most visible theming gap. These appear in `resources/css/utilities.css` and in
Blade component inline styles:

| Hex value | Purpose | Where used |
|---|---|---|
| `#6b16ed` | Focus border accent (light mode) | `input`, `select`, `textarea`, `datalist`, `input-sticky` utilities + Blade components |
| `#fcd452` | Focus border accent (dark mode) | Same as above |
| `#e5e5e5` | Input border (light mode) | Same as above |
| `#242424` | Input border (dark mode) | Same as above |
| `#000000` / `#ffffff` | Select chevron SVG (data URI) | `select` utility |

**Why resistant:** These values are hardcoded in CSS `box-shadow` declarations and SVG
data URIs. They are NOT CSS custom properties.

**Theming approach:** Override with scoped CSS at higher specificity:
```css
html[data-ce-theme="tailadmin"] .input {
    box-shadow: inset 0 0 0 2px var(--ta-border) !important;
}
html[data-ce-theme="tailadmin"] .input:focus-within {
    box-shadow: inset 4px 0 0 var(--ta-accent), inset 0 0 0 2px var(--ta-border) !important;
}
```

**Complexity:** Medium. ~15 CSS rules needed. Works for the CSS utilities but NOT for
the inline `style` bindings in Alpine.js (see 5.2).

### 5.2 Alpine.js Inline Style Bindings (MEDIUM IMPACT)

The `datalist.blade.php` component uses Alpine.js to set `boxShadow` directly in
JavaScript with hardcoded hex values:

```javascript
$el.style.boxShadow = focused
    ? 'inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424'
    : 'inset 4px 0 0 transparent, inset 0 0 0 2px #242424';
```

Also found in `input.blade.php` and `textarea.blade.php` via `wire:dirty.class` with
inline styles.

**Why resistant:** `element.style.boxShadow` sets an inline style that beats any CSS
selector specificity (except `!important` on the same property).

**Theming approach:** CSS `!important` on the scoped selector will NOT work against
inline styles. Options:
1. **Overlay the Blade component** to use CSS variables instead of hardcoded hex
2. **JavaScript shim** that overrides the Alpine.js handler
3. **Accept the gap** — form focus states use Coolify's native colors

**Complexity:** Low impact on user experience (focus state only), but requires overlay
or JS for complete fix.

### 5.3 Chart Colors in JavaScript (MEDIUM IMPACT)

ApexCharts are configured with hardcoded color values in JavaScript:

**`layouts/base.blade.php`** (global `checkTheme()` function):
```javascript
cpuColor = '#1e90ff';   // DodgerBlue
ramColor = '#00ced1';   // DarkTurquoise
textColor = '#ffffff';  // or '#000000'
```

**`server/charts.blade.php`** and **`project/shared/metrics.blade.php`**:
```javascript
colors: ['#FCD452']   // ApexCharts data label color
```

**Why resistant:** ApexCharts reads color values from JavaScript objects at
initialization time. CSS custom properties have no effect on `ApexCharts.options.colors`.

**Theming approach:**
1. **JavaScript shim** in the theme CSS's companion `<script>` tag that overrides
   `checkTheme()` to read CSS variables via `getComputedStyle()`
2. **Accept the gap** — charts use their own color scheme independent of theme

**Complexity:** Medium. Charts are on server detail and resource metrics pages only.
Most users visit configuration pages more frequently.

### 5.4 Monaco Editor Colors (LOW IMPACT)

The code editor background is set via JavaScript:
```javascript
backgroundColor: isDark ? '#181818' : '#ffffff'
```

**Theming approach:** Monaco has its own theme system (`vs-dark`, `vs-light`). CSS
overrides are unreliable. Best handled via a JS shim that defines a custom Monaco
theme matching the active Coolify theme.

**Complexity:** Low priority — the editor already has its own dark/light handling.

### 5.5 Hardcoded SVG Fill Colors (LOW IMPACT)

~5 SVGs use hardcoded fill colors for brand logos:

| Color | SVG context | File |
|---|---|---|
| `#635BFF` | Stripe logo | `subscription/actions.blade.php` |
| `#D50C2D` | Hetzner logo | `server/create.blade.php`, `server/show.blade.php`, `boarding/` |
| `#00618A` | MySQL logo | `app/Livewire/Project/New/Select.php` |
| `#000000` | Decorative dots | `pricing-plans.blade.php` |

**Theming approach:** These are brand-specific logos that should NOT be recolored.
No action needed.

### 5.6 Email Templates (NO IMPACT)

`vendor/mail/html/themes/default.css` contains hardcoded colors for email rendering.
Emails render in the recipient's email client, not in Coolify's UI. No theming needed.

---

## 6. Effort Estimation by Theme Type

### 6.1 "Color Palette Swap" Theme (like current Enhanced/TailAdmin)

**What it covers:** Redefine the 20 CSS custom properties + override ~15 utility-level
selectors for backgrounds, text, borders, and form controls.

| Area | CSS rules needed | Effort |
|---|---|---|
| Light mode tokens | ~20 | Low |
| Dark mode tokens | ~20 | Low |
| Foundation overrides | ~15 | Low |
| Form control overrides | ~15 | Low |
| Navigation overrides | ~5 | Low |
| **Total** | **~75 rules** | **1-2 hours** |

**Coverage:** ~70% of the visual surface. All major surfaces, cards, forms, nav.
Standard Tailwind colors (status indicators, badges) remain Coolify-native.

### 6.2 "Full Visual Overhaul" Theme (like TailAdmin with all status colors)

Everything in 6.1 plus overrides for standard Tailwind color classes.

| Area | CSS rules needed | Effort |
|---|---|---|
| Everything in 6.1 | ~75 | Low |
| Standard `red-*` overrides | ~10 | Low |
| Standard `green-*` overrides | ~10 | Low |
| Standard `blue-*` overrides | ~8 | Low |
| Standard `yellow-*` overrides | ~6 | Low |
| Standard `purple-*`/`pink-*`/`orange-*` | ~8 | Low |
| Standard `gray-*`/`neutral-*` | ~20 | Medium |
| **Total** | **~137 rules** | **3-5 hours** |

**Coverage:** ~85-90%. All colors are themed. Only JavaScript-driven elements
(charts, editor, form focus inline styles) remain unthemed.

### 6.3 "Pixel-Perfect" Theme (95%+ coverage)

Everything in 6.2 plus JavaScript shims for resistant areas.

| Area | Additional work | Effort |
|---|---|---|
| Everything in 6.2 | ~137 CSS rules | 3-5 hours |
| Chart color JS shim | ~30 lines JS | 1 hour |
| Form focus inline style fix | Blade overlay OR JS shim | 1-2 hours |
| Monaco editor theme | Custom Monaco theme def | 1 hour |
| **Total** | **~137 CSS + ~80 JS** | **6-9 hours** |

**Coverage:** 95%+. Only brand SVGs (intentionally unchanged) and email templates
remain unthemed.

---

## 7. Comparison: What Commercial Themes Would Need

### 7.1 TailAdmin (current implementation)

TailAdmin uses the same Tailwind v4 `@theme` approach with `--color-brand-*`,
`--color-gray-*` scales. Its design language maps naturally to Coolify's tokens.

**Estimated effort for a high-quality theme:** 3-5 hours (Level 6.2 above).

**Font:** Outfit (variable weight). Self-hosted as WOFF2 in the theme. Already done.

**Specific considerations:**
- TailAdmin's warm grays (`#fcfcfd`-`#0c111d`) vs Coolify's cool grays (`#181818`-`#323232`)
  map cleanly via token override
- TailAdmin's brand blue (`#465fff`) replaces Coolify's purple (`#6b16ed`)
- TailAdmin's shadow system (`shadow-theme-xs/sm/md`) can be defined as `--ta-shadow-*`
  tokens and applied via scoped selectors

### 7.2 Metronic (Keenthemes — Target Enterprise Theme)

Metronic is a premium Tailwind CSS admin template from Keenthemes (CodeCanyon's
top-selling dashboard). It uses a sophisticated design system built on KtUI, their
own component library. This analysis is based on [Metronic's Laravel integration docs](https://keenthemes.com/metronic/tailwind/docs/getting-started/integration/laravel)
and [theming documentation](https://keenthemes.com/metronic/tailwind/docs/customization/theming).

#### Metronic's Design System Architecture

Metronic's color system is entirely CSS-variable-driven through KtUI:

**Semantic color variables (with light/dark auto-switching):**
```
--tw-primary / --tw-primary-active / --tw-primary-light / --tw-primary-inverse
--tw-success / --tw-success-active / --tw-success-light / --tw-success-inverse
--tw-warning / --tw-warning-active / --tw-warning-light / --tw-warning-inverse
--tw-danger  / --tw-danger-active  / --tw-danger-light  / --tw-danger-inverse
--tw-info    / --tw-info-active    / --tw-info-light    / --tw-info-inverse
--tw-brand   / --tw-brand-active   / --tw-brand-light   / --tw-brand-inverse
```

**Gray scale (9-step, also CSS-variable-driven):**
```
--tw-gray-100 through --tw-gray-900
```

**Coal scale (dark mode deep backgrounds):**
`#15171C`, `#13141A`, `#111217`, `#0F1014`, `#0D0E12`, `#0B0C10`

**Component tokens:**
```
--tw-kt-card-box-shadow
--tw-default-box-shadow
--tw-primary-box-shadow / --tw-success-box-shadow / etc.
```

**Font:** Inter (same as Coolify's default — no font conflict).
**Dark mode:** Class-based via `data-kt-theme-mode` attribute + `.dark` class.
**Components:** KtUI components use `kt-*` CSS classes (`kt-btn`, `kt-card`, `kt-modal`)
with `data-kt-*` attributes for JavaScript behavior.

#### Mapping Metronic to Coolify

| Metronic token | Coolify equivalent | Mapping difficulty |
|---|---|---|
| `--tw-primary` | `--color-coollabs` | Direct (both are brand accent) |
| `--tw-primary-active` | `--color-coollabs-100` | Direct |
| `--tw-primary-light` | `--color-coollabs-50` | Direct |
| `--tw-success` | `--color-success` | Direct |
| `--tw-danger` | `--color-error` | Direct |
| `--tw-warning` | `--color-warning` | Direct |
| `--tw-gray-100..900` | `--color-coolgray-100..500` | Partial (Coolify has 5, Metronic has 9) |
| Coal colors | `--color-base` | Direct (deep dark backgrounds) |
| `--tw-kt-card-box-shadow` | No equivalent | Must define as `--mt-shadow-card` |
| KtUI component classes | No equivalent | Cannot map (different component library) |

#### Effort Estimate for a Metronic-Inspired Theme

**Level 6.2 (full visual overhaul, CSS only):** 5-8 hours.

The extra effort vs TailAdmin comes from:
1. **Richer gray scale** — Metronic uses 9 gray steps + 6 coal steps, while Coolify has 5
   coolgray steps. The theme must interpolate missing stops.
2. **Semantic color variants** — Metronic has `active`, `light`, `clarity` (transparent),
   and `inverse` variants for each semantic color. Coolify only has the base color. The
   theme can define these as `--mt-*` tokens and apply them to hover/focus states.
3. **Component shadow system** — Metronic has per-component shadow variables
   (`--tw-kt-card-box-shadow`). These map to Coolify's card/panel selectors.
4. **KtUI component classes** — Metronic uses `kt-btn`, `kt-card`, `kt-modal`, etc.
   These do NOT exist in Coolify. We cannot replicate KtUI components; we can only
   style Coolify's existing components to *visually resemble* Metronic.

**What a Metronic-inspired CSS theme CAN achieve:**
- Metronic's color palette (primary blue, semantic colors, gray/coal scales)
- Metronic's shadow elevation system
- Metronic's typography scale (Inter font already shared, size adjustments via CSS)
- Metronic's border-radius conventions
- Metronic's form control styling (border, focus ring, placeholder colors)
- Metronic's card surface treatment (backgrounds, borders, shadows)
- Metronic's table header/row styling
- Metronic's dark mode coal backgrounds

**What a Metronic-inspired CSS theme CANNOT achieve:**
- KtUI interactive components (drawer, sticky header, mega menu, image input)
- Metronic's `data-kt-*` JavaScript behavior system
- Metronic's icon set (KeenIcons — requires component overlays)
- Metronic's sidebar collapse/expand behavior
- Metronic's multi-demo layout switching (10 demo layouts)
- Metronic's structured header with mega-menu

**Licensing note:** Metronic requires a separate license per deployment. An
Extended License is required for SaaS usage. The CSS theme would be "inspired by"
Metronic's visual language, NOT a redistribution of Metronic's code or assets.

### 7.3 Common Template Features vs CSS-Only Feasibility

| Feature | CSS-only feasible? | Notes |
|---|---|---|
| Color palette swap | Yes | Token override |
| Custom font | Yes | Self-hosted WOFF2 + @font-face |
| Shadow/elevation system | Yes | CSS custom properties |
| Border radius conventions | Yes | Override `.rounded`, `.rounded-md` |
| Form control styling | Mostly | Except inline JS focus styles |
| Button variants | Yes | Override `.button` utility |
| Card styling | Partially | Colors/shadows yes; structural elements no |
| Sidebar collapse | No | Requires JS/layout change |
| Sidebar width change | Partially | CSS can override width, but content may overflow |
| Tab styling | Yes | Override `sub-menu-item` utilities |
| Table styling | Yes | Override header/row/hover colors |
| Modal styling | Yes | Override modal background/border |
| Chart colors | No | Requires JS shim |
| Navigation layout | No | Requires DOM changes |
| Icon set | No | Requires component overlays |
| Loading animations | Partially | CSS can change colors, not animation shape |
| Toast/notification styling | Yes | Override toast component colors |
| Scrollbar styling | Yes | Override scrollbar pseudo-elements |

---

## 8. Risk Assessment

### 8.1 Upstream Breakage Risk

**Risk:** Coolify updates may add new components, rename CSS classes, or change the
`@theme` token structure.

**Mitigation:**
- Theme CSS files use `!important` overrides on stable Tailwind utility class names
  (e.g., `.bg-coolgray-100`) — these change very rarely
- Token overrides (`--color-coollabs`, `--color-base`) are part of Tailwind's config
  and are stable across Coolify versions
- The scoped `html[data-ce-theme="X"]` selector ensures themes only activate when
  explicitly selected — no risk of breaking default Coolify

**Impact per release:** Typically 0-2 new utility classes may need addition to the
theme CSS. Estimated maintenance: <30 min per Coolify release.

### 8.2 Performance Risk

**Risk:** Large theme CSS files with many `!important` selectors could impact render
performance.

**Mitigation:**
- Theme CSS is loaded as a single static file (~12-15KB uncompressed, ~3KB gzipped)
- Browser CSS selector matching is O(1) per rule — 150 rules is negligible
- No runtime JS cost (except optional chart color shim)

### 8.3 Specificity War Risk

**Risk:** Coolify's own CSS may use `!important` on some properties, making theme
overrides fail.

**Current state:** Coolify does NOT use `!important` on its `@utility` blocks or
`@theme` tokens. The `!important` in theme CSS consistently wins. The one exception
is ApexCharts vendor CSS which uses `!important` on some chart styles — but our
theme doesn't need to override those.

---

## 9. Recommendations

### 9.1 For "Good Enough" Theming (current approach, 85-90%)

No changes needed to the current CSS-only scoped theme approach. The token override
strategy already covers all major surfaces, and standard Tailwind color overrides
can be added incrementally.

**Action items for theme authors:**
1. Define `--ta-*` (or `--flavor-*`, etc.) design tokens
2. Map to Coolify's `--color-*` tokens under the scoped selector
3. Add ~15-20 foundation overrides (backgrounds, text, borders)
4. Add ~15-20 standard Tailwind color overrides for status indicators
5. Optionally self-host a custom font

### 9.2 For "Near-Perfect" Theming (95%+)

Add a lightweight JavaScript shim (loaded alongside the theme CSS) that:
1. Overrides `checkTheme()` to read chart colors from CSS custom properties
2. Intercepts form component focus handlers to use CSS variables
3. Optionally defines a custom Monaco editor theme

**Estimated JS shim size:** ~50-80 lines, <2KB.

This JS shim could be a standard part of the theme infrastructure — loaded from
the theme config's `js` field (alongside `css`).

### 9.3 For Future-Proofing

Consider contributing upstream PRs to Coolify that:
1. Replace hardcoded `#6b16ed`/`#fcd452`/`#e5e5e5`/`#242424` in form utilities with
   CSS custom properties (4 hex values, ~8 occurrences)
2. Move chart colors to CSS custom properties read via `getComputedStyle()`
3. Use `currentColor` in remaining hardcoded SVGs

These are small, backward-compatible changes that would make Coolify inherently
themeable without any override hacks. Total upstream change: ~30 lines across 3 files.

---

## 10. File-Level Impact Matrix

### Files requiring NO changes for theming (fully token-driven)

All navbar components (5), all modal components (5), all status components (5),
sidebar components (2), badge utilities, card utilities, button utility, tag utility,
checkbox component, alert components.

### Files where CSS overrides are needed

| File | What to override | CSS rules |
|---|---|---|
| `utilities.css` (`input`) | Focus box-shadow, border | ~4 |
| `utilities.css` (`select`) | Focus box-shadow, border, SVG | ~4 |
| `utilities.css` (`input-sticky`) | Focus box-shadow | ~2 |
| `utilities.css` (`log-highlight`) | Background rgba | ~1 |
| `toast.blade.php` | Shadow rgb value | ~1 |
| ~80 Blade files | Standard Tailwind colors | ~60 |

### Files where JS modification is needed (optional, for 95%+)

| File | What to modify | Complexity |
|---|---|---|
| `layouts/base.blade.php` | `checkTheme()` chart colors | Low |
| `server/charts.blade.php` | ApexCharts `colors` array | Low |
| `project/shared/metrics.blade.php` | ApexCharts `colors` array | Low |
| `forms/datalist.blade.php` | Alpine.js `boxShadow` | Medium |

### Files that should NOT be themed

| File | Reason |
|---|---|
| `subscription/actions.blade.php` | Stripe brand SVG |
| `server/create.blade.php` | Hetzner brand SVG |
| `vendor/mail/html/themes/` | Email templates (different rendering context) |

---

## 11. White-Labeling Impact Analysis

White-labeling goes beyond visual theming — it replaces the product identity itself
(name, logos, links, colors, meta tags) so the instance appears as a different product.
This section assesses what Coolify exposes for white-labeling and what the coolify-enhanced
package could feasibly support.

### 11.1 Branding Surface Area in Coolify

| Category | Occurrences | Config-Driven? | Effort |
|---|---|---|---|
| App name ("Coolify") in page titles | ~50 views | Partially (`APP_NAME` env) | Low |
| App name in visible text | ~100+ views | No (hardcoded) | High |
| Logo files (SVG/PNG) | 5 files in `public/` | No | Medium |
| Favicon | 2 links in `base.blade.php` | No | Medium |
| Sidebar brand text | 1 (`navbar.blade.php:82`) | No | Low |
| OpenGraph/Twitter meta | 11 tags in `base.blade.php` | No (hardcoded URLs) | Medium |
| Email templates | 8+ files with "Coolify" | Partially (`config('app.name')`) | Medium |
| External links (coolify.io) | 15+ across views | No | High |
| GitHub links (coollabsio) | 5+ in version/pricing | No | Medium |
| Analytics (coollabs.io/plausible) | 1 in `base.blade.php` | Conditional | Low |
| Brand color (`coollabs` purple) | 20+ files via CSS classes | Yes (theme system) | Low |
| Documentation links | 10+ throughout UI | No | High |
| Sponsorship/donation links | 3 in `layout-popups.blade.php` | No | Medium |

### 11.2 What `APP_NAME` Already Controls

Coolify reads `config('app.name')` (set via `APP_NAME` env var, defaults to `'Coolify'`)
in several places:

- **Email footers:** `components/emails/footer.blade.php` — `{{ config('app.name') }}`
- **Email layouts:** `vendor/mail/html/layout.blade.php` — `{{ config('app.name') }}`
- **Email copyright:** `vendor/mail/html/message.blade.php` — `© {{ date('Y') }} {{ config('app.name') }}`
- **Session/cache prefixes:** `config/session.php`, `config/cache.php` — use app name as prefix
- **Some page titles:** Use `$title ?? 'Coolify'` fallback pattern

**NOT controlled by `APP_NAME`:**
- Sidebar brand text (hardcoded `<a>Coolify</a>`)
- Page title suffix (hardcoded `'Coolify'` in base layout)
- OpenGraph/Twitter meta tags (hardcoded `'Coolify'` and `coolify.io` URLs)
- Documentation links (hardcoded `coolify.io/docs/...`)
- Version component (links to `github.com/coollabsio/coolify/releases`)
- Analytics script (conditional on `config('app.name') == 'Coolify Cloud'`)

### 11.3 White-Label Tiers

#### Tier 1: "Quick Rebrand" (1-2 hours, config + theme only)

What coolify-enhanced can do today without any new overlays:

| Element | Approach | Files |
|---|---|---|
| Brand color | Apply a custom theme (TailAdmin, Metronic, etc.) | Theme CSS |
| App name in emails | Set `APP_NAME` env var | `.env` only |
| Font | Theme with self-hosted WOFF2 | Theme CSS |
| Color palette | Theme token override | Theme CSS |

**Coverage:** ~30%. Colors and email footers are rebranded, but the UI still says
"Coolify" everywhere and links to coolify.io.

#### Tier 2: "Visual Rebrand" (4-6 hours, theme + overlays)

Add overlay files to replace visible branding elements:

| Element | Approach | New Overlay |
|---|---|---|
| Sidebar brand text | Overlay `navbar.blade.php` | Yes (1 file) |
| Page title suffix | Overlay `base.blade.php` (already overlaid) | Update existing |
| Favicon | Replace files in `public/` via Dockerfile | Dockerfile |
| Logo files | Replace files in `public/` via Dockerfile | Dockerfile |
| OpenGraph meta | Update base layout overlay | Update existing |
| Settings subtitle | Overlay `settings/navbar.blade.php` (already overlaid) | Update existing |

**Coverage:** ~60%. The UI shows the custom brand name, logo, and colors. External
links still point to coolify.io/docs.

#### Tier 3: "Full White-Label" (8-12 hours, significant overlay work)

Replace all remaining brand references:

| Element | Approach | Complexity |
|---|---|---|
| Documentation links | Configurable base URL (`WHITELABEL_DOCS_URL`) | Medium |
| GitHub release links | Configurable or remove version component | Medium |
| Sponsorship popup | Overlay `layout-popups.blade.php` to remove/replace | Medium |
| Email templates (body text) | Overlay 8+ email views | High |
| Analytics script | Already conditional; disable for non-Coolify | Low |
| Pricing/subscription pages | Overlay pricing components | High |
| Error pages | Overlay `errors/400.blade.php`, `errors/500.blade.php` | Low |

**Coverage:** ~90%. Remaining 10% is in edge cases (boarding wizard text, inline
help strings mentioning "Coolify" in Livewire component PHP code).

#### Tier 4: "Complete OEM" (20+ hours, deep integration)

Full product identity replacement including:
- PHP source strings mentioning "Coolify" in error messages, notifications, job logs
- API response strings
- Artisan command output
- Database seed/default values
- All email template body copy

**Coverage:** ~99%. This level requires maintaining a comprehensive set of overlay
files that must be updated with each Coolify release.

### 11.4 Recommended White-Label Architecture

If white-labeling is pursued, the cleanest approach is a configuration-driven system:

```php
// config/coolify-enhanced.php
'whitelabel' => [
    'enabled' => env('COOLIFY_WHITELABEL', false),
    'brand_name' => env('COOLIFY_BRAND_NAME', 'Coolify'),
    'brand_logo' => env('COOLIFY_BRAND_LOGO', null),      // path relative to public/
    'brand_favicon' => env('COOLIFY_BRAND_FAVICON', null),
    'docs_url' => env('COOLIFY_DOCS_URL', 'https://coolify.io/docs'),
    'support_url' => env('COOLIFY_SUPPORT_URL', null),
    'hide_sponsorship' => env('COOLIFY_HIDE_SPONSORSHIP', false),
    'hide_version_link' => env('COOLIFY_HIDE_VERSION_LINK', false),
    'og_image' => env('COOLIFY_OG_IMAGE', null),
],
```

This way:
- The `base.blade.php` overlay reads `config('coolify-enhanced.whitelabel.brand_name')`
  instead of hardcoding 'Coolify'
- Logo/favicon paths are configurable without file replacement
- Documentation links use `config('coolify-enhanced.whitelabel.docs_url')` as base
- Sponsorship popup is conditionally hidden
- All via environment variables — no code changes per deployment

**Overlay files needed:** 3-4 (base layout, navbar, settings navbar, layout-popups).
Most already exist in coolify-enhanced for other features.

### 11.5 White-Label vs Theming Interaction

| Concern | Theming | White-Label | Both |
|---|---|---|---|
| Brand color | Theme CSS | -- | Theme handles it |
| Brand name | -- | Config/overlay | Config drives overlay |
| Logo | -- | Config/Dockerfile | Independent |
| Font | Theme CSS | -- | Theme handles it |
| Page titles | -- | Config/overlay | `brand_name` in title |
| Meta tags | -- | Config/overlay | `brand_name` + `og_image` |
| Doc links | -- | Config/overlay | `docs_url` config |
| Email branding | -- | `APP_NAME` env | Already works |

The two systems are complementary: theming handles visual identity (colors, fonts,
shadows), white-labeling handles product identity (name, logos, links). A fully
rebranded instance uses both.

### 11.6 Maintenance Burden

| Tier | Overlay files | Maintenance per Coolify release |
|---|---|---|
| Tier 1 | 0 new | None |
| Tier 2 | 0 new (update existing) | ~30 min |
| Tier 3 | 3-5 new | ~1-2 hours |
| Tier 4 | 10-15 new | ~3-5 hours |

**Recommendation:** Implement Tier 2 with the configuration-driven architecture
from 11.4. This provides meaningful white-labeling with minimal overlay maintenance,
and the config approach scales to Tier 3 when needed.

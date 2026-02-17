# Plan: Custom GitHub Template Sources for Coolify Enhanced

## Research Summary

### How Coolify's Template System Works

1. **346 YAML template files** live in `templates/compose/` with metadata headers (comments) and standard docker-compose content
2. **`artisan generate:services`** compiles them into `templates/service-templates-latest.json` — a JSON file where each template key maps to `{documentation, slogan, compose (base64), tags, category, logo, minversion, port?, envs?}`
3. **`get_service_templates()`** (`bootstrap/helpers/shared.php:524`) loads the JSON file at runtime and returns a `Collection`
4. **`Select.php::loadServices()`** calls `get_service_templates()`, adds logo URLs, and passes data to an Alpine.js UI with search/category filtering
5. **`Create.php::mount()`** receives a `one-click-service-{name}` type, looks up the template in the collection, base64-decodes the compose, creates a `Service` model with `docker_compose_raw`, creates env vars, calls `$service->parse()`, then redirects

### Critical Safety Finding: Templates Are Write-Once

After a service is created from a template, the `docker_compose_raw` is stored in the database. **No runtime operations** (start, stop, restart, redeploy) ever re-read the template. The `service_type` field is informational only. This means:
- Removing a template source leaves all deployed services fully operational
- Uninstalling the addon has zero impact on running services created from custom templates

## Architecture Design

### Core Approach: Override `get_service_templates()` via Helper Overlay

The cleanest approach is to:
1. **Store custom template sources** in a new database table (`custom_template_sources`)
2. **Cache fetched templates** as a merged JSON file on disk at `/data/coolify/custom-templates/merged-templates.json`
3. **Override `shared.php`** helper file to make `get_service_templates()` merge Coolify's built-in templates with our cached custom templates
4. **Provide a Settings UI** (new "Templates" tab) to manage sources, refresh, and preview

### Why This Approach

- `get_service_templates()` is the **single entry point** for all template consumers (UI, API, creation flow)
- Overriding this one function automatically makes custom templates available everywhere — no need to modify Select.php, Create.php, or the API controller
- The merged JSON cache means no GitHub API calls during template selection (fast UX)
- Overlay approach matches existing patterns in coolify-enhanced (DatabaseBackupJob, databases.php, etc.)

### Alternative Considered: Middleware Injection

Injecting templates via JavaScript/middleware was rejected because:
- The `loadServices()` Livewire method returns data to Alpine.js — you can't inject into that data flow from middleware
- The `Create.php::mount()` method does the actual template lookup — it must find our templates in `get_service_templates()`

## Detailed Implementation Plan

### 1. Database Migration: `custom_template_sources` Table

```
Schema:
  id                  - bigint primary key
  uuid                - string unique (for URLs)
  name                - string (display name, e.g., "My Company Templates")
  repository_url      - string (e.g., "https://github.com/org/templates")
  branch              - string default 'main'
  folder_path         - string default 'templates/compose' (path within repo to YAML files)
  auth_token          - text encrypted nullable (GitHub PAT for private repos)
  enabled             - boolean default true
  last_synced_at      - timestamp nullable
  last_sync_status    - string nullable ('success', 'failed', 'syncing')
  last_sync_error     - text nullable
  template_count      - integer default 0
  created_at          - timestamp
  updated_at          - timestamp
```

**File**: `database/migrations/xxxx_create_custom_template_sources_table.php`

### 2. Model: `CustomTemplateSource`

**File**: `src/Models/CustomTemplateSource.php`

- Encrypted cast for `auth_token`
- Methods: `sync()`, `getTemplates()`, `getCacheFilePath()`
- Cache location: `/data/coolify/custom-templates/{source-uuid}/templates.json`

### 3. Service: `TemplateSourceService`

**File**: `src/Services/TemplateSourceService.php`

Handles the core logic:

**`syncSource(CustomTemplateSource $source)`**:
1. Use GitHub API to list files in `{repo}/{branch}/{folder_path}` (supports `contents` API or `git/trees` for large directories)
2. For each `.yaml`/`.yml` file, download raw content
3. Parse each file using the same logic as Coolify's `Generate/Services.php::processFile()`:
   - Extract metadata headers (`# key: value`)
   - Parse YAML via `Symfony\Component\Yaml\Yaml::parse()`
   - Base64-encode the compose content
   - Validate the compose structure (must have `services` section)
   - Run `validateDockerComposeForInjection()` for security
4. Build a JSON collection keyed by filename (without extension)
5. **Prefix template keys** with source identifier to avoid collisions (e.g., `custom:{source-uuid}:{template-name}`)
   - Actually, simpler: prefix with a human-readable source slug. If a custom template has the same name as a built-in one, the custom one does NOT override — it gets a suffix like `myapp (My Company Templates)`
6. Save to `/data/coolify/custom-templates/{source-uuid}/templates.json`
7. Update `last_synced_at`, `last_sync_status`, `template_count`

**`getMergedTemplates()`**:
1. Load Coolify's built-in templates via `File::get(base_path('templates/...'))` (the original logic)
2. For each enabled source, load its cached `templates.json`
3. Merge all collections, handling name collisions by appending source name
4. Return merged collection

**`validateTemplate(array $template)`**:
- Ensure `services` section exists in compose
- Run injection validation
- Check for required metadata

**GitHub API approach**:
- Use GitHub's Contents API: `GET /repos/{owner}/{repo}/contents/{path}?ref={branch}`
- For directories with >1000 files, fall back to Trees API: `GET /repos/{owner}/{repo}/git/trees/{branch}?recursive=1`
- Support both github.com and GitHub Enterprise (custom base URL)
- Auth via `Authorization: Bearer {token}` header (optional, for private repos)
- Rate limit handling with retry logic

### 4. Override: `shared.php` (Helper Overlay)

**File**: `src/Overrides/Helpers/shared.php`

Override the `get_service_templates()` function. The new version:

```php
function get_service_templates(bool $force = false): Collection
{
    // Load Coolify's built-in templates (original logic)
    if ($force) {
        try {
            $response = Http::retry(3, 1000)->get(config('constants.services.official'));
            if ($response->failed()) {
                $builtIn = collect([]);
            } else {
                $builtIn = collect($response->json());
            }
        } catch (\Throwable) {
            $services = File::get(base_path('templates/' . config('constants.services.file_name')));
            $builtIn = collect(json_decode($services));
        }
    } else {
        $services = File::get(base_path('templates/' . config('constants.services.file_name')));
        $builtIn = collect(json_decode($services));
    }

    // If coolify-enhanced is enabled, merge custom template sources
    if (config('coolify-enhanced.enabled', false)) {
        try {
            $custom = \AmirhMoradi\CoolifyEnhanced\Services\TemplateSourceService::getCachedCustomTemplates();
            $builtIn = $builtIn->merge($custom);
        } catch (\Throwable $e) {
            // Log but don't break — built-in templates still work
            \Log::warning('Failed to load custom templates: ' . $e->getMessage());
        }
    }

    return $builtIn->sortKeys();
}
```

This is the **only Coolify file we need to modify** for the template loading to work. Everything downstream (Select.php, Create.php, API) automatically picks up the merged templates.

### 5. Livewire Component: `CustomTemplateSources`

**File**: `src/Livewire/CustomTemplateSources.php`

Settings page component for managing template sources. Features:

- **List view**: Shows all configured sources with name, repo URL, template count, last sync time, status
- **Add form**: Repository URL, branch, folder path, optional auth token, name
- **Per-source actions**: Sync (refresh), Edit, Delete, Enable/Disable toggle
- **Sync all button**: Refresh all sources at once
- **Template preview**: Expandable list showing which templates each source provides
- **Validation**: Tests GitHub API access on save (catches bad URLs/tokens early)

### 6. Blade View: `custom-template-sources.blade.php`

**File**: `resources/views/livewire/custom-template-sources.blade.php`

Uses Coolify's native form components (`<x-forms.input>`, `<x-forms.button>`, `<x-forms.checkbox>`):

```
┌─────────────────────────────────────────────────────┐
│ Custom Template Sources                             │
│                                                     │
│ Add external GitHub repositories containing         │
│ docker-compose templates for one-click services.    │
│                                                     │
│ [+ Add Source]  [Sync All]                         │
│                                                     │
│ ┌─────────────────────────────────────────────────┐ │
│ │ ☑ My Company Templates                          │ │
│ │   github.com/acme/coolify-templates             │ │
│ │   Branch: main | Path: templates/               │ │
│ │   12 templates | Last synced: 2 hours ago ✓     │ │
│ │   [Sync] [Edit] [Delete]  [▼ Show Templates]   │ │
│ └─────────────────────────────────────────────────┘ │
│                                                     │
│ ┌─────────────────────────────────────────────────┐ │
│ │ ☑ Community Templates                           │ │
│ │   github.com/community/awesome-coolify          │ │
│ │   Branch: main | Path: compose/                 │ │
│ │   8 templates | Last synced: 1 day ago ✓        │ │
│ │   [Sync] [Edit] [Delete]  [▼ Show Templates]   │ │
│ └─────────────────────────────────────────────────┘ │
│                                                     │
│ Add New Source                                      │
│ ┌─────────────────────────────────────────────────┐ │
│ │ Name: [________________________]                │ │
│ │ Repository URL: [________________________]      │ │
│ │ Branch: [main___]                               │ │
│ │ Folder Path: [templates/compose___]             │ │
│ │ Auth Token (optional): [________________________]│ │
│ │                                                 │ │
│ │ [Save & Sync]                                   │ │
│ └─────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

### 7. Settings Navbar Update

**File**: `src/Overrides/Views/components/settings/navbar.blade.php` (already overridden)

Add a "Templates" tab to the existing settings navbar, next to Configuration | Backup | Restore | Email | OAuth.

### 8. Route Registration

**File**: `routes/web.php`

```php
Route::get('/settings/custom-templates', CustomTemplateSources::class)
    ->name('settings.custom-templates');
```

### 9. Job: `SyncTemplateSourceJob`

**File**: `src/Jobs/SyncTemplateSourceJob.php`

Queued job for background sync:
- Called when user clicks "Sync" or "Sync All"
- Also dispatched on a schedule (configurable, default: every 6 hours)
- Updates source status to 'syncing' → 'success'/'failed'
- Dispatches Livewire events for real-time UI updates

### 10. API Endpoints (Optional)

**File**: `src/Http/Controllers/Api/CustomTemplateSourceController.php`

REST API for managing template sources programmatically:
- `GET /api/v1/enhanced/template-sources` — List sources
- `POST /api/v1/enhanced/template-sources` — Add source
- `PUT /api/v1/enhanced/template-sources/{uuid}` — Update source
- `DELETE /api/v1/enhanced/template-sources/{uuid}` — Delete source
- `POST /api/v1/enhanced/template-sources/{uuid}/sync` — Trigger sync
- `POST /api/v1/enhanced/template-sources/sync-all` — Sync all

### 11. Dockerfile Updates

Add to `docker/Dockerfile`:

```dockerfile
# Override shared.php helper to merge custom template sources
COPY --chown=www-data:www-data src/Overrides/Helpers/shared.php \
    /var/www/html/bootstrap/helpers/shared.php
```

### 12. Configuration

Add to `config/coolify-enhanced.php`:

```php
'custom_templates' => [
    // Auto-sync interval (cron expression)
    'sync_frequency' => env('COOLIFY_TEMPLATE_SYNC_FREQUENCY', '0 */6 * * *'),

    // Cache directory for fetched templates
    'cache_dir' => env('COOLIFY_TEMPLATE_CACHE_DIR', '/data/coolify/custom-templates'),

    // Maximum templates per source (safety limit)
    'max_templates_per_source' => 500,

    // GitHub API timeout in seconds
    'github_timeout' => 30,
],
```

## Template Name Collision Strategy

When a custom template has the same name as a built-in Coolify template:
- **The built-in template takes precedence** (safety first)
- Custom template gets a disambiguated key: `{name}-{source-slug}` (e.g., `wordpress-acme-templates`)
- In the UI, the source name is shown as a small badge/label on custom templates so users know where it came from

## Logo Handling for Custom Templates

Custom templates can specify logos in two ways:
1. **URL**: `# logo: https://example.com/logo.svg` — used directly
2. **Relative path**: `# logo: svgs/myapp.svg` — resolved relative to the repo (raw GitHub URL constructed)

The sync service detects absolute URLs vs relative paths and converts relative paths to raw GitHub URLs:
`https://raw.githubusercontent.com/{owner}/{repo}/{branch}/{folder}/../{logo_path}`

## Revert Safety Analysis

### What happens when coolify-enhanced is uninstalled?

1. **`get_service_templates()` reverts to original** — only built-in templates appear
2. **Deployed services from custom templates continue running** — their `docker_compose_raw` is in the database, not referenced from templates
3. **Service restart/redeploy works** — uses stored compose, not template lookup
4. **The `service_type` field** stores the custom template name but is never used for operations
5. **Database tables** (`custom_template_sources`) become orphaned but cause no errors
6. **Cache files** in `/data/coolify/custom-templates/` become orphaned but harmless

### What happens when addon is disabled (COOLIFY_ENHANCED=false)?

1. The override `shared.php` checks `config('coolify-enhanced.enabled')` before merging custom templates
2. Falls back to built-in templates only
3. All existing services unaffected

## File Summary

| File | Type | Purpose |
|------|------|---------|
| `database/migrations/xxxx_create_custom_template_sources_table.php` | New | Database schema |
| `src/Models/CustomTemplateSource.php` | New | Eloquent model |
| `src/Services/TemplateSourceService.php` | New | GitHub fetch + template parsing |
| `src/Jobs/SyncTemplateSourceJob.php` | New | Background sync job |
| `src/Livewire/CustomTemplateSources.php` | New | Settings page component |
| `resources/views/livewire/custom-template-sources.blade.php` | New | Settings page view |
| `src/Overrides/Helpers/shared.php` | New overlay | Override get_service_templates() |
| `src/Overrides/Views/components/settings/navbar.blade.php` | Modify | Add Templates tab |
| `src/Http/Controllers/Api/CustomTemplateSourceController.php` | New | REST API |
| `routes/web.php` | Modify | Add template settings route |
| `routes/api.php` | Modify | Add API routes |
| `config/coolify-enhanced.php` | Modify | Add template config |
| `docker/Dockerfile` | Modify | Add shared.php overlay |
| `src/CoolifyEnhancedServiceProvider.php` | Modify | Register component + scheduler |

## Implementation Order

1. Migration + Model
2. TemplateSourceService (core parsing + GitHub fetch logic)
3. Override shared.php (the integration point)
4. Dockerfile update
5. Livewire component + view
6. Settings navbar update
7. Route registration
8. Service provider updates
9. SyncTemplateSourceJob
10. API endpoints
11. Testing + documentation updates

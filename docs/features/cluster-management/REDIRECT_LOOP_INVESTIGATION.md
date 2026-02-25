# Cluster Management — "Too Many Redirects" Investigation

**Symptom:** When activating Cluster management (`COOLIFY_CLUSTER_MANAGEMENT=true`), Coolify does not load pages and the browser shows "too many redirects". Reproduced on a node that is **not** part of a Docker Swarm cluster.

---

## Investigation Plan (Phase 1 — Root Cause)

1. **Trace redirect sources** — Find all `redirect()`, `Redirect::`, and `abort()` in cluster-related code and middleware.
2. **Check route registration order** — Coolify’s `routes/web.php` ends with a catch-all; confirm whether package routes are registered before or after it.
3. **Check middleware** — Ensure no middleware redirects to `/clusters` or triggers a loop when cluster management is enabled.
4. **Check Livewire components** — Ensure ClusterList/ClusterDashboard do not redirect in a way that creates a cycle.

---

## Findings

### 1. Coolify’s catch-all route

Coolify’s `docs/coolify-source/routes/web.php` ends with:

```php
Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);  // HOME = '/'
    }
    return redirect()->route('login');
})->where('any', '.*');
```

This route matches **any** path (e.g. `clusters`, `cluster/abc-123`). For an authenticated user it always redirects to `/` (dashboard).

### 2. Package route registration order

- Package web routes are loaded in `CoolifyEnhancedServiceProvider::boot()` via `loadRoutesFrom(__DIR__.'/../routes/web.php')`.
- Laravel registers routes in the order providers call `loadRoutesFrom()` / `Route::get()`.
- **Provider boot order** depends on how the app lists providers: package (discovered) providers can boot before or after `App\Providers\RouteServiceProvider` depending on config and Laravel version.
- If **Coolify’s routes (including the catch-all) are registered before** our package’s routes, then:
  - `GET /clusters` and `GET /cluster/{uuid}` are matched by the catch-all first.
  - The handler redirects to `/`.
  - Result: every visit to `/clusters` (or a cluster detail page) is redirected to dashboard. If anything then sends the user back to `/clusters` (e.g. sidebar link, default start page, or front-end logic), a **redirect loop** can occur and the browser reports "too many redirects".

### 3. No redirect-to-clusters in our code

- Grep and code review show **no** middleware or Livewire code that redirects to `/clusters` when cluster management is enabled.
- `InjectPermissionsUI` only injects HTML (including a link to `/clusters`); it does not issue redirects.
- `ClusterList` and `ClusterDashboard` use `abort(404)` or `firstOrFail()` when config/team/cluster checks fail; they do not redirect in a loop.

### 4. Conclusion (root cause)

- **Root cause:** When the package’s web routes are registered **after** Coolify’s catch-all, `GET /clusters` and `GET /cluster/{uuid}` are handled by the catch-all and redirect to `/`. This can lead to a redirect loop (e.g. dashboard → link to clusters → catch-all → dashboard → …), especially when cluster management is enabled and the UI or default behavior points to cluster URLs.
- **Fix:** Register the package’s **web routes during `register()`** (when the addon is enabled) so they are added to the router **before** any provider’s `boot()` runs. That guarantees our routes are registered before `RouteServiceProvider::boot()` adds Coolify’s catch-all, so `GET /clusters` and `GET /cluster/{uuid}` match our routes and no longer hit the catch-all.

---

## Fix Applied

- In `CoolifyEnhancedServiceProvider::register()`: if `config('coolify-enhanced.enabled')` is true, call `$this->loadRoutesFrom(__DIR__.'/../routes/web.php')`.
- In `CoolifyEnhancedServiceProvider::boot()`: remove the duplicate `loadRoutesFrom` for `web.php`; keep loading `api.php` in `boot()`.

Result: web routes (including `clusters` and `cluster/{cluster_uuid}`) are registered during `register()`, before any provider (including `RouteServiceProvider`) runs `boot()`, so they take precedence over the catch-all.

---

## References

- Coolify `routes/web.php` (catch-all at end of file).
- Coolify `app/Providers/RouteServiceProvider.php` (`HOME = '/'`, loads `routes/web.php` in `boot()`).
- Package `routes/web.php` (defines `GET clusters`, `GET cluster/{cluster_uuid}`).
- Systematic debugging: root cause identified before implementing fix; single, minimal change applied.

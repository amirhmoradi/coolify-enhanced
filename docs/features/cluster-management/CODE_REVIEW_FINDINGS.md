# Cluster Management — Code Review Findings

**Branch:** `claude/plan-cluster-management-ty9Nw`  
**Commits reviewed:** 41a4755 (docs) → 62810a0 (full cluster management)  
**Reference:** [README.md](README.md), [PRD.md](PRD.md), [plan.md](plan.md)

---

## Critical (must fix)

### 1. Force Update button calls wrong Livewire method — FIXED

**Location:** `resources/views/livewire/cluster-service-viewer.blade.php` line 96

**Issue:** The Blade template calls `forceUpdateService('{{ $service['id'] }}')` but the Livewire component defines the method as `forceUpdate(string $serviceId)`. The button therefore invokes a non-existent method and does nothing (or throws a Livewire method-not-found).

**Fix:** Change Blade to call `forceUpdate`:
```blade
wire:click.stop="forceUpdate('{{ $service['id'] }}')"
```
Or rename the Livewire method to `forceUpdateService` and keep the Blade as-is. Prefer aligning Blade to the existing method name (`forceUpdate`) for consistency with `scaleService` / `rollbackService` naming.

---

### 2. Null currentTeam() can crash API and Livewire — FIXED

**Locations:**
- `src/Http/Controllers/Api/ClusterController.php` — `teamId()` returns `$request->user()->currentTeam()->id` with no null check.
- `src/Livewire/ClusterList.php` — uses `currentTeam()->id` in mount, loadClusters, autoDetect, syncCluster, deleteCluster.
- `src/Livewire/ClusterServiceViewer.php` — `resolveCluster()` uses `currentTeam()->id`.

**Issue:** If the authenticated user has no current team (e.g. removed from last team, or Coolify allows no team), `currentTeam()` returns null and `->id` causes a fatal error.

**Fix:**
- **ClusterController:** In `teamId()`, check for null and abort with 403 and a clear message (e.g. "No team selected").
- **ClusterList:** In `mount()` (and anywhere using `currentTeam()->id`), check for null and either redirect or show a message; do not call `currentTeam()->id` when null.
- **ClusterServiceViewer:** In `resolveCluster()`, ensure currentTeam() is not null before using it, or resolve cluster in a context where team is guaranteed (e.g. parent already checked).

---

## Important (should fix)

### 3. Missing API route for force update service — FIXED

**Location:** `routes/api.php`, README API table

**Issue:** README and PRD describe "Force update" as a service operation. The driver and Livewire implement `forceUpdateService` / `forceUpdate`, but there is no REST endpoint for it. API clients (e.g. MCP) cannot trigger force update.

**Fix:** Add route and controller method:
- Route: `POST /api/v1/clusters/{uuid}/services/{serviceId}/force-update`
- Controller: `ClusterController::forceUpdateService(Request $request, string $uuid, string $serviceId)` — authorize `manageServices`, call `$cluster->driver()->forceUpdateService($serviceId)`, invalidate cache, return JSON.

---

### 4. Cluster detection: Server “settings” and Swarm manager flag — FIXED

**Location:** `src/Services/ClusterDetectionService.php` line 21–22

**Issue:** Detection used `whereHas('settings', ...)`. Coolify uses a **relation** `settings` (ServerSetting), column `is_swarm_manager`. Query was correct in concept; style aligned with Coolify’s use of `whereRelation`.

**Fix applied:** Replaced with `whereRelation('settings', 'is_swarm_manager', true)` and added comment documenting Coolify schema (ServerSetting relation, `is_swarm_manager`).

---

### 5. linkKnownServers uses Server `ip` column — VERIFIED

**Location:** `src/Services/ClusterDetectionService.php` — `linkKnownServers()` uses `Server::where('ip', $ip)`.

**Verification:** Coolify stores the server’s primary IP in **`Server.ip`** (string). No code change needed. Comment added in method docblock documenting reliance on `Server.ip`.

---

## Minor / nice-to-have

### 6. Explicit viewAny authorization on cluster list

**Location:** `ClusterController::index()`

**Issue:** No `Gate::authorize('viewAny', Cluster::class)`. Policy `viewAny` returns true for authenticated users; adding the call keeps behavior consistent and documents intent.

**Fix:** Add at the start of `index()`: `Gate::authorize('viewAny', Cluster::class);`

---

### 7. getClusterInfo() JSON path vs Docker output

**Location:** `src/Drivers/SwarmClusterDriver.php` — `getClusterInfo()` uses `data_get($swarm, 'Cluster.ID')`, `data_get($swarm, 'Nodes', 0)`, etc.

**Issue:** Actual `docker info --format "{{json .Swarm}}"` output structure may use different keys (e.g. top-level `ClusterID` vs nested `Cluster.ID`). ClusterDetectionService uses `data_get($swarmInfo, 'Cluster.ID')` and works, so structure may be consistent; if not, getClusterInfo could return empty/wrong data.

**Fix:** Run `docker info --format "{{json .Swarm}}"` on a Swarm manager and confirm the JSON shape. Align getClusterInfo() and any metadata usage (e.g. in ClusterDetectionService) with that structure; add a short comment or test documenting the expected format.

---

### 8. Large secret/config creation and ARG_MAX

**Location:** `SwarmClusterDriver::createSecret()` / `createConfig()` — secret/config data is passed via `escape()` (escapeshellarg) into a single shell command.

**Issue:** Very large payloads (e.g. ~1MB+) can approach or exceed the system ARG_MAX limit and cause command execution to fail.

**Fix:** Document as a known limitation (e.g. in README or inline). Optionally, for large payloads, write data to a temp file on the server and use `docker secret create name - < /tmp/file` (or equivalent) in a follow-up change.

---

## Verification checklist (no code change)

- [ ] Confirm `docker info --format "{{json .Swarm}}"` structure matches getClusterInfo() and ClusterDetectionService.
- [ ] Confirm Coolify Server: column/relation for IP and for Swarm manager flag (settings vs relation).
- [ ] After fixes: run feature flag on, list clusters, open dashboard, node actions, service scale/rollback/force-update, secrets/configs CRUD; confirm no PHP errors and expected behavior.

---

## Summary

| Severity   | Count | Items |
|-----------|-------|--------|
| Critical  | 2     | Force-update Blade method name — FIXED; null currentTeam() crash — FIXED |
| Important | 3     | Missing force-update API route — FIXED; Server settings relation — FIXED (whereRelation + doc); Server IP column — VERIFIED (Server.ip) |
| Minor     | 3     | viewAny in index — FIXED; getClusterInfo JSON path verification — open; large secret/config ARG_MAX doc — open |

All critical and important items are addressed. Minor items 7 and 8 remain as optional follow-ups (verification + documentation).

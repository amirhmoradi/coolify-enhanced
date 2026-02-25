# Cluster Management — Validation Action Plan (Redirect Loop Fix)

Use this checklist to validate the "too many redirects" fix when Cluster management is enabled on a node that is **not** part of a Docker Swarm cluster.

---

## Prerequisites

- Coolify instance with coolify-enhanced addon installed.
- **Node not part of a Swarm cluster** (single-node or standalone Docker).
- `COOLIFY_ENHANCED=true` and `COOLIFY_CLUSTER_MANAGEMENT=true` in Coolify `.env`.

---

## 1. Confirm the fix is deployed

- [ ] Code change is applied: web routes are loaded in `CoolifyEnhancedServiceProvider::register()` when the addon is enabled (see [REDIRECT_LOOP_INVESTIGATION.md](REDIRECT_LOOP_INVESTIGATION.md)).
- [ ] Rebuild/restart the Coolify container (or run `upgrade.sh`) so the updated provider is active.

---

## 2. Basic page load (no redirect loop)

- [ ] Open Coolify in the browser (e.g. `https://your-coolify/ui`).
- [ ] Log in if required.
- [ ] **Expected:** Dashboard (or default home) loads; **no** "too many redirects" or endless redirect.
- [ ] Navigate to **Servers**, **Projects**, **Settings**.
- [ ] **Expected:** Each page loads normally; no redirect loop.

---

## 3. Cluster list page (no cluster / no Swarm)

- [ ] In the sidebar, click **Clusters** (link appears when cluster management is enabled; if you have no clusters yet, the link may still be present depending on sidebar injection logic).
- [ ] Or go directly to: `https://your-coolify/ui/clusters`.
- [ ] **Expected:** The Clusters list page loads. It may show "No clusters found" and suggest using "Auto-detect from Servers" — that is correct on a non-Swarm node.
- [ ] **Failure mode (before fix):** Browser shows "too many redirects" or immediately redirects back to dashboard.

---

## 4. No cluster created (empty list)

- [ ] On the Clusters page, click **Auto-detect from Servers**.
- [ ] **Expected:** Message like "No new Swarm clusters detected" (or similar); no redirect, no 5xx.
- [ ] Page still shows empty list; no redirect loop.

---

## 5. Optional: with a Swarm manager

If you have (or add) a server that is a Swarm manager:

- [ ] Mark the server as Swarm Manager in Coolify and run **Auto-detect from Servers**.
- [ ] **Expected:** At least one cluster appears; you can open it and see the cluster dashboard (overview, nodes, services, etc.) without redirect loops.

---

## 6. Regression: other addon routes

- [ ] Visit other coolify-enhanced routes, e.g.:
  - Settings → Restore Backup
  - Settings → Custom Templates
  - Settings → Networks
  - A server → Resource Backups
- [ ] **Expected:** All load normally; no new redirects or 404s caused by the route-order change.

---

## 7. Clear cache (if needed)

If anything still misbehaves:

- [ ] Clear Laravel config and route caches (inside the Coolify container):
  - `php artisan config:clear`
  - `php artisan route:clear`
- [ ] Hard-refresh the browser or use a private window to avoid stale redirects.

---

## Summary

| Check              | Expected result                          |
|--------------------|------------------------------------------|
| Dashboard & nav    | Loads; no redirect loop                  |
| `/clusters`       | Clusters list page; no redirect loop    |
| Auto-detect (no Swarm) | Message "No new Swarm clusters…"; no redirect |
| Other enhanced routes | Load normally; no regression         |

If all items pass, the redirect loop fix is validated for your environment. If any step fails, capture the exact URL, response (redirect target or error), and browser console/network log and use that to debug further (see [REDIRECT_LOOP_INVESTIGATION.md](REDIRECT_LOOP_INVESTIGATION.md)).

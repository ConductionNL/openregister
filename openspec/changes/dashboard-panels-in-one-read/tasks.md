# Tasks

## 1. Server

- [x] 1.1 `DashboardController::getPanels()` + route `GET /api/dashboard/panels`: the seven panels through their own controller methods, `{panel: {status, data}}`, admin only.

## 2. Client

- [x] 2.1 Dashboard store `fetchAllPanels()`: one request, panels kept where the per-panel reads keep them, failures logged per panel and the first rethrown; per-panel reads when a panel is already loading.
- [x] 2.2 `preload()`, `init()`'s scope watcher, `setupDashboardStoreWatchers()` and `DashboardIndex.refetchLiveWidgets()` read through it.

## 3. Verification

- [x] 3.1 PHPUnit: `DashboardControllerTest` (every panel like its own endpoint with its filters; a failing panel kept to itself; a bad date fails only the audit chart; 403 for a non-admin).
- [x] 3.2 Jest: `dashboard.preload.spec.js`, `dashboard.watchers.spec.js`, `dashboardActions.golden.spec.js` (fetchAllPanels recorded; preload sends 2 requests), `DashboardIndex.methods.spec.js`.
- [ ] 3.3 Live on the sandbox reference stack: OR Dashboard total and request count before and after, panel answers identical to the per-panel endpoints, in the PR body.

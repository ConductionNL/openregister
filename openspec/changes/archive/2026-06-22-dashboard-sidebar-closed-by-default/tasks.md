## 1. Implementation

- [x] 1.1 In `src/sidebars/dashboard/DashboardSideBar.vue`, change the `isSidebarOpen` initial value in `data()` from `true` to `false` so the dashboard sidebar renders collapsed on initial load.
- [x] 1.2 Confirm `NcAppSidebar`'s `:open="isSidebarOpen"` binding and the `@update:open` handler are unchanged, so the manual toggle still works and writes back to `isSidebarOpen`.

## 2. Verification

- [x] 2.1 Build the frontend (`npm run build`) and load the dashboard (route `/`): the right sidebar is collapsed on arrival.
- [x] 2.2 Open the sidebar via the standard NC toggle and confirm filters, system totals, and orphaned-items render and operate as before.
- [x] 2.3 Navigate to non-dashboard views (registers, schemas, search, chat, deleted, entities, audit/search trails) and confirm their sidebar defaults are unchanged.

Acceptance criteria:
- The dashboard right sidebar is collapsed on initial load of route `/`.
- The user can still open it manually; sidebar content behaves identically once open.
- No other view's sidebar default open/closed state changes.

Quality reminders:
- Frontend-only change; no PHP, schema, seed, or API changes.
- Smoke-check that opencatalogi and softwarecatalog show no regressions, since they consume OpenRegister UI.

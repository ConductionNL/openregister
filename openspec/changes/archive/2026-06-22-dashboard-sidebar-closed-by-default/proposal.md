---
kind: code
---

## Why

When a user lands on the OpenRegister dashboard, the right-hand statistics sidebar opens automatically and covers a meaningful slice of the dashboard's own content. The sidebar holds supporting filters and totals — useful on demand, but not the user's first concern on arrival. Defaulting it open pushes the primary dashboard view aside on every visit and forces a manual collapse, which is friction for the most-visited landing surface.

## What Changes

- On the dashboard view (`/`) only, the right-hand app sidebar (`DashboardSideBar.vue`) SHALL render **collapsed** on initial load instead of open.
- The standard Nextcloud sidebar toggle continues to work: the user can open the sidebar manually whenever they want.
- No change to any other view's sidebar behaviour (registers, schemas, search, chat, deleted, entities, audit/search trails) — each keeps its current default.
- No change to the per-object `CnObjectSidebar` (which is already gated on `objectSidebarState.active`).

## Capabilities

### New Capabilities
- `dashboard-sidebar-default-state`: Defines the initial open/closed state of the dashboard's right-hand app sidebar on the dashboard view, and that the manual toggle remains available.

### Modified Capabilities
<!-- No existing spec captures the dashboard sidebar's default open state, so none change. -->

## Impact

- **Code**: `src/sidebars/dashboard/DashboardSideBar.vue` — the `isSidebarOpen` initial value bound to `NcAppSidebar`'s `:open` prop.
- **APIs / backend**: none (Vue/JS only).
- **Other views**: none — `SideBars.vue` route gating and all non-dashboard sidebars are untouched.
- **Dependent apps** (opencatalogi, softwarecatalog): none — this is internal OpenRegister UI state.

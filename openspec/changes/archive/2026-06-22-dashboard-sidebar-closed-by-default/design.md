## Context

The OpenRegister right-rail sidebars are mounted at `NcContent` level via `App.vue`'s `<template #sidebar>`, which renders `src/sidebars/SideBars.vue`. `SideBars.vue` selects exactly one route-specific sidebar component by `$route.path`; for the dashboard (`/`) that component is `src/sidebars/dashboard/DashboardSideBar.vue`.

`DashboardSideBar.vue` owns its own open state locally: a `data()` field `isSidebarOpen` (currently `true`) is bound to `NcAppSidebar`'s `:open` prop, with `@update:open` writing the user's toggle back into the same field. There is no shared `navigationStore` open-state for these route sidebars — each route sidebar manages its own `:open` value. The dashboard's default-open therefore lives in exactly one place: the initial value of `isSidebarOpen`.

## Goals / Non-Goals

**Goals:**
- The dashboard right sidebar renders collapsed on initial load.
- The user can still open it manually via the standard NC sidebar toggle; `@update:open` keeps working.
- The change is the minimal, idiomatic edit to the existing local open-state field.

**Non-Goals:**
- Changing any other route sidebar's default (each keeps its own `:open` default).
- Introducing or routing through a shared store for sidebar open state.
- Persisting the user's manual open/closed choice across visits.
- Any backend, schema, or API change.

## Decisions

**Decision: flip the existing local `isSidebarOpen` initial value from `true` to `false`.**
The dashboard sidebar's `:open` prop is already two-way bound to the local `isSidebarOpen` field, with `@update:open` writing user toggles back. Changing only the initial value in `data()` makes the sidebar collapsed on first render while leaving the toggle path untouched. This is the smallest change that satisfies the spec and matches how OpenRegister already manages this sidebar's visibility (per-component local state, not a store).

- *Alternative — set `:open` default in `App.vue` or `SideBars.vue`:* rejected. Those layers don't own the dashboard sidebar's open state; the per-route sidebar does. Editing them would couple unrelated views and risk changing other sidebars' defaults.
- *Alternative — route a default through a shared `navigationStore`:* rejected as over-engineering. No such shared open-state store exists for these route sidebars; adding one for a single boolean default is disproportionate.

**Decision: reset to collapsed on each dashboard visit (do not persist the user's manual choice).**
Because the dashboard sidebar is route-mounted and its open state is component-local, leaving on `/` and returning re-initialises `isSidebarOpen` to its default. The chosen behaviour is to land collapsed each visit. Persisting the user's last choice would require a store or user-config and is out of scope (recorded as a deferred question).

## Risks / Trade-offs

- [Risk] A user who relied on the sidebar being open by default loses one-time visibility of filters/totals. → Mitigation: the toggle is unchanged and discoverable; the dashboard's main content is the primary surface, and the sidebar is supporting.
- [Risk] Future refactors might move dashboard sidebar open state into a shared store, re-introducing a default elsewhere. → Mitigation: the spec pins the observable behaviour (collapsed on initial load), independent of where the default lives.

## Migration Plan

Single-file frontend edit; no data migration. Deploy via the standard JS build. Rollback is reverting the one-line initial-value change.

## Open Questions

- Should the user's manual open/closed choice persist across dashboard visits? Provisional decision: no — reset to collapsed each visit (see Deferred Questions).

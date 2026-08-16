---
kind: code
---

## Why

`adopt-live-updates-ui` (openregister#402) wired OpenRegister's object list
and object detail views to the notify_push live-update events the backend
already emits, but explicitly deferred the dashboard: its widgets render from
a separate plain-Pinia dashboard store (custom aggregate endpoints under
`/api/dashboard/*`) and keep one-shot fetch behaviour. A user watching the
dashboard while another session creates or deletes objects sees stale counts,
charts, and audit statistics until a manual refresh. Issue openregister#410
tracks closing that gap.

## What Changes

- Dashboard page (`src/views/dashboard/DashboardIndex.vue`) holds ONE live
  collection subscription (`or-collection-{register-slug}-{schema-slug}`)
  covering all its object-CRUD-derived widgets, active only while the
  dashboard is scoped to a register+schema via the sidebar filter
  (`registerStore.registerItem` + `schemaStore.schemaItem`). The subscription
  reuses the package object store's `liveUpdatesPlugin`
  (`objectStore.subscribe(type)` — the same API #402 uses), registering the
  type first when the sidebar's own object search has not done so yet.
- Re-scope on filter change (watcher on a derived `liveCollectionType`),
  subscribe on mount, release in `beforeDestroy`. Same async-race guards as
  #402, copied verbatim: pending-type marker dedupes an in-flight same-scope
  subscribe; an epoch counter invalidates resolutions that land after a
  release (the stale handle is unsubscribed, never leaked).
- Events are refetch HINTS only. The plugin stamps
  `objectStore.liveLastEventAt` on every event; the dashboard watches it and
  — after a 750ms trailing debounce that coalesces bulk-import event bursts —
  refetches the object-CRUD-derived widget data: `fetchRegisters()` (Objects
  KPI + sidebar totals), `fetchAllChartData()` (objects-by-register/schema/
  size charts + audit-trail action chart behind the Events KPI), and
  `fetchAllStatistics()` (audit-trail stats). The debounce is needed because
  the dashboard store's `chartLoading`/`statisticsLoading` guards DROP
  concurrent fetches rather than queueing a trailing one, so an undebounced
  burst would miss the final state. No data is ever patched from an event
  payload.
- Side effect of reusing the plugin: on each event the plugin also re-runs
  `fetchCollection` for the subscribed type, keeping the object-collection
  cache (used by the dashboard sidebar's object search) warm. This is the
  documented plugin contract, not extra wiring.
- No backend changes; no new endpoints; no dependency bump
  (`@conduction/nextcloud-vue` 1.0.0-beta.206 already ships the layer).

## Widget coverage (honest partial)

Wired (refetched on collection events while scoped):

- `count-objects` KPI — object totals from `/api/dashboard`.
- `count-events` KPI — audit-trail totals/chart; every create/delete writes
  audit entries, so a collection event is a valid hint.
- `objects-by-register`, `objects-by-schema`, `objects-chart` — object-count
  charts.
- Dashboard sidebar totals/orphaned tables — render from the same
  `dashboardStore.registers` state as the Objects KPI.

Left unwired, and why:

- **Unscoped dashboard (no register+schema selected — the default view).**
  The push event dialect only defines `or-object-{uuid}` and
  `or-collection-{registerSlug}-{schemaSlug}`; there is no instance-wide or
  register-level event key, and subscribing to every register×schema pair
  would need N×M subscriptions plus slug enumeration. A backend
  wildcard/register-level event is the correct fix and is out of scope here.
  A register-only scope is unwireable for the same reason (the key needs
  both slugs).
- `count-registers`, `count-schemas` KPIs — driven by register/schema CRUD,
  which emits no push events at all.
- `count-searches`, `popular-terms` — search-trail activity; not object CRUD,
  not signalled by or-collection events.

## Capabilities

### Modified Capabilities

- `realtime-updates`: the dashboard becomes a consumer of collection events
  for its sidebar-scoped register+schema — widgets refetch on events instead
  of rendering one-shot fetches only.

## Impact

- `src/views/dashboard/DashboardIndex.vue` — subscription lifecycle, debounced
  refetch of dashboard aggregates.
- No store, API, schema, or PHP changes.

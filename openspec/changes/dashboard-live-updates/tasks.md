# Tasks — dashboard-live-updates

## 1. Architecture mapping

- [x] 1.1 Inventory the dashboard surface: `DashboardIndex.vue` (only
  `CnDashboardPage` in the app, 9 custom widgets) + `DashboardSideBar.vue`
  (register/schema scope filter + totals tables), all rendering from the
  plain-Pinia `dashboard` store (`/api/dashboard/*` aggregates) and the
  search-trail store. Classify each widget as object-CRUD-derived (wireable
  when scoped) vs register/schema-CRUD or search-trail (no event key exists)
  — documented in proposal.md.

## 2. Subscription lifecycle

- [x] 2.1 `src/views/dashboard/DashboardIndex.vue`: add
  `syncLiveSubscription()` / `releaseLiveSubscription()` managing a single
  collection subscription via `objectStore.subscribe(type)` for the derived
  `liveCollectionType` (empty unless BOTH register and schema are selected);
  register the type (`registerObjectType`) when the sidebar's object search
  has not done so yet.
- [x] 2.2 Re-scope on sidebar filter change via a watcher on
  `liveCollectionType`; subscribe on mount; release in `beforeDestroy`.
- [x] 2.3 Copy the #402 race guards verbatim: pending-type marker dedupes an
  in-flight same-scope subscribe; epoch counter invalidates resolutions
  landing after a release (stale handle immediately unsubscribed, never
  leaked).

## 3. Event-driven refetch

- [x] 3.1 Watch `objectStore.liveLastEventAt` (plugin-stamped on every event);
  while a subscription is held, schedule a refetch of the object-CRUD-derived
  widget data (`fetchRegisters` + `fetchAllChartData` + `fetchAllStatistics`)
  — events are refetch hints, payloads are never applied as data.
- [x] 3.2 Debounce: 750ms trailing timer coalesces bulk-import event bursts
  into one refetch (the nc-vue plugin dedupes in-flight fetches but the
  dashboard store's loading guards DROP concurrent fetches, so a trailing
  debounce is required to not miss the post-burst state). Timer is cleared
  and epoch-guarded on release.

## 4. Out of scope / deferred

- [x] 4.1 Unscoped ("all registers") dashboard, register-only scope,
  `count-registers`/`count-schemas`, and search-trail widgets deliberately
  NOT wired — no matching push event key exists in the dialect (see
  proposal.md, Widget coverage).

## 5. Verification

- [x] 5.1 `npm run lint` passes on the touched files.
- [x] 5.2 `npm test` (jest) passes.
- [x] 5.3 `npm run build` (webpack production) succeeds.
- [ ] 5.4 Live two-session verification on a notify_push-enabled instance
  (deferred — requires a deployed instance; not part of this change's CI).

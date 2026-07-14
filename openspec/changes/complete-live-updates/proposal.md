---
kind: code
---

## Why

The `add-live-updates` change (archived 2026-06-14) shipped the notify_push
transport but left task 6 half-wired: `ImportService` toggles
`NotifyPushListener::setBatchMode(true)` around its two bulk-save call sites,
then calls `setBatchMode(false)` — which **clears the accumulated collection
events without ever emitting them** (`flushBatch()` was implemented on the
listener but never called; the call sites carried a
`@todo add-live-updates/task-6`). Net effect: bulk imports emit ZERO push
events, so connected clients never learn an import happened, while single-object
saves push fine.

Separately, the repo carries a second, older realtime write path from the
archived `2026-05-01-realtime-updates` change: a DB-backed cursor-polling
change feed (`RealtimeService` + `RealtimeEventListener` +
`RealtimeController` at `/api/realtime/events` + `/api/realtime/cursor` +
daily `RealtimeEventRetentionJob` + `openregister_realtime_events` table).
It writes a CloudEvent row on EVERY object change (4 lifecycle events) and has
**zero consumers** — verified by grepping the entire apps-extra workspace plus
this repo's `src/` and `website/` for `realtime/events`, `RealtimeService`,
`RealtimeController`, and `api/realtime`: every hit is openregister's own
implementation, its tests, archived openspec docs, or coverage reports. It is
pure write amplification on the hot object-save path.

## What Changes

- **Fix the import batch flush (Task 1).** `ImportService` gains a lazily
  resolved DI container reference and a `flushNotifyPushBatch()` helper. Both
  bulk-save call sites (`processSpreadsheetBatch`, `processCsvSheet`) now call
  the helper in their `finally` block — BEFORE `setBatchMode(false)` — so on
  import completion (success or failure; partial saves still happened) exactly
  one deduplicated `or-collection-{register-slug}-{schema-slug}` broadcast is
  pushed per affected pair. The flush keeps the archived change's design
  decisions: collection events are broadcast without per-user targeting
  (payload is slugs + action only; clients refetch through the RBAC-filtered
  REST API), and everything soft-fails when notify_push is absent (no
  accumulation happens in that case, so the flush is a silent no-op; a resolve
  failure with pending events logs at most one DEBUG entry).
  `NotifyPushListener::flushBatch()` drops its never-used `PermissionHandler`
  parameter and gains a `hasBatchedCollections()` guard accessor.

- **Remove the orphaned RealtimeService write path (Task 2).** Deletes
  `RealtimeService`, `RealtimeController` (+ its two routes),
  `RealtimeEventListener` (+ its four registrations in `Application.php`),
  `RealtimeEvent`/`RealtimeEventMapper`, `RealtimeEventRetentionJob` (+ its
  `info.xml` registration), the table-creating migration
  (`Version1Date20260430000000` — fresh installs no longer create the dead
  table), and the two tests covering the subsystem. The existing
  `openregister_realtime_events` table on already-installed instances is
  intentionally **left in place** (no destructive migration); dropping it is a
  follow-up. Spec deltas REMOVE the requirements that mandated this subsystem
  from `realtime-updates`, `production-observability`, and
  `retention-management`.

## Impact

- Affected specs: `realtime-updates` (1 MODIFIED, 1 REMOVED requirement),
  `production-observability` (1 REMOVED), `retention-management` (1 REMOVED)
- Affected code: `lib/Service/ImportService.php`,
  `lib/Listener/NotifyPushListener.php`, `lib/AppInfo/Application.php`,
  `appinfo/routes.php`, `appinfo/info.xml`, deletions listed above
- Behaviour: bulk imports now notify connected clients (one collection event
  per affected register/schema pair); every object save stops paying a
  realtime-log DB insert

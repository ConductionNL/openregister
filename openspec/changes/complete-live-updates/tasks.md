# Tasks: Complete Live Updates

## 1. Fix the import batch flush

- [x] 1.1 Add `NotifyPushListener::hasBatchedCollections(): bool` guard accessor
  so import callers can skip IQueue resolution entirely when nothing was
  accumulated (notify_push absent → zero logs, zero container churn).
- [x] 1.2 Simplify `NotifyPushListener::flushBatch()` signature to
  `flushBatch(object $queue): void` — the `PermissionHandler` parameter was
  never used (batch flush is an untargeted broadcast by design) and carried a
  PHPMD `UnusedFormalParameter` suppression.
- [x] 1.3 Inject `Psr\Container\ContainerInterface` into `ImportService` and add
  `flushNotifyPushBatch()`: early-return when nothing accumulated, lazily
  resolve `OCA\NotifyPush\Queue\IQueue`, soft-fail with at most one DEBUG log,
  delegate to `NotifyPushListener::flushBatch()`.
- [x] 1.4 Wire both bulk-save call sites (`processSpreadsheetBatch`,
  `processCsvSheet`): call `flushNotifyPushBatch()` inside the existing
  `finally` block BEFORE `setBatchMode(false)` (which clears the accumulator).
  Remove the `@todo add-live-updates/task-6` markers.
- [x] 1.5 Extend `tests/Unit/Listener/NotifyPushListenerTest.php`: flush emits
  exactly one deduplicated event per (register, schema) pair, broadcasts
  without a `user` key, payload is slugs + `action: batch` only, accumulator
  cleared after flush, `hasBatchedCollections()` state transitions.
- [x] 1.6 Add `tests/Unit/Service/ImportServiceNotifyPushBatchTest.php` proving
  the end-to-end contract through `importFromCsv()`: batch mode on → N object
  saves emit zero pushes during the save → completion flushes exactly the
  deduplicated collection events and zero per-object events; per-object pushes
  resume after the import; failure path (`saveObjects` throws after partial
  saves) still flushes.
- [x] 1.7 Decouple hint accumulation from the `events` flag: the default import
  path runs `events=false` end to end (UI → `RegistersController` →
  `ImportService` → `SaveObjects`), so the listener never accumulates on its
  own. Add `NotifyPushListener::addBatchedCollection()` and
  `ImportService::queueNotifyPushCollectionHint()`; both call sites derive the
  `(register-slug, schema-slug)` pair from the save RESULT (saved/updated
  non-empty, or conservatively when the save throws; all-unchanged emits
  nothing). Tests exercise the real default flags (no manual event dispatch),
  the failure path, the all-unchanged case, and the events-enabled variant
  (result hint + listener accumulation deduplicate — no double emit).

## 2. Remove the orphaned RealtimeService write path

- [x] 2.1 Verify zero consumers: grep all of apps-extra plus this repo's `src/`
  and `website/` for `realtime/events`, `RealtimeService`, `RealtimeController`,
  `api/realtime` — only openregister's own implementation/tests/archived docs
  matched.
- [x] 2.2 Delete `lib/Service/RealtimeService.php`,
  `lib/Controller/RealtimeController.php`, `lib/Db/RealtimeEvent.php`,
  `lib/Db/RealtimeEventMapper.php`, `lib/Listener/RealtimeEventListener.php`,
  `lib/BackgroundJob/RealtimeEventRetentionJob.php`.
- [x] 2.3 Remove the two `/api/realtime/*` routes from `appinfo/routes.php`, the
  four `RealtimeEventListener` registrations + import from
  `lib/AppInfo/Application.php`, and the `RealtimeEventRetentionJob` entry from
  `appinfo/info.xml`.
- [x] 2.4 Delete the `openregister_realtime_events` create-migration
  (`lib/Migration/Version1Date20260430000000.php`) so fresh installs no longer
  create the dead table, and add `lib/Migration/Version1Date20260714120000.php`
  dropping the orphaned table (and its indexes) on instances that still carry
  it — idempotent, drops only when present.
- [x] 2.5 Delete `tests/Service/RealtimeUpdatesIntegrationTest.php` and
  `tests/Service/RealtimeEventRetentionJobTest.php`; drop the stale
  `RealtimeEventMapper` entry from `phpstan-baseline.neon`.
- [x] 2.6 Spec deltas: REMOVE the realtime-log recorder requirement from
  `realtime-updates`, the CloudEvent-envelope requirement from
  `production-observability`, and the daily prune requirement from
  `retention-management`; MODIFY the batch-mode requirement in
  `realtime-updates` to codify the import-completion flush and broadcast
  semantics.
- [x] 2.7 Update `docs/features/realtime-updates.md` — drop the removed/never-built
  `/api/realtime/*` endpoint listings, document the actual transports.

## 3. Verification

- [x] 3.1 `composer check:strict` clean on touched files.
- [x] 3.2 PHPUnit unit suite green (new tests pass, no regressions).

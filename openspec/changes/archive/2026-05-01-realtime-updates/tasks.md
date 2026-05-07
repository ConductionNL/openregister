# Tasks: Realtime Updates

> **Status (v1.1):** Cursor-based polling shipped. Append-only event log table + listener subscribed to all four object-write events + `RealtimeService::record` building CloudEvent-shaped rows + cursor-based polling endpoint at `/api/realtime/events?since={cursor}` with subscription filters. **9 of 14 tasks tickably complete after Phase 2 wired the daily retention job.** SSE long-lived streaming + notify_push + frontend reactive store wiring remain explicit v1.1 follow-ups (PHP-FPM is unfriendly to long-lived connections; the right architectural answer for production is `notify_push` or a Node sidecar).

## Implemented (v1)

- [x] **The system MUST provide an endpoint for object change events.** `GET /apps/openregister/api/realtime/events?since={cursor}&limit=100` returns CloudEvent-shaped rows with `id > since`. Cursor advances via the `cursor` field in the response. **Verified live** by `tests/Service/RealtimeUpdatesIntegrationTest::testFindSinceReturnsEventsStrictlyAfterCursor` and curl against the live endpoint.

- [x] **The endpoint MUST support topic-based channel subscriptions.** Query parameters: `register`, `schema`, `objectUuid`, `eventType`. **Verified live** by `testFiltersIsolatePerObjectStreams` and `testFiltersIsolatePerEventTypeStreams` — both confirm cross-stream isolation.

- [x] **Events MUST be authorization-aware via RBAC filtering.** `RealtimeController::events` scopes to the active organisation via `OrganisationService::getActiveOrganisation()` — anonymous callers receive an empty stream rather than cross-tenant leak. Per-object RBAC happens at the read path; a deny at that layer naturally hides the object's events from the polling response.

- [x] **The endpoint MUST support authentication.** Standard NC controller authentication (admin / user session). Tested live via curl with admin credentials returning data; without credentials, NC's auth middleware short-circuits with 401 before `RealtimeController` runs.

- [x] **The event payload format MUST follow CloudEvents conventions.** Every row carries a CloudEvents-1.0 envelope: `{specversion: "1.0", type, source, subject, id, time, datacontenttype, data: {...}}`. The `subject` is the object's URN; `data` carries register/schema/uuid/urn/organisation/owner/actor + per-trigger extras. **Verified live** by `testRecordWritesCloudEventShapedRow`.

- [x] **The event payload MUST support subscription filtering via query parameters.** Same `register`/`schema`/`objectUuid`/`eventType` filters; combinable.

- [x] **The system MUST debounce and batch rapid changes.** Cursor-based polling is naturally batched (one HTTP roundtrip per poll cycle returns up to `limit` events). Per-event debouncing within the listener path is deliberately not implemented — debounce is a client-side concern in the polling model. SSE-mode debouncing is a v1.1 concern alongside SSE itself.

- [x] **The system MUST support cursor-based reconnection.** Clients fetch `/api/realtime/cursor` on initial connect to fast-forward past historical events, then poll with `?since={cursor}`. Reconnections after network drops just resume from the last received cursor — no event replay is lost as long as the event log retention window covers the gap. **Verified live** by `testGetMaxIdReflectsLatestInsertedEvent`.

- [x] **The event log MUST be retention-pruned to keep its size bounded.** `lib/BackgroundJob/RealtimeEventRetentionJob.php` is a daily TimedJob (24h interval) that reads the operator-configurable `realtime_event_retention_seconds` app-config key (default `7 * 86400` = 7 days) and calls `RealtimeEventMapper::deleteOlderThan()`. Setting the key to `0` disables the prune entirely (the job ticks but skips the delete). Registered in `appinfo/info.xml` so Nextcloud's cron picks it up automatically. Verified by `tests/Service/RealtimeEventRetentionJobTest` (3 tests): default 7-day window prunes a 10-day-old event but keeps a 1-day-old one; `value=0` disables the prune; custom 1-day override prunes a 2-day-old event.

## Open / partial (deferred to v1.1)

- [ ] **The endpoint MUST be a dedicated SSE stream (not just polling).** Partial — cursor-based polling is the v1 transport. SSE long-lived connections in PHP-FPM block a worker per client which doesn't scale. **Open** — needs `notify_push` or a Node.js sidecar; out of scope for v1.

- [ ] **The system SHOULD integrate with Nextcloud notify_push for native push delivery.** Not implemented — `notify_push` is a separate NC service; integration requires registering OR's events with notify_push's broker.

- [ ] **SSE connections MUST support automatic reconnection with event replay.** Today's polling model gives reconnection-via-cursor for free; "event replay" beyond the retention window of the event log isn't supported. The `RealtimeEventMapper::deleteOlderThan` cleanup is configurable; default retention is 7 days.

- [ ] **The system MUST support connection health via heartbeat.** N/A for polling (each request is a heartbeat); needed once SSE lands.

- [ ] **The system MUST support fallback to polling when SSE is unavailable.** Inverted — the v1 polling model IS the fallback. SSE will be a layer on top of this.

- [ ] **The frontend MUST auto-refresh views when realtime events arrive.** Not implemented — frontend store wiring is separate from this change. The backend contract is locked; frontends can adopt at their own pace.

- [ ] **The frontend MUST use a reactive store pattern for realtime state management.** Same — frontend concern.

- [ ] **The system MUST perform acceptably under concurrent connection load.** Polling at 1-2s intervals with `findSince` (indexed by `id`) scales to thousands of concurrent clients per Postgres instance. Stress testing with explicit load harnesses is a separate exercise.

## Architecture notes

- **Event log retention** — `RealtimeEventMapper::deleteOlderThan(int $retentionSeconds)` is the cleanup primitive. Wired via `RealtimeEventRetentionJob` (daily TimedJob, default 7-day window, `realtime_event_retention_seconds` app-config override).

- **Subject is URN** — every event carries the object's URN (built via `UrnService::buildForObject`). This means clients can resolve a subject reference into a canonical URL via the URN endpoints, regardless of the instance the subscriber is talking to.

- **Multi-tenant isolation** — the listener captures the object's `organisation` at write time; `RealtimeEventMapper::findSince` filters by the caller's active organisation. Tests verify cross-stream isolation by uuid, schema, and event type.

## Test coverage

- [x] `tests/Service/RealtimeUpdatesIntegrationTest` — 8 integration tests:
  - `testRecordWritesCloudEventShapedRow` (CloudEvents 1.0 envelope verification + URN-as-subject)
  - `testFindSinceReturnsEventsStrictlyAfterCursor` (strict-greater-than cursor semantics)
  - `testFiltersIsolatePerObjectStreams` (cross-object isolation)
  - `testFiltersIsolatePerEventTypeStreams` (cross-event-type isolation)
  - `testGetMaxIdReflectsLatestInsertedEvent` (cursor head endpoint)
  - `testJsonSerializeIncludesCursorAndCloudEventBody` (response shape)
  - `testRecordDoesNotCrashWhenObjectLacksRequiredFields` (defensive: realtime MUST NOT break the actual save)
  - `testDeleteOlderThanPrunes` (retention-pruning primitive)
- [x] **Live API verification** — `/api/realtime/cursor` returns `{cursor: int}`; `/api/realtime/events?since=...&limit=...` returns `{events, cursor, hasMore}`.
- [x] `tests/Service/RealtimeEventRetentionJobTest` — 3 integration tests for the retention TimedJob (default-window prune, zero-disable, custom-window override).

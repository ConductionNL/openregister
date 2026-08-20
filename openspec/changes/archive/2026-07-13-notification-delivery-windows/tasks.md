# Tasks: Notification Engine — Quiet Hours / Delivery Windows + Per-Rule Digest Scheduling

## 1. Data model

- [x] 1.1 Create `lib/Db/QueuedNotification.php` entity (`id`, `schema_id`,
      `rule_key`, `recipient`, `reason` [`quiet-hours`|`digest-schedule`],
      `object_uuid` nullable, `payload` json text, `due_at_hint` datetime,
      `created_at` datetime), following `lib/Db/NotificationDedupeState.php`'s
      shape and getter/setter docblock style.
- [x] 1.2 Create `lib/Db/QueuedNotificationMapper.php` (`extends QBMapper`,
      table `openregister_notification_queue`), following
      `lib/Db/NotificationDedupeStateMapper.php`: `findAll()`
      (all rows, since flush re-evaluates live per design.md), `insert()`
      wrapper (inherited), `deleteById()`, `findByRecipientAndRule()` for the
      per-`(rule, recipient)` grouping the flush job needs.
- [x] 1.3 Add migration `lib/Migration/Version1Date20260712120000.php`
      creating `openregister_notification_queue` with indexes on
      `(recipient, reason)` and `due_at_hint`.

## 2. Delivery-window preference (per-user quiet hours)

- [x] 2.1 Create `lib/Service/Notification/NotificationDeliveryWindowService.php`:
      `getForUser(string $userId): ?array`, `setForUser(string $userId, ?array
      $window): void`, storing `{enabled, start, end, timezone, days?}` as a
      single JSON value via `IConfig::setUserValue` under app `openregister`,
      key `notification_delivery_window` — mirror
      `NotificationPreferenceService`'s override-only, zero-migration pattern
      (`lib/Service/Notification/NotificationPreferenceService.php`).
      `getForUser()` returns `null` when no value is stored (no per-user row
      required).
- [x] 2.2 Add `isInsideWindow(array $window, \DateTimeImmutable $now):
      bool` helper (or a small `DeliveryWindowEvaluator` class) that resolves
      `now` in the window's `timezone` (falling back to `OCP\IDateTimeZone`
      server default when `timezone` is absent) and checks `[start, end)`,
      wrapping past-midnight ranges (e.g. `18:00`-`08:00`) correctly.
- [x] 2.3 Validate `start`/`end` as `HH:MM` and `timezone` as a value
      `DateTimeZone` accepts; reject malformed values with a clear exception
      (the controller translates to HTTP 422).

## 3. Delivery-window API

- [x] 3.1 Create `lib/Controller/NotificationDeliveryWindowController.php`
      (`GET`/`update` methods), following
      `lib/Controller/NotificationPreferencesController.php`'s auth pattern
      (`@NoAdminRequired`, `@NoCSRFRequired` docblock tags — matching the
      existing controller's style, not PHP attributes; resolve current user
      from `IUserSession`, 401 when unauthenticated, scope strictly to the
      authenticated user).
- [x] 3.2 Register routes in `appinfo/routes.php`:
      `notificationDeliveryWindow#index` GET `/api/notification-delivery-window`,
      `notificationDeliveryWindow#update` PUT `/api/notification-delivery-window`
      (placed next to the existing `notificationPreferences#*` entries).
- [x] 3.3 `GET` with no stored preference returns `{enabled: false}` (not a
      404/500) — the "no configured window" backward-compat case.

## 4. Dialect + validator changes

- [x] 4.1 Add `critical: bool` (default `false`) and `digest: {schedule,
      at, timezone?, weekday?}` to the recognized keys in
      `lib/Service/Notification/NotificationAnnotationValidator.php`, via
      new `validateCriticalAndDigest()` following the existing
      `{code, message}` structured-error precedent used throughout the
      validator (the validator does not use a `{code, ruleKey, field,
      value, message}` shape anywhere today — matched the file's actual
      convention instead).
- [x] 4.2 Validate: `critical` must be boolean; `digest.schedule` must be
      `daily`|`weekly`; `digest.at` must be `HH:MM`; `digest.weekday`
      required (0-6) when `schedule: "weekly"`, forbidden otherwise; a rule
      MUST NOT declare both a `digest` block and a rolling `coalesce`
      window (mutually exclusive, HTTP 422). NOTE: the codebase has no
      literal "digest period" / "throttle" key — `coalesce:
      {windowSeconds, maxEvents}` (`NotificationCoalescer`) is the only
      existing rolling-batching mechanism, so it is what "rolling digest
      period" in design.md maps to; documented here since the mapping is
      an interpretation, not a literal grep hit.
- [x] 4.3 Update the schema-save validation call sites (wherever
      `NotificationAnnotationValidator::validate()` is invoked on schema
      create/update) — confirmed already wired in `lib/Db/SchemaMapper.php`;
      no new call site needed.

## 5. Dispatcher gate

- [x] 5.1 In `AnnotationNotificationDispatcher::dispatchWithSchema()`, add
      the delivery-window/digest-schedule gate immediately after the
      existing preference gate, evaluated only for non-broadcast channels
      (`nc-notification`, `email`, `activity`) and only when the rule
      spec's `critical` is not `true` (`deliveryWindowOrDigestSuppresses()`).
- [x] 5.2 When the gate suppresses: persist a `QueuedNotification` via
      `QueuedNotificationMapper` with the pre-resolved subject/message/
      channels/context (so flush needs no re-resolution), `reason` set to
      `quiet-hours` / `digest-schedule` / `quiet-hours+digest-schedule` as
      applicable, and record notification history with status
      `queued-quiet-hours` / `queued-digest` (new status values, additive
      to the existing four).
- [x] 5.3 Add `AnnotationNotificationDispatcher::dispatchQueued(array
      $queuedRows): void` that reuses the existing channel fan-out
      (`emitNotification`, `emitEmail`, `emitActivity`) unchanged, grouping
      same-`(rule_key, recipient)` rows into one digest-style message via
      `buildQueuedSummary()` (breakdown by stored `action`, "N nieuw, M
      gewijzigd" pattern), and updates history to `dispatched` on success.
- [x] 5.4 Ensure `critical: true` rules skip step 5.1 entirely — dispatch
      proceeds exactly as before this change (still subject to the
      unmodified preference-off / rate-limit / coalesce gates).

## 6. Queue flush job

- [x] 6.1 Create `lib/BackgroundJob/NotificationQueueFlushJob.php`, a 60s
      `TimedJob` following `lib/BackgroundJob/ScheduledNotificationJob.php`'s
      structure (constructor DI, `run()` fail-closed on missing
      dependencies, structured logging).
- [x] 6.2 On each tick: scan all `QueuedNotification` rows via
      `QueuedNotificationMapper`, group by `(schema_id, rule_key,
      recipient)`, live-re-evaluate each group's condition (delivery window
      via `NotificationDeliveryWindowService` + `isInsideWindow()` gates the
      WHOLE group; digest schedule via the rule's `digest` config resolved
      from the owning schema gates each row individually by its own
      `created_at`) against the current wall clock, and call
      `dispatchQueued()`/delete the flushed rows for every group/row whose
      condition has cleared.
- [x] 6.3 Register the job in `appinfo/info.xml`'s `<background-jobs>` list
      (alongside `ScheduledNotificationJob`) — NOTE: background jobs in
      this codebase are registered in `appinfo/info.xml`, not
      `lib/AppInfo/Application.php` (verified: `BatchNotificationJob`'s own
      registration lived in info.xml, not Application.php; tasks.md's
      description of the registration point was inaccurate).
- [x] 6.4 Remove `lib/BackgroundJob/BatchNotificationJob.php` and
      `lib/Service/Notification/NotificationDigest.php` (superseded, zero
      other callers per design.md verification); removed the
      `BatchNotificationJob` `<job>` entry from `appinfo/info.xml` (replaced
      by `NotificationQueueFlushJob`); also removed the now-obsolete
      `tests/Unit/Service/Notification/NotificationDigestTest.php`.

## 7. Tests

- [x] 7.1 Unit tests for `NotificationDeliveryWindowService`
      (`tests/Unit/Service/Notification/NotificationDeliveryWindowServiceTest.php`):
      get/set/round-trip, no-stored-value → null, past-midnight window
      wrapping, DST spring-forward transition, `days` restriction,
      unauthorized cross-user access rejected at controller level (see 7.3
      note below — moved to the controller test per its actual auth
      surface).
- [x] 7.2 Unit tests for `NotificationAnnotationValidator`
      (`tests/Unit/Service/Notification/NotificationAnnotationValidatorDeliveryWindowTest.php`)
      covering the new `critical`/`digest` grammar (valid cases, missing
      `weekday` on weekly, weekday-not-allowed on daily, bad `at` format,
      bad timezone, mutual exclusion with `coalesce`).
- [x] 7.3 Unit tests for `AnnotationNotificationDispatcher`'s new gate
      (`tests/Unit/Service/Notification/AnnotationNotificationDispatcherDeliveryWindowTest.php`):
      queued during quiet hours (with `QueuedNotification` row + history
      assertions), immediate when `critical: true`, immediate when no
      window/digest configured (backward compat), digest-declared rule
      always queues, window+digest both active records the combined
      reason, broadcast (webhook) channel unaffected. Also
      `tests/Unit/Controller/NotificationDeliveryWindowControllerTest.php`
      covers 401-unauthenticated and the "uid in body is ignored, session
      user is authoritative" cross-user guarantee.
- [x] 7.4 Unit tests for `NotificationQueueFlushJob`
      (`tests/Unit/BackgroundJob/NotificationQueueFlushJobTest.php`):
      not-flushed while window active, flushes once window clears, groups
      multiple queued events into one `dispatchQueued()` call, window
      overlap (quiet-hours-end vs digest-due-time, later of the two wins —
      two tests for both sides), a row queued after the last digest
      occurrence waits for the next one, DST-transition live re-evaluation
      (row queued pre-transition, flush evaluated post-transition, asserts
      the CURRENT offset drives the decision).
- [x] 7.5 Integration/PHPUnit test for the full path
      (`tests/Unit/Service/Notification/NotificationDeliveryWindowIntegrationTest.php`):
      rule with `digest` schedule + recipient with quiet hours → 3 events
      queued (not dropped, zero immediate deliveries) → flushed once at the
      window/digest edge through the REAL dispatcher + REAL flush job →
      exactly one notification delivered and exactly one notification
      history row transitions to `dispatched`.
- [x] 7.6 (added, not in original checklist) Entity round-trip test
      `tests/Unit/Db/QueuedNotificationTest.php` and schedule-evaluator
      unit tests `tests/Unit/Service/Notification/DigestScheduleEvaluatorTest.php`
      (daily/weekly `lastOccurrence()`, `isDue()` semantics, DST edge,
      fail-open on malformed spec).

## 8. Docs

- [x] 8.1 Updated `docs/features/webhooks-and-notifications.md`: new
      "Delivery Windows (Quiet Hours) & Fixed-Time Digest Schedules"
      section documenting the `GET`/`PUT /api/notification-delivery-window`
      endpoints, the `critical`/`digest` dialect keys, and the queue/flush
      dispatcher behaviour.
- [x] 8.2 Updated `openspec/coverage-report.md` (two `BatchNotificationJob`
      references replaced with `NotificationQueueFlushJob`) — this file is
      a generated coverage/bucket snapshot (see `opsx-coverage-scan`), so
      the edit is a courtesy sync, not a full regeneration.
      `openspec/platform-capabilities.md` had no `BatchNotificationJob` /
      `NotificationDigest` references, so no change was needed there.

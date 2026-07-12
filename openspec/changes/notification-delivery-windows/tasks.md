# Tasks: Notification Engine — Quiet Hours / Delivery Windows + Per-Rule Digest Scheduling

## 1. Data model

- [ ] 1.1 Create `lib/Db/QueuedNotification.php` entity (`id`, `schema_id`,
      `rule_key`, `recipient`, `reason` [`quiet-hours`|`digest-schedule`],
      `object_uuid` nullable, `payload` json text, `due_at_hint` datetime,
      `created_at` datetime), following `lib/Db/NotificationDedupeState.php`'s
      shape and getter/setter docblock style.
- [ ] 1.2 Create `lib/Db/QueuedNotificationMapper.php` (`extends QBMapper`,
      table `openregister_notification_queue`), following
      `lib/Db/NotificationDedupeStateMapper.php`: `findDueForFlush()`
      (all rows, since flush re-evaluates live per design.md), `insert()`
      wrapper, `deleteById()`, `findByRecipientAndRule()` for the
      per-`(rule, recipient)` grouping the flush job needs.
- [ ] 1.3 Add migration `lib/Migration/Version1Date<YYYYMMDDHHMMSS>.php`
      creating `openregister_notification_queue` with indexes on
      `(recipient, reason)` and `due_at_hint`.

## 2. Delivery-window preference (per-user quiet hours)

- [ ] 2.1 Create `lib/Service/Notification/NotificationDeliveryWindowService.php`:
      `getForUser(string $userId): ?array`, `setForUser(string $userId, ?array
      $window): void`, storing `{enabled, start, end, timezone, days?}` as a
      single JSON value via `IConfig::setUserValue` under app `openregister`,
      key `notification_delivery_window` — mirror
      `NotificationPreferenceService`'s override-only, zero-migration pattern
      (`lib/Service/Notification/NotificationPreferenceService.php`).
      `getForUser()` returns `null` when no value is stored (no per-user row
      required).
- [ ] 2.2 Add `isInsideWindow(array $window, \DateTimeImmutable $now):
      bool` helper (or a small `DeliveryWindowEvaluator` class) that resolves
      `now` in the window's `timezone` (falling back to `OCP\IDateTimeZone`
      server default when `timezone` is absent) and checks `[start, end)`,
      wrapping past-midnight ranges (e.g. `18:00`-`08:00`) correctly.
- [ ] 2.3 Validate `start`/`end` as `HH:MM` and `timezone` as a value
      `DateTimeZone` accepts; reject malformed values with a clear exception
      (the controller translates to HTTP 422).

## 3. Delivery-window API

- [ ] 3.1 Create `lib/Controller/NotificationDeliveryWindowController.php`
      (`GET`/`update` methods), following
      `lib/Controller/NotificationPreferencesController.php`'s auth pattern
      (`#[NoAdminRequired]`, `#[NoCSRFRequired]`, resolve current user from
      `IUserSession`, 401 when unauthenticated, scope strictly to the
      authenticated user).
- [ ] 3.2 Register routes in `appinfo/routes.php`:
      `notificationDeliveryWindow#index` GET `/api/notification-delivery-window`,
      `notificationDeliveryWindow#update` PUT `/api/notification-delivery-window`
      (placed next to the existing `notificationPreferences#*` entries).
- [ ] 3.3 `GET` with no stored preference returns `{enabled: false}` (not a
      404/500) — the "no configured window" backward-compat case.

## 4. Dialect + validator changes

- [ ] 4.1 Add `critical: bool` (default `false`) and `digest: {schedule,
      at, timezone?, weekday?}` to the recognized keys in
      `lib/Service/Notification/NotificationAnnotationValidator.php`,
      following the throttle-window-grammar validation precedent (structured
      `{code, ruleKey, field, value, message}` errors, HTTP 422).
- [ ] 4.2 Validate: `critical` must be boolean; `digest.schedule` must be
      `daily`|`weekly`; `digest.at` must be `HH:MM`; `digest.weekday`
      required (0-6) when `schedule: "weekly"`, forbidden otherwise; a rule
      MUST NOT declare both a rolling digest period and a `digest` block
      (mutually exclusive, HTTP 422).
- [ ] 4.3 Update the schema-save validation call sites (wherever
      `NotificationAnnotationValidator::validate()` is invoked on schema
      create/update) — no new call site needed if it already runs on every
      schema save; confirm and note in the PR if a new hook is required.

## 5. Dispatcher gate

- [ ] 5.1 In `AnnotationNotificationDispatcher::dispatchWithSchema()`, add
      the delivery-window/digest-schedule gate immediately after the
      existing preference gate (`AnnotationNotificationDispatcher.php:476-506`),
      evaluated only for non-broadcast channels (`nc-notification`, `email`,
      `activity`) and only when the rule spec's `critical` is not `true`.
- [ ] 5.2 When the gate suppresses: persist a `QueuedNotification` via
      `QueuedNotificationMapper` with the pre-resolved subject/message/
      channels/context (so flush needs no re-resolution), `reason` set to
      `quiet-hours` and/or `digest-schedule` as applicable, and record
      notification history with status `queued-quiet-hours` /
      `queued-digest` (new status values, additive to the existing four).
- [ ] 5.3 Add `AnnotationNotificationDispatcher::dispatchQueued(array
      $queuedRows): void` (or per-row) that reuses the existing channel
      fan-out (`emitNotification`, `emitEmail`, `emitActivity`) unchanged,
      grouping same-`(rule_key, recipient)` rows into one digest-style
      message (reuse the "N nieuw, M gewijzigd" breakdown pattern already
      used for the rolling digest window), and updates history to
      `dispatched` on success.
- [ ] 5.4 Ensure `critical: true` rules skip step 5.1 entirely — dispatch
      proceeds exactly as before this change (still subject to the
      unmodified preference-off / rate-limit / coalesce gates).

## 6. Queue flush job

- [ ] 6.1 Create `lib/BackgroundJob/NotificationQueueFlushJob.php`, a 60s
      `TimedJob` following `lib/BackgroundJob/ScheduledNotificationJob.php`'s
      structure (constructor DI, `run()` fail-closed on missing
      dependencies, structured logging).
- [ ] 6.2 On each tick: scan all `QueuedNotification` rows via
      `QueuedNotificationMapper`, group by `(recipient, reason-relevant
      rule)`, live-re-evaluate each group's condition (delivery window via
      `NotificationDeliveryWindowService` + `isInsideWindow()`, digest
      schedule via the rule's `digest` config resolved from the owning
      schema) against the current wall clock, and call
      `dispatchQueued()`/delete the flushed rows for every group whose
      condition has cleared.
- [ ] 6.3 Register the job in `lib/AppInfo/Application.php`'s background-job
      registration list (alongside `ScheduledNotificationJob`,
      `BatchNotificationJob`).
- [ ] 6.4 Remove `lib/BackgroundJob/BatchNotificationJob.php` and
      `lib/Service/Notification/NotificationDigest.php` (superseded, zero
      other callers per design.md verification); remove their background-job
      registration entry.

## 7. Tests

- [ ] 7.1 Unit tests for `NotificationDeliveryWindowService`
      (get/set/round-trip, no-stored-value → null, past-midnight window
      wrapping, unauthorized cross-user access rejected at controller level).
- [ ] 7.2 Unit tests for `NotificationAnnotationValidator` covering the new
      `critical`/`digest` grammar (valid cases, missing `weekday` on weekly,
      bad `at` format, mutual exclusion with rolling digest period).
- [ ] 7.3 Unit tests for `AnnotationNotificationDispatcher`'s new gate:
      queued during quiet hours, immediate when `critical: true`, immediate
      when no window/digest configured (backward compat), broadcast channels
      unaffected.
- [ ] 7.4 Unit tests for `NotificationQueueFlushJob`: flushes once window
      clears, groups multiple queued events into one digest message, window
      overlap (quiet-hours-end vs digest-due-time, later of the two wins),
      DST-transition live re-evaluation (mock two timezone offsets across
      the transition boundary and confirm the flush decision uses the
      current offset, not a stale precomputed one).
- [ ] 7.5 Integration/PHPUnit test for the full path: rule with `digest`
      schedule + recipient with quiet hours → queued → flushed once, exactly
      one notification history row transitions to `dispatched`.

## 8. Docs

- [ ] 8.1 Update any developer-facing dialect reference docs (e.g. app
      README/docs sections describing `x-openregister-notifications`) to
      list `critical` and `digest` alongside the existing `trigger`/
      `filter`/`recipients`/`channels`/`throttle`/`audit` keys.
- [ ] 8.2 Update `openspec/coverage-report.md` / `platform-capabilities.md`
      references to the notification engine if they enumerate dialect keys
      or background jobs by name (so `BatchNotificationJob` removal and
      `NotificationQueueFlushJob` addition are reflected).

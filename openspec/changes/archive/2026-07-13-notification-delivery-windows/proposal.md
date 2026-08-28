---
kind: code
depends_on: []
---

# Notification Engine — Quiet Hours / Delivery Windows + Per-Rule Digest Scheduling

## Why

**The dispatcher has no delivery-window concept — it only has a drop gate.**
Verified against `lib/Service/Notification/AnnotationNotificationDispatcher.php`
(HEAD, 2026-07-12): `dispatchWithSchema()` resolves the per-recipient
preference at lines 482–506 via `NotificationPreferenceService::resolveEffective()`
and, when the effective preference is off, records a `preference-off` history
row and `continue`s — the event is gone (`lib/Service/Notification/AnnotationNotificationDispatcher.php:489-501`).
`NotificationPreferenceService::resolveEffective()`
(`lib/Service/Notification/NotificationPreferenceService.php:184-219`) returns
only `{enabled, channels, source}` — there is no time-of-day, timezone, or
"queue for later" dimension anywhere in the resolution path. `grep -rn
"quietHours|quiet_hours|quiet-hours" lib/ --include=*.php` returns zero hits.

**The spec already describes quiet hours — as unimplemented aspiration, not a
built mechanic.** `openspec/specs/notificatie-engine/spec.md:266-270`
("Scenario: User sets global quiet hours") and `:272-277` ("Scenario: Admin
overrides user preferences for critical notifications") both read as normative
scenarios, but the normative dialect requirement two hundred lines later
(`openspec/specs/notificatie-engine/spec.md:634-663`, "Schemas MAY declare
notifications via `x-openregister-notifications` with a normative channel
block format") lists no `critical` key and no delivery-window shape anywhere
in the schema-authoring surface those scenarios presuppose. The dialect a
schema author can actually write has no way to mark a rule `critical`, and no
user-facing surface exists to set quiet hours (only
`NotificationPreferencesController` at `/api/notification-preferences`,
scoped to per-`(schema, notification)` on/off — verified
`lib/Controller/NotificationPreferencesController.php:1-30`). This spec
inconsistency is itself evidence the feature was speced once and never
followed through to a dialect key or a controller.

**Digest infrastructure exists as two unwired dead-code primitives.**
`lib/Service/Notification/NotificationDigest.php` is an explicit "pure-domain
— no DB, no scheduling" in-memory bucket, whose own docblock says it is "used
as the primitive layer beneath a **future** BatchNotificationJob" (lines 1-15).
`lib/BackgroundJob/BatchNotificationJob.php` is that job — a real 5-minute
`TimedJob` — but its `run()` method's own comment admits: "Until the
dispatcher exposes a public `dispatchDigest()` entry, this job simply logs the
flush... The dispatch wiring is a focused follow-up commit on the same
branch" (`lib/BackgroundJob/BatchNotificationJob.php:126-143`). `grep -rn
"NotificationDigest\b" lib/Service/Notification/AnnotationNotificationDispatcher.php
lib/Listener/*.php` returns zero hits: nothing ever calls
`NotificationDigest::enqueue()`. Even if something did, the queue is a plain
PHP array on a service instance — it does not survive the process boundary
between the web/cron request that would enqueue an event and the next
`BatchNotificationJob` tick, so wiring `enqueue()` in today's shape would
silently drop every batched item. The existing "batching and digest delivery"
requirement (`openspec/specs/notificatie-engine/spec.md:191-218`) only covers
a *rolling* window (`digest period: 300` = 5 minutes after the first event);
it has no fixed-time-of-day schedule ("flush at 07:00 daily"), which is what
every digest consumer in the fleet actually wants.

**A consumer is already blocked on exactly this.** scholiq's `grading` spec
(`scholiq-dev/openspec/specs/grading/spec.md:56`) requires: "Notification
dispatch MUST honour per-parent / per-18+-learner preference (instant vs
daily digest), backed by a `NotificationPreference` schema or the existing OR
notification-preference mechanism (**whichever OR exposes**)" — scholiq is
explicitly deferring the decision to OpenRegister because OR does not yet
expose one. scholiq's `scholiq-notifications` spec
(`scholiq-dev/openspec/specs/scholiq-notifications/spec.md:74-81`) only
documents the existing per-`(schema, notification)` on/off override — no
delivery-window concept exists there either, confirming the gap-report's
scope check.

**Demand evidence** (Spectr): insight 1178 ("notification overload + late
delivery is a universal LMS complaint", high-confidence) and insight 1173;
stories `notification-quiet-hours` (10104), `notification-per-course-mute-digest`
(10117), `vo-cijfer-parent-digest` (9748); journey 1751. Competitor failure
modes cited: Canvas ignores quiet-hour preferences entirely; Brightspace
sends one email per post (no batching); Somtoday delivers grade notifications
after the review deadline has already passed (a timing bug, not a feature —
exactly the "wrong timing in both directions" failure a dispatcher-level fix
resolves once for every OR-consuming app, not per app).

## What Changes

- **New user-level delivery-window preference**, separate from the existing
  per-`(schema, notification)` override: `NotificationDeliveryWindowService`
  stores one JSON value per user via `IConfig::setUserValue` under app
  `openregister`, key `notification_delivery_window`
  (`{enabled, start: "HH:MM", end: "HH:MM", timezone, days?: [0-6]}`), mirroring
  the existing override-only, zero-migration pattern in
  `NotificationPreferenceService`. New `NotificationDeliveryWindowController`
  exposing `GET`/`PUT /api/notification-delivery-window`, registered in
  `appinfo/routes.php` alongside the existing `notificationPreferences#*`
  routes.
- **New durable queue table** `openregister_notification_queue` (Entity
  `QueuedNotification` + `QueuedNotificationMapper extends QBMapper`,
  following the `NotificationDedupeState`/`NotificationDedupeStateMapper`
  pattern at `lib/Db/NotificationDedupeState.php`) — replaces the in-memory
  `NotificationDigest` primitive as the persistence layer for BOTH quiet-hours
  suppression and digest scheduling (see design.md "Rejected alternatives").
- **New dispatcher gate** in `AnnotationNotificationDispatcher::dispatchWithSchema()`,
  evaluated per-recipient alongside the existing preference gate
  (`AnnotationNotificationDispatcher.php:476-506`): when the recipient is
  inside their configured delivery window (or the rule declares a `digest`
  schedule not yet due) AND the rule is not `critical: true`, persist a
  `QueuedNotification` row instead of emitting, with status
  `queued-quiet-hours` / `queued-digest` recorded in notification history.
- **New `critical: bool` key** on the `x-openregister-notifications` rule
  spec, added to the normative dialect at
  `openspec/specs/notificatie-engine/spec.md:634-663`: bypasses quiet-hours
  queuing so urgent rules always dispatch immediately. Scoped to quiet-hours
  bypass only in this change (the pre-existing, still-unimplemented
  "Admin overrides user preferences for critical notifications" scenario at
  `:272-277`, about bypassing per-channel opt-out, is untouched — see
  design.md Deferred).
- **New optional `digest` block** on the rule spec:
  `digest: {schedule: "daily"|"weekly", at: "HH:MM", timezone?, weekday?: 0-6}`
  — a fixed-time schedule, distinct from and layered on top of the existing
  rolling `digest period` (`:191-218`). One flush groups all queued items for
  a `(rule, recipient)` pair into a single summary notification, reusing the
  existing "N nieuw, M gewijzigd" breakdown pattern from `:214-218`.
- **New `NotificationQueueFlushJob`** (60s `TimedJob`, same cadence and
  fail-closed pattern as `lib/BackgroundJob/ScheduledNotificationJob.php`):
  scans due `QueuedNotification` rows, re-evaluates the recipient's window
  live against wall-clock time (not a precomputed timestamp — see design.md
  timezone handling), and flushes through a new
  `AnnotationNotificationDispatcher::dispatchQueued()` entry point that reuses
  the existing channel fan-out unchanged.
- **Retire the unwired stub path**: `NotificationDigest` and
  `BatchNotificationJob` are superseded by the above (removed, not left as
  parallel dead code — see design.md).

## Impact

- **Affected specs**: `notificatie-engine` (this change's delta) —
  MODIFIES "Notifications MUST support batching and digest delivery" (adds
  fixed-time-of-day digest scheduling), MODIFIES "Users MUST be able to
  manage their notification preferences" (formalizes the quiet-hours scenario
  against a real mechanic), MODIFIES "Schemas MAY declare notifications via
  `x-openregister-notifications`..." (adds `critical` and `digest` keys to the
  normative dialect), ADDS a new requirement for the delivery-window
  preference API and the dispatcher queueing gate.
- **Affected code**: `lib/Service/Notification/AnnotationNotificationDispatcher.php`,
  `lib/Service/Notification/NotificationPreferenceService.php` (new sibling
  service, not modified), `lib/Service/Notification/NotificationAnnotationValidator.php`
  (grammar validation for `critical`/`digest` keys), `lib/Db/` (new
  `QueuedNotification` entity + mapper), `lib/Migration/` (new migration for
  `openregister_notification_queue`), `lib/BackgroundJob/`
  (`NotificationQueueFlushJob` added, `BatchNotificationJob` removed),
  `lib/Controller/` (new `NotificationDeliveryWindowController`),
  `appinfo/routes.php`.
- **Consumers**: unblocks scholiq's `grading` spec digest requirement
  (`scholiq-dev/openspec/specs/grading/spec.md:56`) and any other OR-consuming
  app that wants quiet hours / digest scheduling without reimplementing it
  per app (per ADR-031, apps declare via the dialect; the engine dispatches).
- **Backward compatibility**: a schema that declares no `critical`/`digest`
  keys, and a user with no stored delivery-window preference, gets exactly
  today's behaviour — immediate dispatch, preference-off gate unchanged. See
  design.md "Backward compatibility" for the explicit scenario.

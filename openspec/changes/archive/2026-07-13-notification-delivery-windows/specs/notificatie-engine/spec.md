notification-delivery-windows
---
status: draft
---
# Notificatie Engine — Quiet Hours / Delivery Windows + Per-Rule Digest Scheduling (delta)

## Purpose

Give the dispatcher a real delivery-window/quiet-hours mechanic (queue, then
deliver at the window edge, with an urgency bypass) and a fixed-time-of-day
digest schedule per rule, so apps consuming the `x-openregister-notifications`
dialect (ADR-031) get both as an engine feature instead of reimplementing
per app. Formalizes two scenarios (`:266-270`, quiet hours) that already
existed in the main spec as aspiration with no backing dialect key or API,
and replaces the unwired `NotificationDigest`/`BatchNotificationJob`
in-memory primitives with a durable, scannable queue.

## MODIFIED Requirements

### Requirement: Notifications MUST support batching and digest delivery

High-frequency events MUST NOT overwhelm recipients with individual notifications. The system MUST support configurable digest windows and batch summaries. In addition to the rolling digest window below, a rule MAY declare a fixed time-of-day digest schedule via a `digest` block (`schedule: "daily"|"weekly"`, `at: "HH:MM"`, optional `timezone` defaulting to the server timezone, optional `weekday: 0-6` required when `schedule: "weekly"`). A rule MUST NOT declare both a rolling digest period and a `digest` schedule block; schema-save validation MUST reject the combination with HTTP 422.

#### Scenario: Batch notifications for bulk import operations
- GIVEN a notification rule on `object.created` for schema `meldingen`
- AND 50 meldingen are created in a single bulk import within 10 seconds
- WHEN the notifications are processed
- THEN the system MUST send a single digest notification: `50 nieuwe meldingen aangemaakt in register "Zaakregistratie"`
- AND the digest MUST include a link to the object list view filtered to the newly created objects

#### Scenario: Throttle notifications per recipient within digest window
- GIVEN a digest window of 5 minutes is configured for a notification rule
- AND recipient `jan` receives 15 events within the window
- WHEN the digest window expires
- THEN a single digest notification MUST be delivered to `jan` summarizing all 15 events
- AND each individual event MUST NOT have generated a separate notification

#### Scenario: Configurable digest period per rule
- GIVEN notification rule A has digest period `0` (immediate) and rule B has digest period `300` (5 minutes)
- WHEN events trigger both rules
- THEN rule A MUST deliver notifications immediately (no batching)
- AND rule B MUST batch notifications within the 5-minute window

#### Scenario: Digest includes per-event summary
- GIVEN a digest window contains 3 created and 2 updated meldingen
- WHEN the digest is delivered
- THEN the digest message MUST include a breakdown: `3 nieuw, 2 gewijzigd`
- AND the digest MUST list the titles of affected objects (up to 10, then `... en 5 meer`)

#### Scenario: Rule declares a daily fixed-time digest schedule
- GIVEN a notification rule on schema `gradeEntry` declares `digest: {schedule: "daily", at: "07:00", timezone: "Europe/Amsterdam"}`
- AND 3 grade-published events fire for recipient `ouder-1` at 14:00, 16:30, and 21:00 the previous day
- WHEN `NotificationQueueFlushJob` ticks after 07:00 Europe/Amsterdam the following morning
- THEN a single digest notification summarizing the 3 events MUST be delivered to `ouder-1`
- AND no individual notification MUST have been delivered before the 07:00 flush

#### Scenario: Weekly digest schedule requires a weekday
- GIVEN a notification rule declares `digest: {schedule: "weekly", at: "08:00"}` with no `weekday`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422 and a structured error identifying the missing `weekday`

#### Scenario: Rolling digest period and fixed digest schedule are mutually exclusive
- GIVEN a notification rule declares both a rolling `digest period: 300` and `digest: {schedule: "daily", at: "07:00"}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422

### Requirement: Users MUST be able to manage their notification preferences

Users MUST be able to turn specific schema-declared notifications on or off (and optionally narrow channels) via a personal settings interface, without affecting other users' preferences. Preferences MUST be stored as override-only values in Nextcloud per-user app config under the `openregister` app (NOT in a `NotificationPreference` or `NotificationSubscription` table). When a user has no override for a `(schema, notification-key)` pair, the schema-declared default applies. Independently of the per-`(schema, notification)` override, a user MAY configure a global delivery window (quiet hours) via `NotificationDeliveryWindowService`, exposed through `GET`/`PUT /api/notification-delivery-window`, stored the same override-only way (a distinct per-user app-config key, `notification_delivery_window`) so a user with no configured window keeps today's immediate-delivery behaviour.

#### Scenario: User disables a specific notification
- GIVEN schema `meldingen` declares an `object_created` notification (default on) to group `behandelaars`
- AND user `jan` is a member of `behandelaars`
- WHEN `jan` turns off `(meldingen, object_created)` via `PUT /api/notification-preferences`
- THEN `jan` MUST NOT receive that notification
- AND other members of `behandelaars` MUST be unaffected

#### Scenario: User opts out of all notifications for a schema
- GIVEN multiple notification rules exist for schema `meldingen`
- WHEN user `jan` opts out of all notifications for schema `meldingen`
- THEN `jan` MUST NOT receive any notifications triggered by events on `meldingen` objects
- AND `jan` MUST still receive notifications for other schemas

#### Scenario: User sets global quiet hours and a suppressed notification is queued, not dropped
- GIVEN user `medewerker-1` configures quiet hours from 18:00 to 08:00 (Europe/Amsterdam) via `PUT /api/notification-delivery-window`
- WHEN a non-critical notification event triggers at 22:15 CET
- THEN the notification MUST be persisted as a `QueuedNotification` with reason `quiet-hours` (not dropped)
- AND `NotificationQueueFlushJob` MUST deliver it once 08:00 Europe/Amsterdam is reached (bounded by the job's 60-second tick)
- AND in-app notifications MUST still be stored (but not pushed) during quiet hours

#### Scenario: Admin overrides user preferences for critical notifications
- GIVEN a notification rule marked as `critical` = `true`
- AND user `jan` has opted out of email notifications
- WHEN the critical rule triggers
- THEN `jan` MUST still receive the notification on all channels including email
- AND the notification MUST be visually marked as critical in the notification panel

#### Scenario: Critical rule bypasses quiet hours
- GIVEN user `medewerker-1` has quiet hours configured from 18:00 to 08:00 (Europe/Amsterdam)
- AND a notification rule is declared with `critical: true`
- WHEN the critical rule triggers at 22:15 CET
- THEN the notification MUST be dispatched immediately, NOT queued
- AND the notification MUST still respect the existing preference-off gate (a `critical` rule bypasses quiet-hours queueing only, not the per-`(schema, notification)` on/off override)

#### Scenario: Retrieve effective user notification preferences
- GIVEN user `jan` has customised 2 of the notifications his accessible schemas declare
- WHEN `jan` calls `GET /api/notification-preferences`
- THEN the response MUST list every declared notification for his accessible schemas with its effective on/off (and channel) value
- AND for the 2 customised entries the effective value MUST reflect his overrides, with the remainder showing the schema defaults
- AND each entry MUST indicate whether its value came from the schema default or a user override

#### Scenario: User with no overrides sees all schema defaults
- GIVEN user `piet` has never set any override
- WHEN `piet` calls `GET /api/notification-preferences`
- THEN every entry MUST reflect the schema-declared default
- AND no per-user row or migration MUST be required for the read to succeed

#### Scenario: User with no configured delivery window is never queued for quiet hours
- GIVEN user `piet` has never called `PUT /api/notification-delivery-window`
- WHEN a non-critical notification event triggers for `piet` at any hour
- THEN `GET /api/notification-delivery-window` MUST return `{enabled: false}` (no stored value)
- AND the dispatcher MUST dispatch immediately, exactly as it did before this change

#### Scenario: Retrieve and update the delivery-window preference
- GIVEN user `medewerker-1` has no stored delivery-window preference
- WHEN `medewerker-1` calls `PUT /api/notification-delivery-window` with `{enabled: true, start: "18:00", end: "08:00", timezone: "Europe/Amsterdam"}`
- THEN the value MUST be stored as an override-only per-user app-config value (no migration, no table)
- AND a subsequent `GET /api/notification-delivery-window` MUST return the stored window
- AND a request for another user's window (or an unauthenticated request) MUST be rejected

### Requirement: Schemas MAY declare notifications via `x-openregister-notifications` with a normative channel block format

A schema MUST be allowed to include a top-level `x-openregister-notifications` block, which the system MUST treat as a map of notification name → spec.
Each spec declares
`trigger` (type + parameters), `filter` (Mongo-style operators
against the triggering object), `recipients` (one or more
recipient blocks), `channels` (one or more channel blocks),
optional `throttle`, optional `audit: bool`, optional `critical: bool`
(default `false` — bypasses quiet-hours queuing only; see "Users MUST be
able to manage their notification preferences"), optional `digest`
(fixed time-of-day schedule; see "Notifications MUST support batching and
digest delivery"). Schema-save validation MUST verify every reference and reject malformed
annotations with HTTP 422.

#### Channel block format (normative)

Every entry in `channels[]` MUST be an object with exactly one
mandatory field — `kind` — whose value is one of
`nc-notification`, `email`, `webhook`, `talk`, `activity`. The
remaining fields are kind-dependent:

| `kind` | Required fields | Optional fields | Notes |
|---|---|---|---|
| `nc-notification` | (none) | `subjectKey`, `messageKey`, `iconUrl`, `link`, `priority` (low/normal/high) | i18n keys resolve via the existing `Notifier` template registry. |
| `email` | (none) | `subjectKey`, `bodyTemplateKey`, `replyTo`, `senderKey` | SMTP config comes from NC; templates are i18n keys. The annotation MUST NOT inline raw email bodies. |
| `webhook` | `webhookId` (UUID of an existing `Webhook` entity registered by an admin) | `mappingKey` (template override) | The target URL MUST come from the existing Webhook entity registry — non-admin schema authors MUST NOT be able to set arbitrary URLs in the annotation, to prevent SSRF. Schema-save validation MUST reject `url:` directly inline in a channel block with `{ code: "notification-channel-webhook-inline-url-forbidden" }`. |
| `talk` | `room` (NC Talk conversation id or token) | `messageKey` | Resolves via `OCA\Talk\Manager`. Validation MUST verify the room exists at install time (best effort — re-checks at delivery). |
| `activity` | (none) | `subjectKey`, `objectType`, `objectName` | Routes through `OCP\Activity\IManager`; the existing `activity-provider` integration consumes it. |

A schema MAY declare more than one channel block per notification
(e.g. send both email and an `nc-notification`). Validation MUST
reject unknown keys, missing mandatory fields, or unsupported
`kind` values with HTTP 422.

#### Scenario: Webhook channel with inline URL is rejected
- GIVEN a notification declares `channels: [{ kind: "webhook", url: "https://attacker.example.com/x" }]`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "notification-channel-webhook-inline-url-forbidden" }`

#### Scenario: Webhook channel referencing a registered entity is accepted
- GIVEN an admin has registered a `Webhook` entity with UUID `abc-123` and target URL `https://allowed.example.com/hook`
- AND a notification declares `channels: [{ kind: "webhook", webhookId: "abc-123" }]`
- WHEN the schema is saved
- THEN the save MUST succeed
- AND delivery MUST POST to the URL stored on the registered Webhook entity, NOT to a URL supplied by the schema author

#### Scenario: `critical` key is accepted and defaults to false
- GIVEN a notification declares no `critical` key
- WHEN the schema is saved
- THEN the save MUST succeed
- AND the rule MUST behave as `critical: false` (subject to quiet-hours queuing, unchanged from pre-existing behaviour)

#### Scenario: `critical` key must be boolean
- GIVEN a notification declares `critical: "yes"`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422

## ADDED Requirements

### Requirement: The dispatcher MUST queue, not drop, non-critical notifications suppressed by an active delivery window or a not-yet-due digest schedule

Before delivering a non-broadcast channel (`nc-notification`, `email`, `activity`) to a recipient, the dispatcher MUST evaluate, in addition to the existing preference-off gate, whether the recipient has an active delivery window (quiet hours) covering the current moment, and whether the rule declares a `digest` schedule that has not yet reached its next fire time. When either applies AND the rule is not `critical: true`, the dispatcher MUST persist a `QueuedNotification` row (with the pre-resolved subject/message/channels/context so the eventual flush does not need to re-run recipient/template resolution) and record notification history with status `queued-quiet-hours` or `queued-digest` instead of `dispatched`. Broadcast channels (`webhook`, `talk`) are unaffected by this gate — they continue to fire once per dispatch, unchanged. `NotificationQueueFlushJob`, a 60-second `TimedJob`, re-evaluates each queued row's window/schedule live at each tick (against the current wall clock in the window's or schedule's declared IANA timezone, never a precomputed instant) and flushes rows whose condition has cleared, grouping same-`(rule, recipient)` rows into one summary message.

#### Scenario: Non-critical notification during quiet hours is queued and later flushed
- GIVEN recipient `jan` has quiet hours 18:00-08:00 (Europe/Amsterdam) configured
- AND a non-critical rule fires for `jan` at 20:00 CET
- WHEN the dispatcher evaluates the delivery-window gate
- THEN a `QueuedNotification` row MUST be created with `reason: "quiet-hours"`
- AND notification history MUST record status `queued-quiet-hours`
- AND WHEN `NotificationQueueFlushJob` next ticks after 08:00 Europe/Amsterdam, the notification MUST be delivered and history updated to `dispatched`

#### Scenario: Broadcast channels are unaffected by the delivery-window gate
- GIVEN a rule declares both `nc-notification` and `webhook` channels
- AND the recipient is inside their configured quiet hours
- WHEN the rule fires
- THEN the `nc-notification` channel MUST be queued per the delivery-window gate
- AND the `webhook` channel MUST fire immediately, unaffected (broadcast channels are not per-recipient and are out of scope for this gate)

#### Scenario: Window overlap — delivery waits for the later of quiet-hours-end and digest-due-time
- GIVEN recipient `ouder-1` has quiet hours until 08:00 Europe/Amsterdam
- AND the triggering rule also declares `digest: {schedule: "daily", at: "07:00", timezone: "Europe/Amsterdam"}`
- WHEN events fire for `ouder-1` overnight
- THEN the queued notifications MUST NOT flush at 07:00 (digest-due but still inside quiet hours)
- AND MUST flush at the next `NotificationQueueFlushJob` tick at or after 08:00 (quiet hours cleared)

#### Scenario: Live re-evaluation avoids stale precomputed delivery times across a DST transition
- GIVEN a `QueuedNotification` row was created with an advisory `due_at_hint` computed before a DST transition
- WHEN `NotificationQueueFlushJob` ticks after the DST transition
- THEN the flush decision MUST be based on a fresh evaluation of the recipient's window against the current wall clock in the window's declared timezone, NOT the stored `due_at_hint`

#### Scenario: No delivery window and no digest schedule — behaviour is unchanged from before this change
- GIVEN a recipient has no configured delivery window
- AND the triggering rule declares no `digest` schedule
- WHEN a non-critical notification fires
- THEN the dispatcher MUST dispatch immediately through the unchanged preference-off / rate-limit / coalesce gates
- AND no `QueuedNotification` row MUST be created

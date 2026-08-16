# Design: Notification Engine — Quiet Hours / Delivery Windows + Per-Rule Digest Scheduling

## Verified baseline (2026-07-12)

- `lib/Service/Notification/AnnotationNotificationDispatcher.php:476-506` —
  the ONLY existing per-recipient gate before channel fan-out is the
  preference-off check: `resolveEffective()` returns `{enabled, channels,
  source}`; `enabled === false` records `preference-off` and `continue`s
  (drops the event, no queueing).
- `lib/Service/Notification/NotificationPreferenceService.php` — override-only,
  zero-migration `IConfig::setUserValue` pattern, keyed
  `notification_pref/<schemaSlug>/<notificationKey>`. No time dimension.
- `lib/Service/Notification/NotificationDigest.php` — in-memory `array`
  bucket, explicitly documented as not DB-backed, zero callers.
- `lib/BackgroundJob/BatchNotificationJob.php` — real 5-minute `TimedJob`,
  but `run()` only logs "would dispatch digest to recipient=..."; no call
  into the dispatcher exists.
- `lib/BackgroundJob/ScheduledNotificationJob.php` — the fleet's existing
  60s-`TimedJob` + distributed-cache-throttle + per-object dedup pattern
  (`NotificationDedupeState`/`NotificationDedupeStateMapper`,
  `lib/Db/NotificationDedupeState.php`). This is the closest existing
  precedent for a periodic job that reads schema-declared config and fires
  through the dispatcher, and is the template `NotificationQueueFlushJob`
  follows.
- `lib/Service/Notification/NotificationCoalescer.php` — distributed-cache
  backed (`ICache`/`ICacheFactory`), "survives across requests + (with
  Redis) across cluster nodes" — but a cache is still eviction-prone and has
  no query surface (can't "find all rows due before now"), so it is the
  wrong backing store for a queue that must survive an 8-14 hour overnight
  quiet-hours window and be scannable by a flush job. A real table is used
  instead (see below).
- `lib/Controller/NotificationPreferencesController.php` +
  `appinfo/routes.php:770-771` — the existing per-`(schema, notification)`
  override API pattern (`GET`/`PUT`, `#[NoAdminRequired]`, resolves the
  current user from `IUserSession`, 401 when unauthenticated). The new
  delivery-window controller follows this exact shape.

## Key decisions

### One durable queue, two reasons to be in it

Quiet-hours suppression and digest-schedule batching are the same
mechanical problem: "hold this notification, then flush it at a computable
future point, possibly merged with siblings." Rather than building two
separate holding mechanisms, `QueuedNotification` carries a `reason` enum
(`quiet-hours` | `digest-schedule`) and a `dueAtHint` (advisory — see
timezone handling below). `NotificationQueueFlushJob` runs once, scanning
all rows, and groups rows sharing `(ruleId, recipient)` into one summary
message per flush, reusing the existing digest-breakdown rendering
(`openspec/specs/notificatie-engine/spec.md:214-218`, "3 nieuw, 2 gewijzigd").

Rejected: keep `NotificationDigest` (in-memory) and just wire
`BatchNotificationJob` to call it. Rejected because the enqueue call happens
inside a web/cron request's `AnnotationNotificationDispatcher::dispatchWithSchema()`
call, and the flush happens in a **separate** PHP-FPM/cron process tick —
there is no shared memory between them. Wiring the existing primitive as-is
would compile, pass unit tests that construct both objects in the same
test process, and silently drop every real queued notification in
production. This is exactly the trap the `NotificationDigest` docblock's
"future BatchNotificationJob" language was heading toward; this change
closes it with a real table instead of finishing the in-memory version.

### Live re-evaluation at flush time, not a precomputed `dueAt`

`QueuedNotification.dueAtHint` is stored for operator visibility (so an
admin inspecting the queue can see roughly when a row should flush) but is
**not** the sole flush trigger. `NotificationQueueFlushJob`, on each 60s
tick, re-resolves the recipient's live delivery-window state (current wall
clock in the window's declared IANA timezone) and the rule's live digest
schedule, and flushes a row only when the CURRENT evaluation says "outside
the window" / "digest time reached" — mirroring how
`ScheduledNotificationJob` re-evaluates `trigger.filter` live on every tick
rather than trusting a stored decision.

This avoids a whole class of DST bugs: if a precomputed UTC `dueAt` were
computed once at enqueue time (e.g. "quiet hours end at 08:00
Europe/Amsterdam" → stored as a UTC instant), a DST transition inside the
window would make that instant wrong by an hour. Live re-evaluation is
naturally correct because it asks "is NOW inside the window, in the
window's timezone" fresh every tick — the 60s job cadence bounds the
worst-case delivery lag to ~60s after the window/schedule genuinely opens,
which is well inside the "delivered at the window edge" requirement.

### Timezone handling

Both the per-user delivery window and the per-rule digest schedule store an
IANA timezone name (`Europe/Amsterdam`, matching the existing spec example
at `:267`), never a UTC offset. `DateTimeZone` + `DateTimeImmutable` compute
"is NOW inside [start, end) in this timezone" using the timezone's current
offset at evaluation time, so DST shifts are handled by PHP's tz database,
not by this code. When a delivery window has no configured timezone, it
defaults to the Nextcloud server timezone (existing
`OCP\IDateTimeZone` service, already used elsewhere in the codebase for
server-local rendering) — NOT a hardcoded UTC or `Europe/Amsterdam`
default, so this doesn't silently mis-suppress notifications for
non-NL-hosted instances.

### Window overlap: a row flushes only when neither reason still holds

When a recipient has both an active quiet-hours window AND a rule with a
`digest` schedule, and the two disagree (e.g., digest fires at 07:00 but
the recipient's quiet hours run until 08:00), the queued row is flushed
only once **both** conditions clear: quiet hours has ended AND (for
digest-scheduled rows) the digest time has been reached. In other words,
delivery time = `max(quiet-hours-window-end, digest-scheduled-time)` when
both apply to the same event; `critical: true` rules skip the quiet-hours
factor entirely (see below) but still respect a rule's own `digest`
schedule if declared (a rule cannot be simultaneously "always immediate"
and "batch daily" — the schema author picks one).

### `critical: true` — scope is quiet-hours bypass only

The dialect gains a rule-level `critical: bool` (default `false`). When
`true`, the dispatcher skips the quiet-hours factor and dispatches
immediately regardless of the recipient's delivery window (still subject to
the existing preference-off gate, rate-limit, and coalesce — this change
does not touch those). This directly resolves the gap-report ask ("urgency
override class for critical notifications").

The pre-existing spec scenario "Admin overrides user preferences for
critical notifications" (`openspec/specs/notificatie-engine/spec.md:272-277`)
describes a DIFFERENT, broader mechanic — forcing delivery even when a
recipient has opted a channel OFF via the preference override. That
scenario predates this change, was never implemented, and remains
unimplemented after this change; reusing the same `critical` key name for
both meanings would be a natural follow-up (a `critical` rule arguably
should bypass both gates) but is deliberately left out of this change's
scope to keep the diff reviewable and because it changes the preference-off
contract (`AnnotationNotificationDispatcher.php:489-501`), which is a
distinct, already-relied-upon guarantee ("opted-out user receives nothing",
`scholiq-notifications/spec.md:77-81`). Flagged as a DEFERRED_QUESTION below.

### Digest scheduling is additive to, not a replacement for, the rolling digest window

The existing `digest period` concept (`:191-218`, e.g. "batch within 5
minutes of the first event") is a ROLLING window — useful for absorbing a
burst (bulk import). The new `digest: {schedule, at, timezone, weekday?}`
is a FIXED time-of-day — useful for "one email at 07:00 every morning."
Both can coexist on different rules; a single rule declares at most one
digest mechanism (rolling `digest period` on the rule's `throttle`-adjacent
config, OR a `digest` block — mutually exclusive, validated at save time).

### Removing `NotificationDigest` / `BatchNotificationJob` instead of adding alongside

Both are dead code with zero callers in production paths (confirmed by
`grep -rn "NotificationDigest\b"` across `lib/Service` and `lib/Listener`
returning nothing outside the class's own file). Leaving them in place
alongside the new `QueuedNotification`-backed path would give the codebase
two competing, differently-shaped "hold notifications for later" primitives
— exactly the kind of duplication ADR-011 (search before implementing) asks
this change to avoid perpetuating. They are removed in this change's
implementation phase; any test coverage that exercised `NotificationDigest`
directly is replaced with coverage of `QueuedNotification`/`NotificationQueueFlushJob`.

## Data model

`openregister_notification_queue` (new table, new migration
`Version1DateYYYYMMDDHHMMSS.php` following the existing
`lib/Migration/Version1Date*.php` naming convention):

| column | type | notes |
|---|---|---|
| `id` | int, PK | standard `OCP\AppFramework\Db\Entity` id |
| `schema_id` | int | owning schema, FK-by-convention (matches `NotificationDedupeState.schema_id`) |
| `rule_key` | string | the `x-openregister-notifications` key |
| `recipient` | string | uid |
| `reason` | string | `quiet-hours` \| `digest-schedule` |
| `object_uuid` | string, nullable | the triggering object, when applicable |
| `payload` | text (json) | pre-resolved subject/message/channels/context, so flush does not need to re-run recipient/template resolution |
| `due_at_hint` | datetime | advisory, operator-visibility only (see "Live re-evaluation" above) |
| `created_at` | datetime | |

Indexed on `(recipient, reason)` for the flush job's per-recipient grouping
scan, and on `due_at_hint` for the operator-facing "how backed up is the
queue" admin view (follow-up, not part of this change's scope).

## Backward compatibility

- A schema with no `critical` key and no `digest` block: the dispatcher's
  new gate short-circuits to "not suppressed" when the rule declares no
  `digest` schedule AND the recipient has no stored delivery-window
  preference (`NotificationDeliveryWindowService::getForUser()` returns
  `null` → treated as "no window configured" → dispatch proceeds exactly as
  today, immediately, through the unchanged preference-off /
  rate-limit / coalesce gates).
- A user who has never called `PUT /api/notification-delivery-window`:
  `getForUser()` returns `null` (same zero-migration, no-per-user-row
  pattern as `NotificationPreferenceService`) — that user's notifications
  are never queued for quiet hours, regardless of what any schema declares.
- Existing notification history status values (`dispatched`, `rate-limited`,
  `coalesced`, `preference-off`) are unchanged; `queued-quiet-hours` and
  `queued-digest` (transitioning later to `dispatched` on flush) are
  additive values, so any consumer reading history by exact-match on the
  old four values continues to work unchanged for non-queued notifications.

## Deferred

- Unifying `critical: true` to also bypass the preference-off channel gate
  (the pre-existing `:272-277` scenario) — left for a follow-up change; see
  "critical: true — scope is quiet-hours bypass only" above.
- An admin-facing "queue depth / oldest queued item" dashboard — the table
  design supports it (indexed on `due_at_hint`) but no UI is built in this
  change.
- Per-app default delivery windows (e.g. an app declaring "K-12 teachers
  default to quiet hours 18:00-07:00") — this change only supports
  per-user, self-configured windows; app-level defaults are a plausible
  follow-up once usage data shows most users never touch the setting.

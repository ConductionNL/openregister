# Notification Engine — Scheduled Trigger Conditions & Per-Object Dedup

## Why

The notification engine's `scheduled` trigger is the fleet's designated
vehicle for **deadline and reminder notifications** ("task due soon",
"contract expires", "SLA approaching" — see
`hydra/openspec/fleet-notification-plan.md`, where "`scheduled` deadline
reminders" is named one of the two dominant implementable patterns
fleet-wide). But the trigger as shipped cannot express a deadline rule
at all. Verified against
`lib/BackgroundJob/ScheduledNotificationJob.php` (2026-06-11):

1. **Filters are flat equality only.** `matchesFilter()` documents and
   implements only `{ field: value }` strict-equality matching ("For v1
   we only support flat `{ field: value }` filters; richer shapes
   (operators, nested paths) are a v1.1 extension"). There is no way to
   say *"dueDate is within the next 24 hours"* or *"status is not
   done"* — the two conditions every deadline reminder needs.
2. **Dedup is per-rule, not per-object.** `isDue()` / `markFired()`
   throttle the *rule* to once per `intervalSec`, but each firing
   re-dispatches for **every** matching object. A task inside a 24-hour
   due window scanned hourly generates ~24 identical reminders; the
   only "fix" available today is a giant `intervalSec`, which then
   misses objects that enter the window between scans. The state lives
   in a distributed memory cache, so an eviction additionally restarts
   the spam cycle.

This is the verified blocker called out as **BLOCKED_EXTERNAL on
openregister** by planix's `due-date-reminder-dispatch` change
(`planix/openspec/changes/due-date-reminder-dispatch/proposal.md`,
"Cross-Project Dependencies" — both gaps cited verbatim, checked
against the same job file). The same two gaps block every other
`scheduled`-pattern rule in the fleet plan: procest "deadline
approaching", decidesk "action item overdue", shillinq "contract
renewal/expiry", softwarecatalog "contract expiry", opencatalogi "stale
federation", scholiq "credential expiring".

**Third gap from the fleet plan — already closed, verified.** The plan's
"Cross-cutting engine gap" (no field-changed condition on `updated`
triggers) has since been implemented and specced:
`AnnotationNotificationDispatcher::matches()` evaluates an optional
non-numeric `condition` block for `updated` triggers via
`fieldChangeConditionMatches()` (operators `changed` and `equals`, with
optional `from`, fail-closed when old/new data is absent), and
`openspec/specs/notificatie-engine/spec.md` covers it under "The
notification engine MUST support event-driven trigger types beyond
CRUD" with seven scenarios. This change therefore adds **no** delta for
the `updated` trigger; the fleet plan's prerequisite item 1 should be
marked done.

## What Changes

- **Relative-date and inequality operators on `scheduled` filters.** A
  filter entry value MAY be an operator object
  `{"operator": "...", "value": "..."}` alongside today's scalar
  equality form. v1.1 operators: `equals`, `notEquals`, `withinNext`
  (field date is between the scan's `now` and `now + value`, value an
  ISO-8601 duration), `olderThan` (field date is before
  `now - value`). Multiple filter entries remain ANDed. Unparsable
  dates/durations fail closed (no match). Scalar entries keep today's
  strict-equality semantics byte-for-byte.
- **Annotation validation for the operator grammar.** Saving a schema
  whose `scheduled` filter uses an unknown operator, a non-ISO-8601
  duration on a relative-date operator, or a malformed operator object
  MUST fail with HTTP 422 and a structured error — mirroring the
  existing "Throttle window grammar (normative)" requirement.
- **Per-object-per-rule dedup with re-arm.** A `scheduled` rule
  dispatches **at most once per (schema, rule, object, dedup
  fingerprint)**. The fingerprint defaults to the values of the
  filter's relative-date-operator fields (so a *changed due date
  re-arms the reminder*, while unrelated field churn does not); a rule
  MAY override the re-arm field set via `trigger.dedupeFields`. State
  is durable (database table, not memory cache) and pruned with its
  object / rule.
- New delta requirements on the `notificatie-engine` capability spec
  (the main spec currently mentions the `scheduled` trigger only
  parenthetically — this delta gives it normative requirements).

## Problem

1. **The fleet's most-wanted notification pattern is inexpressible.**
   Deadline reminders appear in 7+ apps' rows of the fleet notification
   plan, and the canonical consumer (planix `taskDueSoon`) has already
   written the exact rule it needs — `withinNext PT24H` on `dueDate`
   plus `notEquals done` on `status` — against an engine that supports
   neither operator.
2. **Without per-object dedup, enabling any scheduled rule is
   self-harm.** Hourly scan × 24-hour window = ~24 duplicate
   notifications per object per window. Users would disable the rule
   (or the app's notifications wholesale) on day one.
3. **Memory-cache state makes even the per-rule throttle unreliable.**
   `markFired()` writes to a distributed cache with a 30-day TTL and
   `isDue()` returns false when the cache is unavailable — eviction or
   cache backend swap silently re-fires or silently stops rules.
   Dedup state that prevents user-visible spam must be durable.

## Proposed Solution

- Extend `ScheduledNotificationJob::matchesFilter()` into a small
  `ScheduledFilterEvaluator` (unit-testable in isolation): scalar →
  equality (unchanged); operator object → `equals` / `notEquals` /
  `withinNext` / `olderThan`. Date parsing accepts the formats the
  schema layer already emits for `date` / `date-time` properties;
  durations via `DateInterval`. Fail closed on parse errors, log at
  debug.
- Extend `NotificationAnnotationValidator` with the filter-operator
  grammar (422 + structured error code on save, consistent with the
  throttle-grammar requirement).
- New `oc_openregister_notification_dedupe` table + mapper: one row per
  (schema id, rule key, object uuid) carrying the dedup fingerprint and
  last-dispatch timestamp. The job consults it before dispatching,
  upserts after, re-arms when the stored fingerprint differs from the
  current one, and prunes rows on object deletion, rule removal, and a
  retention sweep.
- The per-rule `intervalSec` throttle (`isDue`/`markFired`) stays as-is
  — it controls scan cost; the new dedup controls user-visible
  delivery.

## Out of scope

- Changes to the `updated` trigger — its field-change `condition` is
  already implemented and specced (see Why).
- Repeat/escalation delivery ("remind me again every 24 h until done")
  — a deliberate non-goal of v1.1 dedup; a future `redeliverEvery`
  extension can relax the at-most-once rule.
- Nested filter paths (`a.b.c`), OR-composition, and free-form
  expressions — filters stay flat AND-composed maps.
- Cron-expression scheduling (`intervalSec` stays the only cadence
  control).
- Per-recipient dedup keys — recipients are resolved per object at
  dispatch; the (rule, object, fingerprint) key already bounds each
  recipient to one notification per arming.
- The planix-side rule annotation, lead-time setting, and toggle
  write-through — those live in planix `due-date-reminder-dispatch`.

## See also

- `lib/BackgroundJob/ScheduledNotificationJob.php` — the verified v1
  behaviour this change extends (`matchesFilter()`, `isDue()`,
  `markFired()`).
- `lib/Service/Notification/AnnotationNotificationDispatcher.php` —
  `matches()` / `fieldChangeConditionMatches()`: the already-shipped
  `updated` field-change condition (no delta needed).
- `planix/openspec/changes/due-date-reminder-dispatch/proposal.md` —
  the blocked consumer whose requirements (operators `withinNext` +
  `notEquals`, per-(task, due date) dedup re-armed on due-date change)
  this change unblocks.
- `hydra/openspec/fleet-notification-plan.md` — fleet-wide demand for
  `scheduled` deadline reminders; its `updated`-trigger gap is recorded
  here as closed.
- `openspec/specs/notificatie-engine/spec.md` — the capability spec
  this change deltas ("Throttle window grammar" is the validation
  precedent; "event-driven trigger types" hosts the existing `updated`
  condition requirement).
- `FEATURE-REEVALUATION-2026-06-11/openregister.md` — wave-2 seed
  analysis.

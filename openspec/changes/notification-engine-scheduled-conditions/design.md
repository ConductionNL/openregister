# Design: Notification Engine — Scheduled Trigger Conditions & Per-Object Dedup

## Verified baseline (2026-06-11)

- `lib/BackgroundJob/ScheduledNotificationJob.php` — 60s TimedJob;
  per-rule `intervalSec` throttle via `isDue()`/`markFired()` in a
  distributed cache (`openregister_scheduled_notifs`, 30-day TTL,
  fail-closed skip when the cache is unavailable);
  `matchesFilter()` = flat `{field: value}` strict equality with an
  explicit "richer shapes (operators, nested paths) are a v1.1
  extension" comment. Every due firing iterates ALL matching objects
  and dispatches for each — no per-object state of any kind.
- `lib/Service/Notification/AnnotationNotificationDispatcher.php` —
  `matches()` already handles the `updated` trigger's non-numeric
  field-change `condition` via `fieldChangeConditionMatches()`
  (`changed`, `equals` + optional `from`, fail-closed on missing
  old/new data). **Already specced** in
  `openspec/specs/notificatie-engine/spec.md` ("event-driven trigger
  types beyond CRUD"). The fleet-notification-plan's `updated` gap is
  closed — nothing to do here.
- `lib/Service/Notification/NotificationAnnotationValidator.php` —
  save-time validation precedent (throttle window grammar, 422 +
  structured error).

## Key decisions

### Operator grammar: discriminate on value shape, not a new filter key

A filter entry value that is an array/object with an `operator` key is
an operator condition; any scalar stays v1 equality. This keeps every
existing annotation valid unchanged and needs no `filterVersion`
discriminator. The grammar is deliberately the same shape planix
already wrote into its (blocked) `taskDueSoon` rule:

```jsonc
"trigger": {
  "type": "scheduled",
  "intervalSec": 3600,
  "filter": {
    "dueDate": { "operator": "withinNext", "value": "PT24H" },
    "status":  { "operator": "notEquals",  "value": "done"  }
  }
}
```

Operators v1.1: `equals`, `notEquals`, `withinNext`, `olderThan`.
Explicitly NOT included: nested paths, `or` composition, numeric
ordering (`lt`/`gt` — those belong to `calculatedChange`/`threshold`),
cron expressions.

### Evaluation: extract `ScheduledFilterEvaluator`

`matchesFilter()` moves out of the job into
`lib/Service/Notification/ScheduledFilterEvaluator.php` so the grammar
is unit-testable without a TimedJob harness and reusable by the
validator (one source of truth for "what is a valid operator"). The
evaluator receives `now` as a parameter — one clock per scan, so a long
scan can't drift objects across the window boundary mid-run.

Date parsing: `DateTimeImmutable` accepting the formats the schema
layer emits for `date`/`date-time` (ISO-8601, with date-only values
normalised to 00:00:00 instance-timezone). Durations: `DateInterval`
from the ISO-8601 string; validation rejects strings `DateInterval`
cannot parse. Parse failure on the *object value* at evaluation time =
no match + debug log (fail closed, not noisy); parse failure on the
*rule value* is unreachable at runtime because save-time validation
rejects it (and the evaluator still fails closed defensively).

### Dedup: database table, fingerprint re-arm

New table `oc_openregister_notification_dedupe`:

| column | notes |
|---|---|
| `id` | autoincrement |
| `schema_id` | int, indexed |
| `rule_key` | string (annotation key) |
| `object_uuid` | string, indexed |
| `fingerprint` | string — SHA-1 of the JSON-encoded watched-field values |
| `dispatched_at` | datetime of last dispatch |
| `seen_at` | datetime of last evaluation match (drives retention sweep) |

Unique index on (`schema_id`, `rule_key`, `object_uuid`).

Why a table and not the distributed cache: the per-rule throttle merely
risks an early/late scan when state is lost; per-object state loss is
user-visible spam (replay to every recipient). Durability is a spec
requirement, so memory cache is disqualified by design.

Fingerprint = watched-field values only. Default watched fields = the
relative-date-operator fields, because those carry the "deadline
identity" (planix: re-arm on `dueDate` change, NOT on `status` churn).
`trigger.dedupeFields` overrides for rules whose re-arm semantics
differ. Constant fingerprint (no watched fields) = fire-once-per-object
semantics, which is the correct conservative default for pure
state-filters like `olderThan`-less equality rules.

Flow per matching object: read row → no row or fingerprint differs →
dispatch + upsert (`fingerprint`, `dispatched_at`, `seen_at`) → equal
fingerprint → update `seen_at` only, skip dispatch.

Pruning: (a) object-deletion listener / purge path deletes by
`object_uuid`; (b) annotation save diff deletes rows for removed rule
keys; (c) retention sweep inside the existing job run deletes rows with
`seen_at` older than 90 days (configurable app config
`notification_dedupe_retention_days`). All prunes only ever re-arm.

### What stays unchanged

- `isDue()`/`markFired()` per-rule cadence throttle (cache-backed) —
  scan-cost control, orthogonal to delivery dedup.
- Dispatcher, channels, recipients, preference resolution, throttle
  grammar — untouched; the job still calls
  `AnnotationNotificationDispatcher::dispatch(..., 'scheduled', ...)`.
- The `updated`/`calculatedChange`/`transition`/`threshold` trigger
  paths.

## Consumer contract check (planix `due-date-reminder-dispatch`)

| Consumer requirement | Covered by |
|---|---|
| `withinNext` ISO-duration on `dueDate` | operator requirement, scenario 1 |
| `notEquals done` on `status` | operator requirement, AND scenario |
| At most one reminder per (task, due date) | dedup requirement, scenarios 1+3 |
| Changed due date re-arms | dedup requirement, scenario 2 |
| Hourly `intervalSec: 3600` | existing v1 behaviour, unchanged |

## Risks

- **Behaviour change for existing scheduled rules:** any rule relying
  on "re-notify every interval" goes at-most-once. Survey: no shipped
  fleet annotation uses `scheduled` in production yet (the pattern is
  blocked by exactly this change), so the default flip is safe now and
  never later.
- **Table growth:** bounded by matched-objects × rules; `seen_at`
  retention sweep caps it.
- **Clock skew across workers:** single `now` per scan + half-open
  window means an object is matched by whichever scan first sees it
  inside the window; dedup makes double-match harmless.

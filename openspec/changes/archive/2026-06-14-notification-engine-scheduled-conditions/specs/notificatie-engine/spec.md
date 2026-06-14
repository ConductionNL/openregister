notification-engine-scheduled-conditions
---
status: draft
---
# Notificatie Engine — Scheduled Trigger Conditions & Per-Object Dedup (delta)

## Purpose

Give the `scheduled` trigger normative requirements for (a) a filter
operator grammar that can express deadline windows (`withinNext`,
`olderThan`) and inequality (`notEquals`) alongside the existing flat
equality form, with save-time validation, and (b) durable
per-object-per-rule deduplication that prevents re-notification on
every scan interval and re-arms when the watched field value changes.
The `updated` trigger's field-change `condition` is already specced in
the main `notificatie-engine` spec and is NOT modified here.

## ADDED Requirements

### Requirement: Scheduled trigger filters MUST support relative-date and inequality operators

A `scheduled` trigger's `filter` MUST be evaluated as a flat map of object-data field names to conditions, ANDed together. Each condition MUST be accepted in two forms:

- **Scalar (v1, unchanged):** `{"status": "open"}` — strict equality
  against the object's field value, byte-for-byte the existing
  behaviour.
- **Operator object (v1.1):** `{"<field>": {"operator": "<op>",
  "value": <value>}}` with the operators:
  - `equals` — field value equals `value` (same comparison semantics as
    the scalar form).
  - `notEquals` — field value does not equal `value`. A missing/null
    field value satisfies `notEquals` for any non-null `value`.
  - `withinNext` — the field value, parsed as a date or date-time, lies
    in the half-open window `(now, now + value]`, where `value` is an
    ISO-8601 duration (e.g. `PT24H`, `P7D`) and `now` is the evaluating
    scan's clock.
  - `olderThan` — the field value, parsed as a date or date-time, lies
    before `now - value`, `value` an ISO-8601 duration.

Relative-date operators MUST fail closed: when the field value is
missing, null, or not parseable as a date/date-time, the condition does
NOT match (and the engine logs at debug level, not warning — unfilled
date fields are normal data). All filter entries MUST hold for the
object to match (AND semantics, unchanged).

#### Scenario: Deadline window matched with `withinNext`
- GIVEN a `scheduled` rule with filter `{"dueDate": {"operator": "withinNext", "value": "PT24H"}}`
- AND an object whose `dueDate` is 6 hours after the scan's `now`
- WHEN the scheduled job evaluates the filter
- THEN the object matches and the rule dispatches for it

#### Scenario: Object outside the `withinNext` window does not match
- GIVEN the same rule
- AND an object whose `dueDate` is 3 days after `now`, and another whose `dueDate` is 1 hour before `now`
- WHEN the scheduled job evaluates the filter
- THEN neither object matches (the window is future-only and bounded by the duration)

#### Scenario: `olderThan` selects stale objects
- GIVEN a `scheduled` rule with filter `{"lastSyncedAt": {"operator": "olderThan", "value": "P7D"}}`
- AND an object whose `lastSyncedAt` is 10 days before `now`
- WHEN the scheduled job evaluates the filter
- THEN the object matches

#### Scenario: `notEquals` excludes terminal states and combines with AND semantics
- GIVEN a `scheduled` rule with filter `{"dueDate": {"operator": "withinNext", "value": "PT24H"}, "status": {"operator": "notEquals", "value": "done"}}`
- AND object A with `dueDate` in 6 hours and `status: "open"`, and object B with `dueDate` in 6 hours and `status: "done"`
- WHEN the scheduled job evaluates the filter
- THEN object A matches and object B does not

#### Scenario: Unparsable date fails closed
- GIVEN a `scheduled` rule with a `withinNext` condition on `dueDate`
- AND an object whose `dueDate` value is the string `"soon"`
- WHEN the scheduled job evaluates the filter
- THEN the object does NOT match
- AND no warning-level log entry is produced for it

#### Scenario: Scalar filters keep v1 equality semantics
- GIVEN a `scheduled` rule with filter `{"status": "open"}` (scalar form)
- WHEN the scheduled job evaluates the filter
- THEN matching is strict equality exactly as before this change, with no operator parsing applied

### Requirement: Scheduled filter operator grammar MUST be validated when the schema is saved

The notification-annotation validator MUST reject, with HTTP 422 and a
structured error, any `scheduled` trigger filter entry that is an
operator object with: an unknown `operator`; a missing `value`; or a
`value` that is not a valid ISO-8601 duration when the operator is
`withinNext` or `olderThan`. Scalar filter entries and well-formed
operator objects MUST be accepted. The structured error MUST identify
the rule key, the field, and the offending value (consistent with the
existing throttle-window-grammar requirement).

#### Scenario: Unknown operator rejected at save time
- GIVEN a schema whose `scheduled` rule filter contains `{"dueDate": {"operator": "near", "value": "PT24H"}}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include a structured error naming the rule key, the field `dueDate`, and the unknown operator `near`

#### Scenario: Invalid duration rejected at save time
- GIVEN a schema whose `scheduled` rule filter contains `{"dueDate": {"operator": "withinNext", "value": "24h"}}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the structured error MUST state that `withinNext` requires an ISO-8601 duration (e.g. `PT24H`)

#### Scenario: Well-formed operator filter accepted
- GIVEN a schema whose `scheduled` rule filter combines a scalar entry and `withinNext`/`notEquals` operator objects with valid values
- WHEN the schema is saved
- THEN the save MUST succeed

### Requirement: Scheduled rules MUST deduplicate dispatch per object and re-arm on watched-field change

A `scheduled` rule MUST dispatch at most once per (schema, rule key,
object, dedup fingerprint). The dedup fingerprint is derived from the
object's current values of the rule's **watched fields**:

- By default, the watched fields are the filter fields that use a
  relative-date operator (`withinNext` / `olderThan`).
- A rule MAY override the watched-field set with
  `trigger.dedupeFields` (a non-empty array of field names, validated
  at save time against the same 422 contract).
- When a rule has neither relative-date operators nor `dedupeFields`,
  the fingerprint is constant — the rule dispatches at most once per
  object until its dedup state is pruned.

When an object matches the filter on a scan: if no dedup state exists
for (schema, rule, object), or the stored fingerprint differs from the
current one, the engine dispatches and stores the current fingerprint;
if the stored fingerprint equals the current one, the engine MUST NOT
dispatch again. The per-rule `intervalSec` throttle is unchanged and
independent: it bounds scan frequency, not delivery count.

#### Scenario: No re-notification on subsequent scans
- GIVEN an hourly (`intervalSec: 3600`) rule with `withinNext PT24H` on `dueDate`
- AND an object that entered the due window and was notified on the previous scan
- WHEN the next 23 hourly scans evaluate the same object with an unchanged `dueDate`
- THEN no further notification is dispatched for that object

#### Scenario: Changed due date re-arms the reminder
- GIVEN the same rule and an object already notified for `dueDate = 2026-06-12T09:00`
- WHEN the object's `dueDate` is moved to `2026-06-20T09:00` and a later scan finds it inside the window again
- THEN exactly one new notification is dispatched for the new due date

#### Scenario: Unrelated field churn does not re-arm
- GIVEN the same rule (watched field defaults to `dueDate`) and an already-notified object
- WHEN the object's `description` and `status` change while `dueDate` stays the same and the object still matches the filter
- THEN no new notification is dispatched

#### Scenario: `dedupeFields` overrides the watched-field set
- GIVEN a rule with `trigger.dedupeFields: ["assignee"]`
- AND an already-notified object
- WHEN the object's `assignee` changes and a later scan matches it again
- THEN exactly one new notification is dispatched

#### Scenario: Distinct objects are deduplicated independently
- GIVEN two objects A and B that both enter the due window between two scans
- WHEN the next scan runs
- THEN one notification is dispatched for A and one for B, each tracked by its own dedup state

### Requirement: Scheduled dedup state MUST be durable and pruned with its object and rule

Per-object dedup state MUST be persisted in the database (NOT in a
memory/distributed cache), so that cache eviction, restarts, and
backend swaps can neither replay nor suppress notifications. The state
MUST be pruned when: the object is deleted (or purged after soft
delete); the rule is removed from the schema's
`x-openregister-notifications` annotation; or the state row exceeds a
retention horizon (default 90 days since last evaluation match) — a
background sweep reclaims expired rows. Pruned state simply re-arms the
object; it never causes retroactive dispatch.

#### Scenario: Dedup survives cache eviction and restart
- GIVEN an object already notified by a scheduled rule
- WHEN the distributed cache is flushed and the background-job worker restarts
- AND the next scan matches the object with an unchanged fingerprint
- THEN no duplicate notification is dispatched

#### Scenario: Rule removal prunes its state
- GIVEN dedup state rows exist for rule `taskDueSoon` on a schema
- WHEN `taskDueSoon` is removed from the schema's notification annotation and the prune runs
- THEN the rule's dedup rows are deleted
- AND re-adding the rule later treats all objects as not-yet-notified

#### Scenario: Object deletion prunes its state
- GIVEN dedup state exists for an object
- WHEN the object is deleted and purged
- THEN its dedup rows are removed by the prune path

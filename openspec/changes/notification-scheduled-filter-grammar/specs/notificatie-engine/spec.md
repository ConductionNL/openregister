## MODIFIED Requirements

### Requirement: Scheduled trigger filters MUST support relative-date and inequality operators

A `scheduled` trigger's `filter` MUST be supported as a flat map of object-data field names to conditions, ANDed together.
Each condition MUST be accepted in four forms:

- **Scalar (v1, unchanged):** `{"status": "open"}` — strict equality
  against the object's field value, byte-for-byte the existing
  behaviour.
- **Bare list (v1.2):** `{"status": ["open","pending"]}` — shorthand,
  exactly equivalent to `{"status": {"operator": "in", "values":
  ["open","pending"]}}`.
- **Operator object (v1.1, extended in v1.2):** `{"<field>": {"operator":
  "<op>", "value": <value>}}` — or `"values": [<value>, …]` for the
  membership operators — with the operators:
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
  - `in` — the field value is a member of the `values` list.
  - `notIn` — the field value is not a member of the `values` list. A
    missing/null field value satisfies `notIn` for any non-empty list.
  - `before` — the field value, parsed as a date or date-time, is
    strictly earlier than the condition's reference instant.
  - `after` — the field value, parsed as a date or date-time, is
    strictly later than the condition's reference instant.
- **Combinator (v1.2):** the reserved top-level keys `all` and `any`,
  each taking a non-empty list of clause objects of the shape
  `{"field": "<name>", "operator": "<op>", "value"|"values": …}`. `all`
  matches when every clause matches; `any` matches when at least one
  clause matches. A clause MAY itself be `{"all": […]}` or `{"any":
  […]}`; nesting MUST be bounded and a filter nested deeper than the
  bound MUST be reported as invalid rather than evaluated.

Comparison semantics for `equals`, `notEquals`, `in` and `notIn` MUST be
type-strict, identical to the v1 scalar form. The type-coercing
comparison used by `created`-trigger filters MUST NOT be applied to
scheduled filters.

Membership over an array-valued field MUST be defined: when the object's
field value is itself a list, `in` matches when the intersection with
`values` is non-empty, and `notIn` matches when it is empty.

The reference instant for `before` and `after` MUST be accepted in three
spellings, resolved against the scan's `now`:

- the literal string `now`;
- an ISO-8601 date or date-time (e.g. `2026-01-01`,
  `2026-01-01T00:00:00Z`), taken absolutely;
- a signed ISO-8601 duration relative to `now` — `P7D` means `now + 7
  days`, `-P7D` means `now - 7 days`.

Relative-date operators MUST fail closed: when the field value is
missing, null, or not parseable as a date/date-time, the condition does
NOT match (and the engine logs at debug level, not warning — unfilled
date fields are normal data). A reference instant that cannot be
resolved MUST likewise fail closed. An unknown operator MUST fail
closed. All top-level filter entries MUST hold for the object to match
(AND semantics, unchanged); an empty filter map MUST match.

An empty `all` list MUST match (vacuously true, consistent with the
empty filter map). An empty `any` list MUST NOT match.

`all` and `any` are RESERVED top-level keys. A schema field named `all`
or `any` MUST be filtered through a combinator clause
(`{"all":[{"field":"all", …}]}`) rather than a top-level entry.

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

#### Scenario: Bare list means set membership
- GIVEN a `scheduled` rule with filter `{"lifecycle": ["open","in-uitvoering"]}`
- AND object A with `lifecycle: "open"`, object B with `lifecycle: "afgerond"`, and object C with no `lifecycle` value
- WHEN the scheduled job evaluates the filter
- THEN object A matches and objects B and C do not

#### Scenario: `notIn` excludes a terminal set and admits missing values
- GIVEN a `scheduled` rule with filter `{"state": {"operator": "notIn", "values": ["paid","written-off","voided"]}}`
- AND object A with `state: "received"`, object B with `state: "paid"`, and object C with no `state` value
- WHEN the scheduled job evaluates the filter
- THEN objects A and C match and object B does not

#### Scenario: Membership over an array-valued field uses intersection
- GIVEN a `scheduled` rule with filter `{"tags": ["urgent","escalated"]}`
- AND object A with `tags: ["routine","urgent"]` and object B with `tags: ["routine"]`
- WHEN the scheduled job evaluates the filter
- THEN object A matches and object B does not

#### Scenario: `before "now"` selects past-due objects
- GIVEN a `scheduled` rule with filter `{"dueDate": {"operator": "before", "value": "now"}}`
- AND object A whose `dueDate` is 2 days before the scan's `now` and object B whose `dueDate` is 2 days after it
- WHEN the scheduled job evaluates the filter
- THEN object A matches and object B does not

#### Scenario: `after` with a signed duration reference instant
- GIVEN a `scheduled` rule with filter `{"reviewDate": {"operator": "after", "value": "-P30D"}}`
- AND object A whose `reviewDate` is 10 days before `now` and object B whose `reviewDate` is 60 days before `now`
- WHEN the scheduled job evaluates the filter
- THEN object A matches (it is later than `now - 30 days`) and object B does not

#### Scenario: Unresolvable reference instant fails closed
- GIVEN a `scheduled` rule with filter `{"dueDate": {"operator": "before", "value": "soon"}}`
- WHEN the scheduled job evaluates the filter against any object
- THEN no object matches
- AND the rule dispatches for nobody

#### Scenario: `all` combinator ANDs its clauses
- GIVEN a `scheduled` rule with filter `{"all": [{"field": "state", "operator": "notIn", "values": ["paid","voided"]}, {"field": "dueDate", "operator": "before", "value": "now"}]}`
- AND object A with `state: "received"` and a past `dueDate`, object B with `state: "paid"` and a past `dueDate`, and object C with `state: "received"` and a future `dueDate`
- WHEN the scheduled job evaluates the filter
- THEN only object A matches

#### Scenario: `any` combinator ORs its clauses
- GIVEN a `scheduled` rule with filter `{"any": [{"field": "status", "operator": "equals", "value": "escalated"}, {"field": "dueDate", "operator": "before", "value": "now"}]}`
- AND object A with `status: "escalated"` and a future `dueDate`, object B with `status: "open"` and a past `dueDate`, and object C with `status: "open"` and a future `dueDate`
- WHEN the scheduled job evaluates the filter
- THEN objects A and B match and object C does not

#### Scenario: Empty combinator lists resolve in opposite directions
- GIVEN a `scheduled` rule with filter `{"all": []}` and another with filter `{"any": []}`
- WHEN the scheduled job evaluates each filter against any object
- THEN every object matches the `all` rule
- AND no object matches the `any` rule

#### Scenario: A combinator entry ANDs with its sibling top-level entries
- GIVEN a `scheduled` rule with filter `{"register": "contracts", "any": [{"field": "status", "operator": "equals", "value": "expired"}, {"field": "renewalDecisionDate", "operator": "before", "value": "now"}]}`
- AND object A with `register: "contracts"` and `status: "expired"`, and object B with `register: "invoices"` and `status: "expired"`
- WHEN the scheduled job evaluates the filter
- THEN object A matches and object B does not

### Requirement: Scheduled filter operator grammar MUST be validated when the schema is saved

The notification-annotation validator MUST report a structured error for
any `scheduled` trigger filter entry whose shape the evaluator cannot
execute. Specifically it MUST report:

- an operator object with an unknown `operator`;
- an operator object with a missing `value` (or missing `values` for the
  membership operators);
- a `value` that is not a valid ISO-8601 duration when the operator is
  `withinNext` or `olderThan`;
- a `value` that is not a resolvable reference instant when the operator
  is `before` or `after`;
- a `values` key that is not a non-empty list for `in` / `notIn`;
- a combinator whose clause list is not a list, or whose clause is not an
  object carrying a `field` key (or a nested combinator), or which nests
  deeper than the supported bound;
- **any other non-scalar entry that is neither a bare list, nor a
  recognised operator object, nor a recognised combinator.**

The final bullet is the load-bearing one: an entry MUST NOT be accepted
merely because it lacks an `operator` key. Scalar entries, bare lists,
well-formed operator objects and well-formed combinators MUST be
accepted, and nothing else MUST be.

An operator object that carries the key `op` instead of `operator` MUST
produce an error whose message names `operator` as the expected key, so
the author is told what to write rather than being left with an accepted
rule that never fires.

Every structured error MUST identify the rule key, the field (or clause
path), and the offending value, consistent with the existing
throttle-window-grammar requirement.

The validator's findings MUST be reported by the schema-save path. The
save path MAY continue to treat a malformed OPTIONAL notification
annotation as non-fatal for the schema itself, so that one bad rule
cannot abort a register import; where it does so, the findings MUST
still be surfaced to the operator rather than only written to a log that
nothing reads.

The set of recognised operators, entry forms and combinators defined by
the preceding requirement is the single source of truth for this
validation. Any external checker that judges the same filters MUST be
derived from it.

#### Scenario: Unknown operator rejected at save time
- GIVEN a schema whose `scheduled` rule filter contains `{"dueDate": {"operator": "near", "value": "PT24H"}}`
- WHEN the schema is saved
- THEN the validator MUST produce a structured error naming the rule key, the field `dueDate`, and the unknown operator `near`

#### Scenario: Invalid duration rejected at save time
- GIVEN a schema whose `scheduled` rule filter contains `{"dueDate": {"operator": "withinNext", "value": "24h"}}`
- WHEN the schema is saved
- THEN the validator MUST produce a structured error stating that `withinNext` requires an ISO-8601 duration (e.g. `PT24H`)

#### Scenario: Well-formed operator filter accepted
- GIVEN a schema whose `scheduled` rule filter combines a scalar entry and `withinNext`/`notEquals` operator objects with valid values
- WHEN the schema is saved
- THEN the validator MUST produce no errors for that filter

#### Scenario: An unrecognised array entry is no longer silently accepted
- GIVEN a schema whose `scheduled` rule filter contains `{"isEnabled": {"op": "equals", "value": true}}`
- WHEN the schema is saved
- THEN the validator MUST produce a structured error for that entry
- AND the error message MUST name `operator` as the expected key

#### Scenario: An unknown operator inside a combinator clause is reported
- GIVEN a schema whose `scheduled` rule filter contains `{"all": [{"field": "nextRun", "operator": "lt", "value": "now"}]}`
- WHEN the schema is saved
- THEN the validator MUST produce a structured error identifying the clause and the unknown operator `lt`

#### Scenario: Bare list and combinator forms are accepted
- GIVEN a schema with one `scheduled` rule filtered by `{"lifecycle": ["open","in-uitvoering"]}` and another filtered by `{"all": [{"field": "state", "operator": "notIn", "values": ["paid"]}, {"field": "dueDate", "operator": "before", "value": "now"}]}`
- WHEN the schema is saved
- THEN the validator MUST produce no errors for either filter

#### Scenario: A membership operator without a values list is reported
- GIVEN a schema whose `scheduled` rule filter contains `{"status": {"operator": "in", "value": "open"}}`
- WHEN the schema is saved
- THEN the validator MUST produce a structured error stating that `in` requires a non-empty `values` list

## ADDED Requirements

### Requirement: A scheduled filter the evaluator cannot execute MUST be mechanically detectable before merge

Every filter form the evaluator recognises is defined in one place, and
that definition MUST be reachable by a mechanical check that runs on a
change before it merges. The check MUST classify every `scheduled`
trigger filter entry declared in a register annotation as executable or
not executable, and MUST treat "not executable" as a failure rather than
a warning.

The check MUST be capable of judging register annotations that live
outside the OpenRegister repository, because the declarative
notification surface is consumed by every app in the fleet and a rule
authored in a consuming app is exactly the case that has failed in
practice.

Detection MUST NOT rely solely on the schema-save validator, because the
save path deliberately does not fail the save on a malformed optional
annotation and therefore cannot prevent a dead rule from being
installed.

#### Scenario: A newly authored dead filter fails the check
- GIVEN a change that adds a `scheduled` rule whose filter is `{"isEnabled": {"op": "equals", "value": true}}`
- WHEN the mechanical check runs on that change
- THEN the check MUST report the rule key, the file, and the reason the entry is not executable
- AND the check MUST fail

#### Scenario: The extended grammar forms pass the check
- GIVEN a change that adds a `scheduled` rule filtered by a bare list and another filtered by an `all` combinator over `notIn` and `before` clauses
- WHEN the mechanical check runs on that change
- THEN the check MUST report no findings for those rules

#### Scenario: Absence of register annotations is not reported as a pass
- GIVEN a change in a repository that declares no register annotations at all
- WHEN the mechanical check runs
- THEN the check MUST report that its scope was empty
- AND MUST NOT report a pass over nothing

### Requirement: The scheduled filter MUST be expressible as a filter tree that a query planner can consume

The evaluator MUST derive its decision from a normalised representation
of the filter — a tree of leaf conditions (`field`, operator, operand)
under `all` / `any` nodes — rather than from a direct walk of the raw
annotation. The same normalised representation MUST be the input from
which a future database-side filter is derived, so that in-memory
evaluation and any pushed-down evaluation cannot disagree about what a
rule means.

Every operator in the grammar MUST be expressible as a single-column
predicate over the object's data: equality, negated equality, set
membership, negated set membership, and a date range bound. `all` and
`any` MUST correspond to conjunction and disjunction of those
predicates.

Where a filter, or part of one, cannot be pushed down, the engine MUST
fall back to evaluating that part in memory and MUST produce the same
result as evaluating the whole filter in memory.

#### Scenario: In-memory and pushed-down evaluation agree
- GIVEN a `scheduled` rule whose filter is fully expressible as database predicates
- WHEN the same object set is evaluated once entirely in memory and once with the filter applied by the database
- THEN both evaluations MUST select exactly the same objects

#### Scenario: A partially pushable filter still evaluates correctly
- GIVEN a `scheduled` rule that combines a pushable membership condition with a condition that cannot be pushed down
- WHEN the scheduled scan runs
- THEN the objects selected MUST be identical to those selected by evaluating the whole filter in memory

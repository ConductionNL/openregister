---
status: draft
---
# Calculations Annotation (delta — zoeken-filteren)

## Purpose
Extend the implemented `zoeken-filteren` spec with a declarative `x-openregister-calculations` schema annotation: typed computed/derived fields with a small expression DSL, evaluated at save (materialised, persisted, aggregatable) or at render (virtual). Pairs with the parallel `aggregations-annotation` change — the placeholder resolver is shared; an aggregation MAY target a materialised calculation field.

## ADDED Requirements

### Requirement: Schemas MAY declare calculations via `x-openregister-calculations`
A schema MAY include a top-level `x-openregister-calculations` block — a map of calculation name → spec. Each spec declares `type` (string / integer / boolean / number / array), `expression` (a v1 DSL string), optional `materialise: bool` (default false = virtual; true = persisted at save), and optional `computeOn: ["save", "transition:<name>"]`. Schema-save validation MUST verify every property reference, every function in the v1 vocabulary, and reject cycles.

#### Scenario: A virtual calculation renders at response time
- GIVEN schema `meeting` with calculation `displayTitle: { type: "string", expression: "concat(title, ' (', formatDate(scheduledDate, 'yyyy-MM-dd'), ')')" }` and `materialise: false`
- AND a meeting `m1` with `title: "Vergadering"` and `scheduledDate: "2026-05-04T10:00:00Z"`
- WHEN a client GETs the meeting with `_include=calculations`
- THEN the response body MUST include `displayTitle: "Vergadering (2026-05-04)"`
- AND the value MUST NOT be persisted

#### Scenario: A materialised calculation is persisted at save
- GIVEN schema `action-item` with calculation `daysFromCreatedToCompleted: { type: "integer", expression: "diffDays(completedAt, createdAt)", materialise: true }`
- AND an existing action item with `createdAt: 2026-04-01`, no `completedAt`, no calculation value
- WHEN the item is updated to `completedAt: 2026-04-08, taskStatus: "completed"`
- THEN before persistence, the on-save listener MUST run the evaluator
- AND patch `daysFromCreatedToCompleted = 7` into the object payload
- AND the persisted object MUST contain `daysFromCreatedToCompleted: 7`

#### Scenario: An aggregation MAY target a materialised calculation field
- GIVEN the materialised calculation `daysFromCreatedToCompleted` from the previous scenario
- AND an aggregation `avgDaysToClose: { metric: "avg", field: "daysFromCreatedToCompleted", filter: { taskStatus: "completed" } }` (declared via `aggregations-annotation`)
- WHEN a client GETs the aggregation
- THEN the backend MUST compute `AVG(daysFromCreatedToCompleted)` over completed items
- AND return `{ name: "avgDaysToClose", value: <decimal> }`

#### Scenario: Cycle detection rejects a schema with circular calculations
- GIVEN a schema declaring `a: { expression: "b + 1" }` and `b: { expression: "a + 1" }`
- WHEN the schema is saved
- THEN validation MUST reject with HTTP 422 and `{ code: "calculation-cycle", path: ["a", "b", "a"] }`

#### Scenario: Idempotent recomputation skips when inputs haven't changed
- GIVEN a materialised calculation `fullName: { expression: "concat(firstName, ' ', lastName)" }`
- AND an object update that changes only the `email` field (firstName + lastName unchanged)
- WHEN the on-save listener runs
- THEN the listener MUST detect that no input field of `fullName` changed
- AND skip recomputation (the persisted value remains identical)

### Requirement: The calculation expression DSL MUST cover the v1 vocabulary
The v1 evaluator MUST support: bare property references; arithmetic `+ - * / %`; comparison `eq ne lt lte gt gte`; logical `&& || !`; string `concat`; conditional `if(cond, then, else)`; date `now()`, `diffDays(later, earlier)`, `formatDate(d, fmt)`. Functions outside this list MUST cause a schema-save error `{ code: "calculation-unknown-function", function: "<name>" }`. The evaluator MUST be pure — no I/O, no DB access, no HTTP.

#### Scenario: Unknown function in expression is rejected at save
- GIVEN a calculation `expression: "callExternalApi(url)"`
- WHEN the schema is saved
- THEN validation MUST reject with `{ code: "calculation-unknown-function", function: "callExternalApi" }`

#### Scenario: Conditional expression evaluates correctly
- GIVEN calculation `isOverdue: { expression: "if(lt(dueDate, now()) && ne(taskStatus, 'completed'), true, false)" }`
- AND an action item with `dueDate: 2026-04-01, taskStatus: "open"` (today is 2026-04-29)
- WHEN the calculation is evaluated
- THEN the result MUST be `true`

### Requirement: Migration helpers MUST be provided
Two `occ` commands MUST exist:

- `openregister:validate-calculations <register> <schema>` — re-runs every calculation against existing objects; reports drift (stored value differs from recomputed value).
- `openregister:rematerialise-calculations <register> <schema>` — bulk reprocesses every object's materialised calculations (used after adding a new calculation to a schema with existing data).

#### Scenario: Validate command reports drift
- GIVEN a materialised calculation has been added but existing objects pre-date its definition
- WHEN `occ openregister:validate-calculations decidesk action-item` runs
- THEN the command MUST report each object whose stored value of the calculation differs from a fresh recomputation
- AND exit non-zero if any drift is found

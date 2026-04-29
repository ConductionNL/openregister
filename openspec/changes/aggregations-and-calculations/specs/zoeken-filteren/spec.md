---
status: draft
---
# Aggregations and Calculations (delta — zoeken-filteren)

## Purpose
Extend the implemented `zoeken-filteren` spec with two declarative annotations — `x-openregister-aggregations` for count/sum/avg/min/max/count_distinct queries, and `x-openregister-calculations` for computed/derived fields — both reusing the existing filter compiler, RBAC scope, cache layer, and backend dispatch.

## ADDED Requirements

### Requirement: Schemas MAY declare aggregations via `x-openregister-aggregations`
A schema MAY include a top-level `x-openregister-aggregations` block: a map of aggregation name → spec. Each spec declares `metric` (count/count_distinct/sum/avg/min/max), optional `field`, optional `filter` using the same operator vocabulary as `findObjects`, and optional `groupBy` (plain field or `{field, bucket: day|week|month|year, limit?, order?}`). Schema-save validation MUST verify every field reference and reject malformed annotations with HTTP 422.

#### Scenario: Aggregation against an enum-filtered count
- GIVEN a schema `action-item` with property `taskStatus` of type string and enum `["open", "completed"]`
- AND an aggregation `totalOpen: { metric: "count", filter: { taskStatus: { $ne: "completed" } } }`
- WHEN the schema is saved
- THEN validation MUST accept it
- WHEN a client GETs `/api/objects/aggregations/totalOpen?register=decidesk&schema=action-item`
- THEN the backend MUST translate the filter through the existing filter compiler
- AND issue `SELECT COUNT(*) FROM <dynamic-table> WHERE <compiled-filter>`
- AND return `{ name: "totalOpen", value: <integer> }`

#### Scenario: Aggregation grouped by a string field returns groups
- GIVEN an aggregation `openByAssignee: { metric: "count", filter: {taskStatus: {$ne: "completed"}}, groupBy: "assignee" }`
- WHEN a client GETs the aggregation endpoint
- THEN the backend MUST issue `SELECT assignee, COUNT(*) ... GROUP BY assignee`
- AND return `{ name: "openByAssignee", groups: [{ key: "alice", value: 5 }, { key: "bob", value: 3 }] }`

#### Scenario: Time-bucketed aggregation buckets by month
- GIVEN an aggregation `completionByMonth: { metric: "count", filter: {taskStatus: "completed"}, groupBy: { field: "completedAt", bucket: "month", limit: 12, order: "desc" } }`
- WHEN a client GETs the aggregation endpoint
- THEN the backend MUST truncate `completedAt` to month boundaries (using `date_trunc('month', completedAt)` in Postgres, `date_histogram` in ES)
- AND return up to 12 most-recent buckets keyed by `YYYY-MM`
- AND results MUST be sorted descending

#### Scenario: Aggregation with placeholders resolves at request time
- GIVEN an aggregation `myOverdue: { metric: "count", filter: { assignee: "$currentUser", dueDate: { $lt: "$now" }, taskStatus: { $ne: "completed" } } }`
- WHEN user `alice` GETs the aggregation endpoint
- THEN `$currentUser` MUST resolve to `"alice"` and `$now` to the current ISO timestamp
- AND the result MUST count only items assigned to alice that are past their due date and not completed

### Requirement: Aggregations MUST honor the existing RBAC scope
The filter compiler's RBAC mechanism (already used by `findObjects`) MUST be applied to aggregation queries. A user querying `count` MUST only see objects they can read. Aggregations MUST NOT leak counts of objects a user cannot individually read.

#### Scenario: A non-admin's count is bounded by their RBAC scope
- GIVEN 100 action items in the system, of which `alice` can read 7 (per object ACLs)
- WHEN alice GETs `count` of all action items
- THEN the response MUST be `7`, not `100`
- AND no exception or 403 is returned (the filter just narrows)

### Requirement: Aggregations MUST be cacheable with invalidation on writes
The implementation MUST cache aggregation results keyed by `(register, schema, name, resolved-placeholders-hash, rbac-scope-hash)` with default TTL 60 seconds. The cache MUST invalidate when any `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` / `ObjectTransitionedEvent` fires for the affected `(register, schema)`.

#### Scenario: Cache hit returns within 5 ms
- GIVEN a cached aggregation result for `totalOpen`
- WHEN the same user GETs the same aggregation again within 60 seconds
- THEN the response MUST be served from cache
- AND the cache hit MUST be tagged in response headers (`X-OR-Cache: hit`)

#### Scenario: Cache invalidates on write
- GIVEN a cached `totalOpen` value of `7`
- WHEN a new action item is created (firing `ObjectCreatedEvent` for `(decidesk, action-item)`)
- THEN the cache entry for `totalOpen` (and every other aggregation on `action-item`) MUST be evicted
- AND the next read MUST recompute against the database

### Requirement: The system MUST expose an inline aggregation mode on the existing search endpoint
The existing `searchObjectsPaginated` endpoint MUST accept a `_aggregate=name1,name2,...` query param. When present, the response body MUST include both the paginated `results` and an `aggregations` object mapping each requested name to its value (or groups), saving a round-trip when a UI needs the list AND the totals.

#### Scenario: Inline aggregations alongside results
- GIVEN a client GETs `/api/objects?register=decidesk&schema=action-item&taskStatus=open&_aggregate=totalOpen,totalOverdue&_limit=20`
- WHEN the response is rendered
- THEN it MUST contain `results: [...]` (the paginated list, max 20)
- AND `aggregations: { totalOpen: { value: 42 }, totalOverdue: { value: 3 } }`

### Requirement: Schemas MAY declare calculations via `x-openregister-calculations`
A schema MAY include a top-level `x-openregister-calculations` block: a map of calculation name → spec. Each spec declares `type` (string/integer/boolean/number/array), `expression` (a v1 DSL string), optional `materialise: bool` (default false = virtual; true = persisted at save), and optional `computeOn: ["save", "transition:<name>"]` (when materialised, controls when to recompute). Schema-save validation MUST verify every property reference, every function in the v1 vocabulary, no cyclic dependencies between calculations.

#### Scenario: A virtual calculation renders at response time
- GIVEN schema `meeting` with calculation `displayTitle: { type: "string", expression: "concat(title, ' (', formatDate(scheduledDate, 'yyyy-MM-dd'), ')')" }` and `materialise: false`
- AND a meeting `m1` with `title: "Vergadering"` and `scheduledDate: "2026-05-04T10:00:00Z"`
- WHEN a client GETs the meeting with `_include=calculations`
- THEN the response body MUST include `displayTitle: "Vergadering (2026-05-04)"`
- AND the value MUST NOT be persisted in storage

#### Scenario: A materialised calculation is persisted at save
- GIVEN schema `action-item` with calculation `daysFromCreatedToCompleted: { type: "integer", expression: "diffDays(completedAt, createdAt)", materialise: true }`
- AND an existing action item with `createdAt: 2026-04-01`, no `completedAt`, no calculation value yet
- WHEN the item is updated to `completedAt: 2026-04-08, taskStatus: "completed"`
- THEN before the persistence step, the on-save listener MUST run the evaluator
- AND patch the field `daysFromCreatedToCompleted = 7` into the object payload
- AND the persisted object MUST contain `daysFromCreatedToCompleted: 7`

#### Scenario: An aggregation MAY target a materialised calculation field
- GIVEN the materialised calculation `daysFromCreatedToCompleted` from the previous scenario
- AND an aggregation `avgDaysToClose: { metric: "avg", field: "daysFromCreatedToCompleted", filter: { taskStatus: "completed" } }`
- WHEN a client GETs the aggregation
- THEN the backend MUST compute `AVG(daysFromCreatedToCompleted)` over completed items
- AND return `{ name: "avgDaysToClose", value: <decimal> }`

#### Scenario: Cycle detection rejects a schema with circular calculations
- GIVEN a schema declaring `a: { expression: "b + 1" }` and `b: { expression: "a + 1" }`
- WHEN the schema is saved
- THEN validation MUST reject with HTTP 422 and `{ code: "calculation-cycle", path: ["a", "b", "a"] }`

#### Scenario: Idempotent recomputation skips when inputs haven't changed
- GIVEN a materialised calculation `fullName: { expression: "concat(firstName, ' ', lastName)" }`
- AND an object update that changes only the `email` field (leaves firstName + lastName unchanged)
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

---
status: draft
---
# Aggregations Annotation (delta — zoeken-filteren)

## Purpose
Extend the implemented `zoeken-filteren` spec with a declarative `x-openregister-aggregations` schema annotation: declared count/sum/avg/min/max/count_distinct queries with optional groupBy + time-bucket, exposed via `GET /api/objects/aggregations/{name}` and inline `_aggregate=` on the existing search endpoint. Reuses the existing filter compiler, RBAC scope, cache layer, and three-backend dispatch.

## ADDED Requirements

### Requirement: Schemas MAY declare aggregations via `x-openregister-aggregations`
A schema MAY include a top-level `x-openregister-aggregations` block — a map of aggregation name → spec. Each spec declares `metric` (count / count_distinct / sum / avg / min / max), optional `field`, optional `filter` using the same operator vocabulary as `findObjects`, and optional `groupBy` (plain field or `{field, bucket: day|week|month|year, limit?, order?}`). Schema-save validation MUST verify every reference and reject malformed annotations with HTTP 422.

#### Scenario: Aggregation with enum-filtered count is accepted
- GIVEN a schema `action-item` with property `taskStatus` of type string and enum `["open", "completed"]`
- AND an aggregation `totalOpen: { metric: "count", filter: { taskStatus: { $ne: "completed" } } }`
- WHEN the schema is saved
- THEN `SchemaService::saveSchema()` MUST accept it
- WHEN a client GETs `/api/objects/aggregations/totalOpen?register=decidesk&schema=action-item`
- THEN the backend MUST issue `SELECT COUNT(*) FROM <dynamic-table> WHERE <compiled-filter>` (Postgres) and return `{ name: "totalOpen", value: <integer> }`

#### Scenario: A `to`-shaped reference outside the enum is rejected
- GIVEN a schema with `taskStatus` enum `["open", "completed"]`
- AND a filter referencing `taskStatus: "in-progress"` (not in enum)
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422 and `{ code: "aggregation-filter-value-not-in-enum", field: "taskStatus", value: "in-progress" }`

#### Scenario: Group-by on a string field returns groups
- GIVEN an aggregation `openByAssignee: { metric: "count", filter: {taskStatus: {$ne: "completed"}}, groupBy: "assignee" }`
- WHEN a client GETs the endpoint
- THEN the backend MUST issue a grouped query and return `{ name: "openByAssignee", groups: [{ key: "alice", value: 5 }, { key: "bob", value: 3 }] }`

#### Scenario: Time-bucketed group-by buckets by month
- GIVEN an aggregation `completionByMonth: { metric: "count", filter: {taskStatus: "completed"}, groupBy: { field: "completedAt", bucket: "month", limit: 12, order: "desc" } }`
- WHEN a client GETs the endpoint
- THEN the backend MUST truncate `completedAt` to month boundaries (`date_trunc('month', completedAt)` in Postgres, `date_histogram` in ES)
- AND return up to 12 most-recent buckets keyed by `YYYY-MM`

#### Scenario: Placeholders resolve at request time
- GIVEN aggregation `myOverdue: { metric: "count", filter: { assignee: "$currentUser", dueDate: { $lt: "$now" }, taskStatus: { $ne: "completed" } } }`
- WHEN user `alice` GETs the endpoint
- THEN `$currentUser` MUST resolve to `"alice"` and `$now` to the current ISO timestamp
- AND the result counts only items assigned to alice that are past their due date and not completed

### Requirement: Aggregations MUST honour the existing RBAC scope
The filter compiler's RBAC mechanism (already used by `findObjects`) MUST be applied to aggregation queries. A user querying `count` MUST only see objects they can read individually; aggregations MUST NOT leak counts of objects a user cannot individually read.

#### Scenario: A non-admin's count is bounded by their RBAC scope
- GIVEN 100 action items in the system, of which `alice` can read 7 (per object ACLs)
- WHEN alice GETs `count` of all action items
- THEN the response MUST be `7`, not `100`
- AND no exception or 403 is returned (the filter just narrows)

### Requirement: Aggregations MUST be cacheable with invalidation on writes
The implementation MUST cache aggregation results keyed by `(register, schema, name, resolved-placeholders-hash, rbac-scope-hash)` with default TTL 60 seconds. Cache MUST invalidate on `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` / `ObjectTransitionedEvent` for the affected `(register, schema)`.

#### Scenario: Cache hit returns within 5 ms with header
- GIVEN a cached aggregation result for `totalOpen`
- WHEN the same user GETs the same aggregation again within 60 seconds
- THEN the response MUST be served from cache with `X-OR-Cache: hit`

#### Scenario: Cache invalidates on write
- GIVEN a cached `totalOpen` value of `7`
- WHEN a new action item is created (firing `ObjectCreatedEvent` for `(decidesk, action-item)`)
- THEN every cache entry for `(decidesk, action-item)` aggregations MUST be evicted
- AND the next read MUST recompute against the database

### Requirement: The system MUST expose an inline aggregation mode on the existing search endpoint
The existing `searchObjectsPaginated` endpoint MUST accept a `_aggregate=name1,name2,...` query param. When present, the response body MUST include both the paginated `results` and an `aggregations` object mapping each requested name to its value (or groups), saving a round-trip when a UI needs the list AND the totals.

#### Scenario: Inline aggregations alongside results
- WHEN a client GETs `/api/objects?register=decidesk&schema=action-item&taskStatus=open&_aggregate=totalOpen,totalOverdue&_limit=20`
- THEN the response MUST contain `results: [...]` (paginated, max 20)
- AND `aggregations: { totalOpen: { value: 42 }, totalOverdue: { value: 3 } }`

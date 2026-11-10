# aggregation-api

## ADDED Requirements

### Requirement: The ad-hoc aggregation primitive SHALL be reachable in-process by a consuming PHP service

A consuming app running inside the same Nextcloud process SHALL be able to
obtain a server-side aggregation (count / sum / avg / min / max, optionally
grouped) over a register-schema collection WITHOUT looping back over HTTP and
WITHOUT hydrating all rows and aggregating in PHP. The entry point SHALL be
`AggregationRunner::runAdhocByRef(string $registerRef, string $schemaRef,
AggregationQuery $query)`.

`AggregationQuery::create(string $metric, ?string $field, array $filter, ?array
$groupBy, ?array $dateBucket)` SHALL build the request: `metric` one of
`count|sum|avg|min|max`; `field` required for non-`count` metrics; `filter` a
per-field map of scalar-equality or operator sub-maps; `groupBy` an optional
`{field: <name>}`.

`runAdhocByRef()` SHALL apply the SAME authorization and tenancy gate as
`findAll()`: the schema's `list` RBAC verdict for the active user and the
active-organisation `_organisation` predicate, BOTH evaluated before any SQL
executes. It SHALL throw `NotAuthorizedException` when the caller lacks `list`
permission and `RuntimeException` when the register/schema ref cannot be
resolved.

The return SHALL be an array. Ungrouped: `{ value, backend, cached }` where
`value` is an `int` for `count`, a `float` for `sum`/`avg`/`min`/`max`, and
`null` for an empty matching set. Grouped: `{ groups: [{ key, value }], backend,
cached }` with one bucket per distinct group value. `backend` SHALL report the
engine that served the request (`postgres` / `mysql` / `sqlite` / `php-fallback`).

#### Scenario: Consumer obtains a grouped SUM via runAdhocByRef

- **GIVEN** a register+schema with objects carrying a numeric `priority` and a
  categorical `taskStatus`, and an authenticated caller with `list` permission
- **WHEN** the consumer builds `AggregationQuery::create(metric: 'sum', field:
  'priority', groupBy: ['field' => 'taskStatus'])` and calls
  `runAdhocByRef(registerRef, schemaRef, query)`
- **THEN** the result SHALL contain `groups`, one bucket per distinct
  `taskStatus`, each `value` equal to the SUM of `priority` over the rows in that
  bucket that the caller may read
- @e2e exclude In-process PHP-service aggregation contract; verified by PHPUnit integration test + live container verification, no browser flow.

#### Scenario: Consumer obtains an ungrouped AVG / MIN / MAX via runAdhocByRef

- **GIVEN** the same register+schema and caller
- **WHEN** the consumer calls `runAdhocByRef` with `metric: 'avg'` / `'min'` /
  `'max'` and `field: 'priority'`
- **THEN** the result SHALL contain a scalar `value` equal to the arithmetic
  mean / minimum / maximum of `priority` over the RBAC-filtered rows, or `null`
  when no rows match
- @e2e exclude In-process PHP-service aggregation contract; verified by PHPUnit + live container verification, no browser flow.

#### Scenario: runAdhocByRef enforces the list-permission gate

- **GIVEN** a caller who lacks `list` permission on the schema
- **WHEN** the consumer calls `runAdhocByRef` for any metric on that schema
- **THEN** the call SHALL throw `NotAuthorizedException` and no aggregation SHALL
  be computed
- @e2e exclude Backend authorization gate; verified by the existing AggregationRunner RBAC unit coverage, no browser flow.

### Requirement: The aggregation and findAll filter vocabularies SHALL include a notIn exclusion operator

The system SHALL support a `notIn` operator in both the aggregation ad-hoc
filter map and the `ObjectService::findAll(array $config)` / `count(array
$config)` config-filter map. `{field: {notIn: [a, b, …]}}` SHALL match rows
whose `field` is NOT one of the listed values, translating to a `NOT IN (...)`
SQL predicate.
An empty `notIn` list SHALL exclude nothing (retain all rows) and SHALL NOT emit
a malformed `NOT IN ()` clause. The findAll config-filter map SHALL likewise
support `ne` (`{field: {ne: x}}` → `field <> x`).

The aggregation native-SQL path SHALL bind one parameter per `notIn` operand
and SHALL fall back to the PHP path only on query shapes it cannot translate;
the PHP-fallback path SHALL apply the same `notIn` semantics so both paths agree.

#### Scenario: notIn excludes the listed values in an aggregation

- **GIVEN** a register+schema with objects whose `taskStatus` ranges over
  `open` / `in-progress` / `completed`
- **WHEN** an aggregation runs with `filter: {taskStatus: {notIn: ['completed',
  'open']}}` and `metric: 'count'`
- **THEN** the result SHALL count only the rows whose `taskStatus` is neither
  `completed` nor `open`
- @e2e exclude Backend filter-operator translation; verified by PHPUnit integration + native-SQL-emission unit + live verification, no browser flow.

#### Scenario: Empty notIn list retains all rows

- **GIVEN** the same register+schema
- **WHEN** an aggregation runs with `filter: {taskStatus: {notIn: []}}` and
  `metric: 'count'`
- **THEN** the result SHALL count ALL readable rows (the empty exclusion list
  removes nothing) and the SQL SHALL NOT contain a `NOT IN ()` clause
- @e2e exclude Backend filter-operator edge case; verified by PHPUnit integration + native-SQL-emission unit, no browser flow.

#### Scenario: notIn and ne are reachable on an ordinary findAll query

- **GIVEN** a schema collection queried via `ObjectService::findAll(['filters' =>
  ['status' => ['notIn' => ['archived', 'deleted']]]])`
- **WHEN** the query executes
- **THEN** the emitted SQL SHALL contain a `NOT IN (...)` predicate on the
  `status` column, and a sibling `{status: {ne: 'completed'}}` filter SHALL emit
  a `<> 'completed'` inequality
- @e2e exclude Backend filter-operator translation in the magic-table search handler; verified by PHPUnit unit test, no browser flow.

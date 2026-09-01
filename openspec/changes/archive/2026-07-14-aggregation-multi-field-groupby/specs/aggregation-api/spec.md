## ADDED Requirements

### Requirement: The system SHALL honour a multi-field (cross-tab) groupBy

The aggregation engine (`AggregationQuery` + `AggregationRunner`, native-SQL and PHP-fallback paths) SHALL accept a `groupBy` expressed as an ordered list of two or more scalar fields and SHALL produce one grouped row per distinct field **tuple** (a cross-tab), applying the same metric (count/sum/avg/min/max), filters, RBAC gate, and multi-tenant predicate as a single-field groupBy.

A multi-field `groupBy` MAY be supplied in either the explicit shape `{ fields: ["a", "b"] }` or the plain ordered-list shape `["a", "b"]`. The single-field shape `{ field: "a" }` SHALL remain accepted and behaviourally unchanged.

The engine SHALL NOT silently ignore extra group fields. A malformed `groupBy` — an empty list, an empty-string member, a non-string member, or duplicate fields — SHALL be rejected with an `InvalidArgumentException` (HTTP 400 at the controller boundary), never partially honoured.

Result shape:
- A **single-field** group row SHALL keep the backward-compatible shape `{ key: <fieldValue>, value: <metric> }`.
- A **multi-field** group row SHALL expose a composite key map `{ keys: { "a": <valueA>, "b": <valueB> }, value: <metric> }`, with every declared group field present, so a consumer can pivot the result into a cross-tab.

On PostgreSQL, MySQL and SQLite the categorical groupBy SHALL execute natively as `GROUP BY <col_a>, <col_b>, ...` over the sanitised magic-table columns; the PHP fallback SHALL bucket on the same field tuple and SHALL produce grouped rows that agree with the native path.

#### Scenario: Two-field native groupBy returns one row per distinct tuple
- **GIVEN** a register/schema magic table with rows carrying `vendorId` ∈ {V1, V2, V3} and `dueDateBucket` ∈ {current, 30days}
- **AND** a filter `state IN [issued, partially-paid, overdue, disputed]` that excludes the `paid` rows
- **WHEN** an aggregation runs with metric `sum(amount)` and `groupBy: { fields: ["vendorId", "dueDateBucket"] }`
- **THEN** the native path SHALL emit SQL containing `GROUP BY "vendor_id", "due_date_bucket"`
- **AND** the result SHALL contain exactly the tuples `(V1,current)=150`, `(V1,30days)=200`, `(V2,current)=300`, `(V2,30days)=100`
- **AND** each group row SHALL carry `keys: { vendorId: ..., dueDateBucket: ... }` and a numeric `value`

#### Scenario: Single-field groupBy stays backward compatible
- **GIVEN** the same dataset
- **WHEN** an aggregation runs with `groupBy: { field: "vendorId" }`
- **THEN** each group row SHALL carry a scalar `key` and a `value`
- **AND** no group row SHALL carry a composite `keys` map

#### Scenario: Native and PHP-fallback paths agree
- **GIVEN** the same dataset and `groupBy: { fields: ["vendorId", "dueDateBucket"] }` with metric `sum(amount)`
- **WHEN** the aggregation is computed once via the native-SQL path (`backend: "sqlite"`) and once via the PHP fallback (`backend: "php-fallback"`)
- **THEN** the two grouped result sets SHALL contain the same tuples with the same values

#### Scenario: Malformed multi-field groupBy is rejected
- **WHEN** an `AggregationQuery` is built with `groupBy: ["vendorId", ""]` or `groupBy: ["vendorId", "vendorId"]`
- **THEN** construction SHALL throw `InvalidArgumentException`
- **AND** no partial (single-field or ungrouped) aggregation SHALL be produced

@e2e exclude Data-layer aggregation primitive with no OpenRegister UI surface — the multi-field groupBy is exercised against a real in-memory SQLite database via PHPUnit (`AggregationRunnerMultiFieldGroupByTest`) proving the native `GROUP BY a, b` output and native ⇄ PHP-fallback agreement, plus value-object shape/validation units (`AggregationQueryTest`). Covered by PHPUnit.

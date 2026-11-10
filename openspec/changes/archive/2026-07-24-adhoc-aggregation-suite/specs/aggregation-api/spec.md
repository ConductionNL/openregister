# aggregation-api Specification (delta)

---
status: proposed
---

## Purpose delta

The ad-hoc aggregation API gains the primitives dashboards require —
multi-field grouping, multi-metric responses, cumulative time-bucket totals,
cross-dialect native time-bucket execution, and timeseries caching — and the
value/count path is corrected to honour multi-value and `in`-operator filters.

## ADDED Requirements

### Requirement: Multi-field group-by (REQ-AGG-101)

The grouped aggregation endpoint MUST accept `groupBy` as either a single field
(unchanged behaviour) or an ordered list of fields, emitting a composite
`GROUP BY` and returning each group keyed by an ordered tuple. A single-field
request MUST return the same response shape as before this change (scalar
`key`); a multi-field request MUST return `key` as an ordered object of
field→value. Field names MUST be validated against the schema and MUST NOT be
interpolated raw into SQL.

#### Scenario: Group by two fields

- **GIVEN** objects with `status` and `type` properties
- **WHEN** a client requests `groupBy=status,type` with `metric=count`
- **THEN** each returned group is keyed by both `status` and `type` and carries
  the count of objects in that combination.

#### Scenario: Single-field shape is unchanged

- **WHEN** a client requests `groupBy=status`
- **THEN** the response shape is identical to the pre-change single-field shape.

### Requirement: Multi-metric responses (REQ-AGG-102)

The aggregation endpoints MUST accept a list of metrics
(`count`, `sum`, `avg`, `min`, `max`) in one request and return every requested
metric per group under a `values` object. Non-`count` metrics MUST require a
numeric field, validated before execution. The legacy single `metric`/`field`
params MUST remain valid and map to a one-element metrics list.

#### Scenario: Count and sum in one call

- **WHEN** a client requests `metrics=[{count},{sum,price}]` grouped by `status`
- **THEN** each group carries both the count and the summed `price`.

### Requirement: Cumulative time-bucket aggregates (REQ-AGG-103)

The time-bucket primitive MUST accept a `cumulative: true` flag that orders
buckets ascending by bucket start and returns a running total of the selected
metric alongside each per-bucket value. The cumulative result MUST be identical
whether computed by a SQL window function or a PHP post-pass.

#### Scenario: Running total over months

- **GIVEN** a monthly time-bucket over `createdAt`
- **WHEN** a client requests it with `cumulative=true`
- **THEN** each bucket carries its own value and the running total up to and
  including that bucket.

### Requirement: Cross-dialect native time-bucket (REQ-AGG-104)

Time-bucket grouping MUST execute natively on PostgreSQL, MySQL/MariaDB and
SQLite for the intervals `hour`, `day`, `week`, `month`, `quarter`, `year`, with
bucket boundaries that agree across dialects. A dialect/interval combination
with no native expression MUST fall back to a documented PHP-side bucketer
rather than emit incorrect SQL.

#### Scenario: Same boundaries on every dialect

- **GIVEN** the same objects loaded on Postgres, MySQL and SQLite
- **WHEN** a monthly time-bucket aggregation runs on each
- **THEN** the bucket boundaries and per-bucket counts are identical.

### Requirement: Timeseries result caching (REQ-AGG-105)

Timeseries/time-bucket results MUST be cacheable in `AggregationCache`, keyed on
register, schema and a normalized query hash (field, interval, metrics, filters,
cumulative). The timeseries endpoint MUST report `X-OR-Cache: hit` or
`X-OR-Cache: miss`, and cached entries MUST be invalidated when an object in the
register+schema is written.

#### Scenario: Second identical request is a cache hit

- **WHEN** the same timeseries request is issued twice with no intervening write
- **THEN** the first responds `X-OR-Cache: miss` and the second
  `X-OR-Cache: hit` with an identical body.

### Requirement: Multi-value and in-operator filters on the value path (REQ-AGG-106)

On the value/count and grouped paths, a bare array filter value MUST be treated
as an implicit `in` (any-of) match, and the `in` operator MUST generate an
`IN (…)` predicate — including an any-overlap test for multi-value object
properties stored as JSON arrays. Scalar filter values MUST be unchanged. This
aligns aggregation filter semantics with the main object-search filter
semantics.

#### Scenario: Count with a multi-value filter

- **GIVEN** objects whose `tags` property holds arrays
- **WHEN** a client counts with a filter `tags=[a,b]`
- **THEN** objects whose tags include `a` or `b` are counted (not a raw
  array-equality that matches none), and the `in` operator over a scalar field
  returns the any-of count rather than `0`.

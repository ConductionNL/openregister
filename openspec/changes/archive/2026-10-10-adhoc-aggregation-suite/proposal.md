# adhoc-aggregation-suite

## Why

OpenRegister's ad-hoc aggregation API (`AggregationController` →
`value` / `grouped` / `timeseries`, backed by `AggregationQuery`,
`AggregationRunner`-equivalent execution and `AggregationCache`) covers the
single-metric, single-dimension cases but stops short of the shapes real
dashboards need. Five open issues plus one correctness bug describe the gap:

- **#1606** multi-field `groupBy` (group by more than one property in one call)
- **#1607** running / cumulative aggregates over the time-bucket primitive
- **#1608** multi-metric requests (e.g. `count` + `sum` in a single response)
- **#1609** native time-bucket on MySQL / SQLite (not just PostgreSQL
  `DATE_TRUNC`)
- **#1610** cache ad-hoc time-bucket aggregations in `AggregationCache`
- **#2027** (bug) the stat-widget count endpoint ignores multi-value filters —
  a bare array value is counted raw and the `in` operator returns `0` instead
  of matching any-of

Demand evidence: aggregations/BI/dashboards is a recurring roadmap theme
(#1675 BI export, #1729 built-in dashboards, #1662 role-scoped aggregations),
and these six are the concrete, ready-to-build primitives beneath it. #2027 is a
live correctness defect that silently under- or over-counts dashboard widgets.

## What Changes

- **Multi-field groupBy (#1606):** `grouped` accepts a `groupBy` list (multiple
  fields), returning nested or composite-key groups. Single-field requests keep
  their current shape (backward compatible).
- **Multi-metric (#1608):** requests may specify several metrics
  (`count`, `sum`, `avg`, `min`, `max`) in one call; each group carries all
  requested metric values.
- **Cumulative/running aggregates (#1607):** the time-bucket primitive accepts a
  `cumulative: true` flag producing a running total across ordered buckets.
- **Cross-dialect time-bucket (#1609):** time-bucket grouping executes natively
  on MySQL/MariaDB and SQLite (not only PostgreSQL), with identical bucket
  boundaries and an explicit fallback path when a dialect lacks a native
  function.
- **Time-bucket cache (#1610):** time-bucket/timeseries results are cached in
  `AggregationCache` keyed on register+schema+query, with the existing
  `X-OR-Cache: hit|miss` header semantics extended to the timeseries endpoint.
- **Multi-value filter fix (#2027):** the value/count path treats a bare array
  filter value as an implicit `in` (any-of) match, and the `in` operator
  matches when the object's value is contained in the list — for both scalar and
  multi-value object properties.

**BREAKING:** none intended. Multi-field/multi-metric are additive request
shapes; the #2027 fix corrects counts that were already wrong. A regression test
pins that single-field/single-metric responses are byte-shape identical.

## Capabilities

### Modified Capabilities

- `aggregation-api`: gains requirements for multi-field groupBy, multi-metric
  responses, cumulative time-bucket aggregates, cross-dialect native
  time-bucket execution, timeseries result caching, and correct multi-value /
  `in`-operator filter handling on the value/count path.

## Impact

**Affected code:** `lib/Controller/AggregationController.php`
(`value`/`grouped`/`timeseries` param parsing + response assembly),
`lib/Service/AggregationQuery.php` (SQL generation: composite group keys,
multiple metric columns, dialect-specific time-bucket expressions, cumulative
window), `lib/Service/AggregationCache.php` (timeseries keys), the filter
translation used by the value path (multi-value / `in`).

**Tests:** unit tests per primitive + a cross-dialect matrix (Postgres/MySQL/
SQLite) for time-bucket boundaries; a regression test pinning the unchanged
single-field/single-metric response shape; a #2027 test proving a multi-value
filter and an `in` operator both count correctly. Runnable in the `nextcloud:34`
container.

**Dependencies:** no new packages; no schema/migration change (cache table
already exists). Frontend stat-widgets consume the corrected counts with no API
break.

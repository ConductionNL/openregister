# Design: adhoc-aggregation-suite

## Context

The ad-hoc aggregation stack today: `AggregationController` exposes
`value(register, schema)` (single metric), `grouped(register, schema)` (single
`groupBy` field → `{ groups, backend, cached }`), and `timeseries(...)` (a
time-bucket primitive). SQL is built by `AggregationQuery`; results can be
served from `AggregationCache` (the `aggregate()` route already sets
`X-OR-Cache`). Validators exist for annotations, timeseries requests and
widgets. Execution runs natively on the object's magic table.

This change extends the three endpoints with the primitives dashboards need,
and fixes the multi-value filter defect on the value/count path.

## Decisions

### D1 — Multi-field groupBy (#1606) is a list, single stays scalar

`grouped` accepts `groupBy` as either a single field (current behaviour) or a
comma-list / repeated param → an ordered list of fields. `AggregationQuery`
emits a composite `GROUP BY f1, f2, …` and returns groups keyed by an ordered
tuple (`key` becomes an object `{f1: …, f2: …}` when >1 field; stays a scalar
`key` for one field, preserving today's shape). Field names are validated
against the schema before interpolation (no raw field into SQL).

### D2 — Multi-metric (#1608) via a metrics list

A `metrics` param (list of `{metric, field?}`) produces one column per metric;
each group row carries `values: {count: …, sum_price: …}`. The legacy single
`metric`/`field` params remain and map to a one-element list. `count` needs no
field; `sum`/`avg`/`min`/`max` require a numeric field (validated).

### D3 — Cumulative (#1607) is a post-pass over ordered buckets

`cumulative: true` on the timeseries/time-bucket primitive orders buckets
ascending by bucket start and emits a running total of the chosen metric
alongside the per-bucket value (`value` + `cumulative`). Implemented as a SQL
window (`SUM(...) OVER (ORDER BY bucket)`) where the dialect supports it, else a
PHP post-pass over the ordered result — identical output either way (pinned by
test).

### D4 — Cross-dialect time-bucket (#1609)

Bucket expression is dialect-selected: PostgreSQL `DATE_TRUNC`, MySQL/MariaDB
`DATE_FORMAT` truncation (or `DATE_TRUNC` on MariaDB ≥ the supporting version),
SQLite `strftime`. A single `bucketExpression(dialect, field, interval)` helper
centralises this so boundaries agree across dialects. Supported intervals:
`hour|day|week|month|quarter|year`. A dialect/interval combination with no
native expression falls back to a documented PHP-side bucketer rather than
emitting wrong SQL.

### D5 — Timeseries cache (#1610)

`AggregationCache` gains timeseries entries keyed on
`register + schema + normalized-query-hash` (query includes field, interval,
metrics, filters, cumulative). `timeseries()` sets `X-OR-Cache: hit|miss` like
`aggregate()` already does. Cache invalidation rides the existing object-write
invalidation for the register+schema.

### D6 — #2027 multi-value filter / `in` fix on the value path

The value/count path currently passes a filter value straight through: a bare
array is compared as a scalar (raw count), and the `in` operator yields `0`.
Fix: in the filter translation used by `value()`/`grouped()`, a bare array
value is treated as an implicit `in` (any-of); the `in` operator generates an
`IN (…)` predicate (or, for multi-value object properties stored as JSON
arrays, an any-overlap test). Scalars are unchanged. This aligns the aggregation
filter semantics with the main object-search filter semantics.

## Risks / Trade-offs

- **Dialect drift** — the cross-dialect matrix test is the guard; if a dialect
  can't match Postgres boundaries natively, D4's PHP fallback keeps output
  correct at some performance cost (logged, not silent).
- **Cache staleness** — bounded by riding existing write-invalidation; a
  timeseries over a hot schema may serve a slightly stale bucket, acceptable for
  dashboards and explicitly bypassable with a no-cache param.
- **Response-shape compatibility** — the single-field/single-metric shape is
  frozen by a regression test so existing widgets don't break.

## Migration / Rollout

No migration. Additive request params; corrected counts. Frontend adopts
multi-field/multi-metric opportunistically.

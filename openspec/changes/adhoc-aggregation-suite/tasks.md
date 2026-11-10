# Tasks: adhoc-aggregation-suite

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Query engine

- [ ] 1.1 Multi-field groupBy in `AggregationQuery` — composite `GROUP BY`, ordered tuple key, schema-validated field names (REQ-AGG-101)
  - Single-field requests keep a scalar `key`; >1 field returns `key` as an ordered object. No raw field interpolated into SQL.
- [ ] 1.2 Multi-metric columns — `metrics` list → one column per metric, each group row carries `values: {…}`; legacy `metric`/`field` map to a one-element list (REQ-AGG-102)
- [ ] 1.3 Cross-dialect `bucketExpression(dialect, field, interval)` helper for hour/day/week/month/quarter/year on Postgres/MySQL/MariaDB/SQLite, with a documented PHP fallback (REQ-AGG-104)
- [ ] 1.4 Cumulative running total on the time-bucket primitive (`cumulative: true`) via SQL window or PHP post-pass — identical output (REQ-AGG-103)

## 2. Controller + cache

- [ ] 2.1 Wire the new params through `AggregationController::grouped`/`timeseries`; validate metric fields; preserve legacy response shapes (REQ-AGG-101, REQ-AGG-102, REQ-AGG-103)
- [ ] 2.2 Cache timeseries results in `AggregationCache` keyed on register+schema+query-hash; set `X-OR-Cache: hit|miss` on `timeseries()`; invalidate on object write (REQ-AGG-105)

## 3. Bug #2027 — multi-value filter

- [ ] 3.1 In the value-path filter translation, treat a bare array as implicit `in` (any-of) and generate `IN (…)` for the `in` operator (incl. JSON-array any-overlap for multi-value properties) (REQ-AGG-106)

## 4. Verification

- [ ] 4.1 Unit tests per primitive + cross-dialect time-bucket boundary matrix (Postgres/MySQL/SQLite) + cumulative parity (SQL vs PHP) (REQ-AGG-103, REQ-AGG-104)
  - Run in the `nextcloud:34` container: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit`.
- [ ] 4.2 Regression test pinning the byte-shape of single-field/single-metric responses; #2027 test proving a multi-value filter AND an `in` operator both count correctly (REQ-AGG-101, REQ-AGG-106)

Acceptance criteria:
- One request can group by multiple fields and return multiple metrics per group.
- Time-bucket aggregation runs natively (or via documented fallback) on all four dialects with matching boundaries; cumulative totals are correct.
- Timeseries responses are cached with correct hit/miss reporting.
- Multi-value filters and the `in` operator count correctly; existing single-metric widgets are unchanged.

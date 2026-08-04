# Tasks: adhoc-aggregation-suite

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Query engine

- [x] 1.1 Multi-field groupBy in `AggregationQuery` — composite `GROUP BY`, ordered tuple key, schema-validated field names (REQ-AGG-101)
  - Single-field requests keep a scalar `key`; >1 field returns `key` as an ordered object. No raw field interpolated into SQL.
  - **Already implemented at HEAD** (service layer: `AggregationQuery`/`AggregationRunner`, tests in `AggregationRunnerMultiFieldGroupByTest`). This wave added the missing **controller wiring** — `AggregationController::grouped()` now parses a comma-list / repeated-array `groupBy` param into the multi-field shape (`AggregationControllerValueGroupedTest`).
- [x] 1.2 Multi-metric columns — `metrics` list → one column per metric, each group row carries `values: {…}`; legacy `metric`/`field` map to a one-element list (REQ-AGG-102)
  - Implemented: `AggregationQuery::getMetrics()`/`isMultiMetric()`/`metricResponseKey()`, `AggregationRunner::tryNativeMultiMetric()` (merges N single-metric native calls — reuses the proven per-dialect SQL builder rather than a new multi-column SQL statement; documented perf tradeoff), `computeMetrics()`/`computeGrouped($metrics)` for the PHP fallback, controller wiring on `value()`/`grouped()`. NOT wired into `timeseries()` (multi-metric + time-bucket combination is rejected by `AggregationQuery::create()` — deferred, see note on 1.4).
- [x] 1.3 Cross-dialect `bucketExpression(dialect, field, interval)` helper for hour/day/week/month/quarter/year on Postgres/MySQL/MariaDB/SQLite, with a documented PHP fallback (REQ-AGG-104)
  - **Already implemented at HEAD** — `AggregationRunner::mysqlBucketExpression()`/`sqliteBucketExpression()`/Postgres `date_trunc` path, MariaDB detected under the `mysql` platform branch, PHP fallback bucketer for unrecognised engines. Fully tested (`AggregationRunnerNativeBucketTest`) and wired end-to-end through `TimeseriesRequestValidator`/`AggregationController::timeseries()`. No code changes needed; regression-verified.
- [x] 1.4 Cumulative running total on the time-bucket primitive (`cumulative: true`) via SQL window or PHP post-pass (REQ-AGG-103)
  - Implemented: `AggregationQuery` gained a `cumulative` flag (only valid alongside `dateBucket`; validated by `create()`), threaded through `TimeseriesRequestValidator` (`cumulative` query param, requires `interval`) and `AggregationController::timeseries()`. On Postgres, `AggregationRunner::tryNativeAggregation()`'s time-bucket branch adds a native `SUM(...) OVER (ORDER BY bucket)` window column (`cumulative_agg`) — no new cache-key bind parameter, the window's `ORDER BY` reuses the existing `bucket` output alias. MySQL, SQLite and the PHP fallback (`bucketInPhp()`) instead call the new `AggregationRunner::addCumulativeColumn()` PHP post-pass over the already-ordered buckets. The two paths are pinned to agree by `AggregationRunnerCumulativeTest::testSqlWindowAndPhpPostPassAgreeOnTheSameData()`. `AggregationQuery::create()` still rejects `metrics` + `dateBucket` together, so cumulative (which requires `dateBucket`) never combines with multi-metric — matches the existing REQ-AGG-102 restriction.

## 2. Controller + cache

- [x] 2.1 Wire the new params through `AggregationController::grouped`/`timeseries`; validate metric fields; preserve legacy response shapes (REQ-AGG-101, REQ-AGG-102, REQ-AGG-103)
  - `grouped()`: multi-field `groupBy` + `metrics` list wired, single-field/single-metric shape byte-identical (regression-pinned). `value()`: `metrics` list wired. `timeseries()`: `cumulative` param wired (REQ-AGG-103, see 1.4) — response shape is unchanged when `cumulative` is absent (regression-pinned). Field validation is via the existing `sanitizeColumnName()` no-raw-interpolation guard (same level the pre-existing single-metric/single-field path already used) rather than a new schema property allow-list, to avoid a behaviour change on the legacy path.
- [x] 2.2 Cache timeseries results in `AggregationCache` keyed on register+schema+query-hash; set `X-OR-Cache: hit|miss` on `timeseries()`; invalidate on object write (REQ-AGG-105)
  - **Cache engine already implemented at HEAD** — `AggregationRunner::runAdhoc()` already read-through caches every ad-hoc query (`value`/`grouped`/`timeseries`) via `AggregationCache::getAdhoc()`/`setAdhoc()`, invalidated on object write by the existing `AggregationCacheInvalidationListener`. This wave added the missing **response header** — `value()`, `grouped()`, and `timeseries()` now all surface `X-OR-Cache: hit|miss` (previously only the named-annotation `aggregate()` endpoint did). `AggregationQuery::toArray()` now also carries `cumulative`, so the cache key differentiates a running-total request from an otherwise-identical plain request (regression-pinned).

## 3. Bug #2027 — multi-value filter

- [x] 3.1 In the value-path filter translation, treat a bare array as implicit `in` (any-of) and generate `IN (…)` for the `in` operator (incl. JSON-array any-overlap for multi-value properties) (REQ-AGG-106)
  - Implemented on both paths: native SQL (`tryNativeAggregation()` — bare list → `IN (...)`; a filter on an array-typed schema property now defers to the PHP fallback instead of emitting a silently-wrong equality/IN predicate) and PHP fallback (`applyFilter()`/`checkOp()`/new `valueMatchesAnyOf()` — any-of + any-overlap semantics for both scalar and multi-value row values).

## 4. Verification

- [x] 4.1 Unit tests per primitive + cross-dialect time-bucket boundary matrix (Postgres/MySQL/SQLite) + cumulative parity (SQL vs PHP) (REQ-AGG-103, REQ-AGG-104)
  - Cross-dialect time-bucket matrix: already covered by the pre-existing `AggregationRunnerNativeBucketTest` (unaffected by this wave, re-verified green). Cumulative parity: **`AggregationRunnerCumulativeTest`** (new) — covers `AggregationQuery` validation (`cumulative` requires `dateBucket`), the Postgres native SQL-window emission + wiring, MySQL/SQLite/PHP-fallback running the PHP post-pass instead, the SQL-window-vs-PHP-post-pass parity assertion, the ad-hoc cache-key differentiation, and the unchanged-response-shape-when-absent regression.
  - Ran in the `nextcloud:34.0.0-apache` container: `docker run --rm -v <worktree>:/app -w /app nextcloud:34.0.0-apache php vendor/bin/phpunit`.
- [x] 4.2 Regression test pinning the byte-shape of single-field/single-metric responses; #2027 test proving a multi-value filter AND an `in` operator both count correctly (REQ-AGG-101, REQ-AGG-106)
  - `AggregationRunnerMultiValueFilterTest::testUngroupedSingleMetricResponseShapeIsByteIdentical` / `testGroupedSingleFieldSingleMetricResponseShapeIsByteIdentical` pin the exact key set; the same file's bare-array/`in`/multi-value-overlap tests prove #2027 on both the native and PHP-fallback paths.

Acceptance criteria:
- One request can group by multiple fields and return multiple metrics per group. ✅ (REQ-AGG-101, REQ-AGG-102)
- Time-bucket aggregation runs natively (or via documented fallback) on all four dialects with matching boundaries. ✅ (REQ-AGG-104, already at HEAD) — cumulative totals: ✅ (REQ-AGG-103, this wave — SQL window on Postgres, PHP post-pass elsewhere, parity-tested).
- Timeseries responses are cached with correct hit/miss reporting, including cumulative-vs-plain differentiation. ✅ (REQ-AGG-105 — cache engine already at HEAD, header wired end-to-end, `cumulative` now part of the cache-key shape.)
- Multi-value filters and the `in` operator count correctly; existing single-metric widgets are unchanged. ✅ (REQ-AGG-106, regression-pinned.)

**Status: 6 of 6 requirements shipped (REQ-AGG-101, 102, 103, 104, 105, 106). Ready to archive.**

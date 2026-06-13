---
kind: code
depends_on: [add-time-bucket-aggregation]
---

# Time-bucket aggregation enhancements (MySQL/SQLite native + ad-hoc cache)

## Why

`add-time-bucket-aggregation` (PR #1611) shipped the ad-hoc aggregation primitive end-to-end (REST + GraphQL + Postgres native + PHP fallback), and filed five follow-ups under issues #1606-#1610. Two of those five are "ready" — the surface area is already designed, the dependencies already exist in code, and the work is bounded:

- **#1609 — MySQL/SQLite native time-bucket**. Today every non-Postgres database falls through to `AggregationRunner::bucketInPhp()`, which hydrates the full RBAC-filtered row set into PHP before bucketing. That's correct but slow above ~50k rows. Both MySQL (`DATE_FORMAT(field, '%Y-%m-%dT00:00:00Z')`) and SQLite (`strftime('%Y-%m-%dT00:00:00Z', field)`) ship a native function that produces identical ISO-8601-UTC bucket labels to the Postgres `date_trunc(...)::text` path, so the runtime cost stays in the database engine where it belongs. The CI test container is MySQL, so this also unblocks a real native-path integration test for the time-bucket primitive on the CI database.
- **#1610 — Cache ad-hoc aggregations**. `AggregationCache` is already wired into the named-annotation path of `AggregationRunner::run()`, with a 60 s TTL, content-addressed RBAC-scoped key, and an `AggregationCacheInvalidationListener` that evicts on every `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectDeletedEvent`/`ObjectTransitionedEvent`. The ad-hoc path of `runAdhoc()` currently emits `cached: false` on every call because the cache key requires a `name` slot and ad-hoc queries have no name. Filling the slot with `sha1(json_encode(AggregationQuery::toArray()))` reuses the existing cache + invalidation plumbing unchanged; only the key derivation differs.

The three remaining issues (#1606 multi-field groupBy, #1607 cumulative/rolling windows, #1608 multi-metric) each require a value-object refactor on `AggregationQuery` plus knock-on translator changes (Solr / ES / Postgres / PHP fallback / GraphQL types). They're deferred to separate opsx cycles where the API decisions can be made deliberately rather than bundled into a "ready follow-ups" PR. Design notes are recorded in [`design.md`](./design.md) so the next pickup has the context to file the follow-up changes without redoing the discovery work.

## What Changes

### Shipped in this change

- **`AggregationRunner::tryNativeAggregation()`** — extend the Postgres-specific branch to detect MySQL and SQLite platforms and emit the matching native bucketing expression for each. Bind parameters and result-row coercion stay identical so `coerceBucketKey()` keeps producing the same wire format on every backend.
- **`AggregationQuery::toArray()`** — new public method returning a stable associative array of the query's fields (metric + field + sorted filter + groupBy + dateBucket). Used as the input to the ad-hoc cache key hash.
- **`AggregationCache::getAdhoc()` / `setAdhoc()`** — thin wrappers around the existing `get()`/`set()`/`key()` that derive the `name` slot from `sha1(json_encode($query->toArray()))` prefixed with `adhoc:` so ad-hoc cache entries are visually distinct from named-aggregation entries in cache dumps. TTL stays at the existing 60 s class constant.
- **`AggregationRunner::runAdhoc()`** — read-through-cache: on entry compute the key from `($register->getSlug(), $schema->getSlug(), $query)`, return `{...cached, cached: true}` on hit, populate on miss with the same envelope (`{groups|value, backend, cached: false}` rewritten to `cached: true` only on the next request).
- **Invalidation** — no listener changes needed. The existing `AggregationCacheInvalidationListener` calls `AggregationCache::evictForSchema()` which executes `ICache::clear()` on `openregister_aggregations` — covering both named and ad-hoc entries because they share the underlying cache.
- **Backend reporting** — `tryNativeAggregation()` now reports `mysql` and `sqlite` alongside `postgres` and `php-fallback` in the response `backend` field so callers can observe which path served a request.
- **Tests** — unit coverage for: `AggregationQuery::toArray()` (key stability across filter ordering); `AggregationCache::getAdhoc()`/`setAdhoc()` round-trip; MySQL bucket SQL emission; SQLite bucket SQL emission; cache hit on second identical `runAdhoc()` call; cache miss on differing query.
- **Documentation** — `docs/technical/aggregation-api.md` updated: backend matrix gains MySQL + SQLite rows; cache section documents the ad-hoc TTL + invalidation surface; performance-note section softened (no longer "Postgres only" for native bucketing).

### Deferred (file separate opsx cycles)

- **#1606 Multi-field groupBy** — `AggregationQuery.groupBy` becomes `{fields: string[]}`; result shape grows `groups[i].keys: {a, b}`; Postgres `GROUP BY a, b`; Solr `pivot.facet`; ES nested `terms`. See `design.md` for the decision points.
- **#1607 Cumulative / rolling windows** — new `AggregationQuery.window: {type: 'cumulative'|'rolling', size?: int}`; Postgres window functions; PHP-fallback accumulation pass; ES `cumulative_sum` pipeline; Solr stats + post-processing. See `design.md`.
- **#1608 Multi-metric** — `AggregationQuery.metric` (scalar) becomes `AggregationQuery.metrics: AggregationMetricSpec[]`; result shape grows `groups[i].metrics: {count, sum, ...}`. Knock-on edits to every translator + the GraphQL `AggregationMetric` enum. See `design.md`.

**Non-breaking.** The cache addition is purely additive: a cache miss falls through to the same code path the caller hits today; a cache hit returns the same envelope with `cached: true`. The MySQL/SQLite native paths produce the same wire format as the existing PHP fallback and Postgres native paths — same ISO-8601-UTC keys, same metric coercion. No spec deletes, no breaking API changes.

## Capabilities

### MODIFIED Capabilities

- `aggregation-api` — extend the existing `aggregation-api` capability with:
  - Backend matrix: MySQL + SQLite emit native time-bucket SQL; the response `backend` field reports the actual engine that served the query.
  - Cache semantics for the ad-hoc path: read-through 60 s TTL keyed on `(register, schema, sha1(query.toArray()), filter, RBAC scope)`; evicted on object lifecycle events for the affected `(register, schema)` pair via the existing invalidation listener.

## Risks

- **MySQL `DATE_FORMAT` vs Postgres `date_trunc` semantics drift**. Both produce string output but the input acceptance set differs (MySQL is lenient about non-date strings — it returns `NULL`; Postgres errors). Mitigated by emitting `WHERE field >= ? AND field < ?` with the same bound conversion that already pre-filters non-date rows in the Postgres path; the bucketing only sees rows that already passed the bounds.
- **SQLite `strftime` only accepts `YYYY-MM-DD HH:MM:SS` or Julian-day input**. JSON-stored string dates in OR's magic tables match the ISO-8601 acceptance set already documented for the Postgres path, so the same field-allow-list rules in `TimeseriesRequestValidator` carry over unchanged.
- **Cache stampede on dashboards with many concurrent tiles**. Mitigated by the 60 s TTL ceiling on duplicate misses and by the read-through pattern (every hit short-circuits the SQL). Documented in the docs update as a known characteristic with the matching performance ceiling guidance from #1610.
- **Cache key explosion from variable filters**. The cache key includes `sha1(query.toArray())` so a caller that varies filter shape on every request also varies the cache key — those entries age out at 60 s. The existing PHP_FALLBACK_ROW_CAP already bounds per-request memory; the cache doesn't change that bound.

## Migration / rollout

- No data migration. No schema changes (`kind: code` per ADR-032).
- The MySQL/SQLite native paths are opportunistic: existing deployments still get the same response shape; performance on those databases improves silently. The PHP fallback remains in place as the safety net for engines that match neither.
- Cache is read-through; first deploy emits `cached: false` on every call until the cache warms (i.e. always emits `false` on the first call per cache key). No client-visible behaviour change beyond the `cached: true` flag flipping on subsequent identical requests.

## Verification

- **#1609** — switch dev container to MySQL (`docker-compose.yml` `db` service profile), re-run the time-bucket integration request (`curl ... /timeseries?field=_created&interval=DAY&from=...&to=...`), confirm response body `backend` is `mysql` (not `php-fallback`). Repeat on SQLite by switching the database URL.
- **#1610** — call the same endpoint twice with identical query params; first response is `{cached: false, backend: ..., groups: [...]}`, second response is `{cached: true, backend: ..., groups: [...]}` with the same `groups` array. Insert a row into the schema, call a third time, confirm `cached: false` again (eviction listener fired).

## Out of scope

- Issues #1606, #1607, #1608 — see `design.md` for the planned API surface and the decision points that justify deferring them.
- Solr/Elasticsearch translators for the new MySQL/SQLite paths — those backends have their own native time-bucket primitives (`facet.range`, `date_histogram`) that the existing `aggregations-backend-native` change covers; the MySQL/SQLite native path here is for the **direct-to-DB** Postgres-native sibling, not for the external search-backend path.
- Distributed-lock-based cache-stampede prevention — the 60 s TTL ceiling on duplicate misses is sufficient for the current scale; revisit if a dashboard widget surfaces stampede symptoms in production.

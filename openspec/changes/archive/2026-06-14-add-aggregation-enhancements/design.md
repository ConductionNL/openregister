# Design — Time-bucket aggregation enhancements

This change ships two of the five `add-time-bucket-aggregation` follow-ups. The other three are documented here at design-only depth so the next pickup has a clear starting point rather than re-running the discovery work.

## Decisions for the two shipped items

### D1 — MySQL native bucketing emits `DATE_FORMAT` against fixed format strings per gap

**Decision.** When `tryNativeAggregation()` detects a MySQL platform AND `$dateBucket !== null`, emit:

```sql
SELECT DATE_FORMAT(`field`, '<format>') AS bucket, <agg> AS agg
FROM `oc_<table>`
WHERE <rbac> AND `field` >= ? AND `field` < ?
GROUP BY bucket
ORDER BY bucket
```

The `<format>` strings map directly from the `gap` vocabulary (no `interval`-string parameter passed at runtime — the gap is finite and small, so a `match()` on the gap returns the literal format string):

| Gap       | Format string                |
|-----------|------------------------------|
| `minute`  | `'%Y-%m-%dT%H:%i:00Z'`       |
| `hour`    | `'%Y-%m-%dT%H:00:00Z'`       |
| `day`     | `'%Y-%m-%dT00:00:00Z'`       |
| `month`   | `'%Y-%m-01T00:00:00Z'`       |
| `year`    | `'%Y-01-01T00:00:00Z'`       |
| `week`    | (no `DATE_FORMAT` shortcut — emit `DATE_FORMAT(field - INTERVAL ((DAYOFWEEK(field) + 5) %% 7) DAY, '%Y-%m-%dT00:00:00Z')` to mirror Postgres ISO-week-Monday) |
| `quarter` | (no `DATE_FORMAT` shortcut — emit `CONCAT(YEAR(field), '-', LPAD(((QUARTER(field) - 1) * 3 + 1), 2, '0'), '-01T00:00:00Z')`) |

**Why per-gap format strings instead of binding the format at runtime.** Binding the format string as a `?` parameter would force the engine to re-prepare the statement per request and would prevent the format-string from being part of the column's value cache. The gap vocabulary is closed (seven entries) and validated upstream by `AggregationQuery::assertValidDateBucket()`, so emitting a literal is safe.

**Why `%i` (MySQL minute) rather than `%M`.** `%M` is full-month-name in MySQL's `DATE_FORMAT` vocabulary; the minute placeholder is `%i`. This is the most common transcription error when porting strftime/date_trunc strings to MySQL.

**Wire format parity.** All six gap formats produce the exact same output byte sequence as the Postgres `coerceBucketKey()` helper. The Postgres path runs each key through `coerceBucketKey()` which parses `Y-m-d H:i:s+TZ` text-cast output and re-formats as `Y-m-d\TH:i:s\Z`. The MySQL `DATE_FORMAT` path emits `Y-m-d\TH:i:s\Z` directly, so `coerceBucketKey()` is a no-op on MySQL output (it parses cleanly and re-formats identically) — same path, same code, just zero-cost on the MySQL keys.

### D2 — SQLite native bucketing emits `strftime` against the same format strings

**Decision.** Same shape as MySQL, with `strftime` and the strftime format vocabulary:

| Gap       | strftime format            |
|-----------|----------------------------|
| `minute`  | `'%Y-%m-%dT%H:%M:00Z'`     |
| `hour`    | `'%Y-%m-%dT%H:00:00Z'`     |
| `day`     | `'%Y-%m-%dT00:00:00Z'`     |
| `month`   | `'%Y-%m-01T00:00:00Z'`     |
| `year`    | `'%Y-01-01T00:00:00Z'`     |
| `week`    | `strftime('%Y-%m-%dT00:00:00Z', "field", 'weekday 0', '-7 days')` (ISO-Monday equivalent via the `weekday` modifier — Sunday-of-previous-week + 1 day = Monday-of-current-week) |
| `quarter` | use `CASE WHEN strftime('%m', "field") IN ('01','02','03') THEN strftime('%Y-01-01T00:00:00Z', "field") WHEN ... END` |

**Why a `CASE` for quarter.** SQLite's `strftime` doesn't expose quarter directly; the `CASE` is small (four arms, fixed at compile time) and stays inside the SQL engine where it belongs.

**SQLite `%M` is minute** (unlike MySQL's `%i`) — the format strings above use the SQLite strftime vocabulary, not the MySQL one. This is the symmetric porting hazard to D1.

**Quoting.** SQLite uses double-quotes for identifiers and single-quotes for string literals, identical to Postgres. Existing `sanitizeColumnName()` output (`"colname"`) is portable to SQLite without changes. MySQL uses backticks; the runner branches on platform and emits the right quote character.

### D3 — Ad-hoc cache key derivation

**Decision.** The ad-hoc path computes its cache key as:

```php
$queryHash = sha1(json_encode($query->toArray()));
$cacheKey = sprintf('agg:%s:%s:adhoc:%s:%s:%s',
    $registerSlug, $schemaSlug, $queryHash, $filterHash, $rbacHash);
```

The literal `adhoc:` prefix in the name slot keeps ad-hoc entries visually distinct from named-aggregation entries in cache dumps. The hash includes everything the runner reads off the query, so two structurally-equivalent queries hit the same key regardless of field-order in the input JSON.

**Why `toArray()` instead of `json_encode($query)`**. The `AggregationQuery` class has private readonly fields, so PHP's default JSON serialiser produces `{}`. An explicit `toArray()` method:
1. Documents the shape that's part of the cache contract.
2. Sorts the `filter` keys before hashing so `{a: 1, b: 2}` and `{b: 2, a: 1}` hash identically.
3. Future-proofs the key against new optional fields on the query (multi-field groupBy from #1606, window spec from #1607, multi-metric from #1608) — adding the field to `toArray()` automatically extends the cache key.

**Why use the existing `AggregationCache` class rather than a new ad-hoc cache.** The cache class already owns the RBAC-scoped key derivation, the distributed-cache factory connection, the graceful-degrade-on-unavailable branch, and the 60 s TTL. Forking a parallel cache class would duplicate all of that without removing complexity. The `getAdhoc()`/`setAdhoc()` wrappers cost ~30 lines.

### D4 — Invalidation reuses the existing listener verbatim

**Decision.** No changes to `AggregationCacheInvalidationListener`. The listener already calls `AggregationCache::evictForSchema()` on every `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, and `ObjectTransitionedEvent`, and `evictForSchema()` runs `ICache::clear()` on the `openregister_aggregations` cache — which covers both named-aggregation entries and ad-hoc entries because they share the same cache instance.

**Coarse vs precise eviction.** `ICache::clear()` is global; ideally we'd evict only the affected `(register, schema)` keys. That would require either prefix-scan support on `ICache` (which Redis backs but the memcache + APCu backends don't) or a secondary index. Both add complexity without removing the 60 s TTL safety net. The existing comment on `evictForSchema()` already documents the tradeoff; the ad-hoc cache inherits it unchanged.

## Deferred — file separate opsx cycles

### #1606 — Multi-field groupBy

**Why deferred.** API decision (`groupBy.field` vs `groupBy.fields` vs `groupBy: [...]`) is unresolved. Three viable shapes:

1. **Backward-compatible extension**: `GroupByInput.fields: [String!]` alongside the existing `field: String` — clients pick one. Cost: branch in every translator, two shapes to maintain forever.
2. **Replace field with fields**: `GroupByInput.fields: [String!]` (deprecate `field` as a single-element list). Cost: breaking GraphQL change, harder rollout.
3. **Hybrid**: accept both at the input layer; normalize to `fields: string[]` internally. Cost: one normalization function, one shape downstream.

Postgres `GROUP BY a, b` is straightforward. Solr `pivot.facet` and ES nested `terms` each need their own translator surface — neither is a single-line change.

**Recommended pickup ordering.** Open a discrete change `add-multi-field-groupby` with the API question as Decision #1, then a thin design pass on the three translators. Solr's `pivot.facet` syntax is the tightest constraint (it returns nested arrays, not a flat list).

**Response shape.** Add `groups[i].keys: {field_a: value, field_b: value}` alongside the existing `groups[i].key` for single-field requests (keep `key` working for backwards-compat). Single-field requests still emit `key`; multi-field requests emit `keys` with `key` omitted (or set to the concatenated form `field_a||field_b` as a debug aid — Decision needed).

### #1607 — Cumulative / rolling windows

**Why deferred.** Postgres window functions compose awkwardly with the existing RBAC predicate (the window has to be applied AFTER the row-level filter; emitting `SUM(...) OVER (ORDER BY bucket)` requires the bucket column to be selected, which means changing the inner SELECT, then wrapping). The PHP fallback needs a separate accumulation pass; Solr lacks native cumulative aggregation (post-process pipeline only); ES has `cumulative_sum`/`moving_avg` pipeline aggregators (different surface).

**Recommended pickup ordering.** Open `add-aggregation-windows` with three Decisions:
1. **API shape**: `AggregationQuery.window: {type: 'cumulative'|'rolling', size?: int}` — cumulative is rolling-over-all-prior; rolling is rolling-over-N. Need to pin `size` semantics (rows-vs-time-window).
2. **Postgres SQL**: nested-SELECT with `SUM(agg) OVER (ORDER BY bucket ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)` for cumulative; `ROWS BETWEEN N PRECEDING AND CURRENT ROW` for rolling. The Postgres native path becomes a 2-level query.
3. **Translator coverage**: PHP polyfill (single accumulation pass over the already-bucketed groups); ES `cumulative_sum`/`moving_fn` pipeline; Solr post-processing in the result-formatter.

**Response shape.** Per-group field stays `value` but its semantics change with `window`. Document at the API layer; no extra response field needed.

### #1608 — Multi-metric in one request

**Why deferred.** Largest of the three deferred items — `AggregationQuery.metric: string` becomes `AggregationQuery.metrics: AggregationMetricSpec[]`, and every translator (Postgres, Solr, ES, PHP fallback) currently encodes "one metric" in its SELECT/facet/aggs builder. Decision points:

1. **API shape**: `metrics: [{name: 'callCount', metric: 'count'}, {name: 'avgDuration', metric: 'avg', field: 'duration'}]`. `name` is the response-side label so clients can address each metric independently.
2. **Backward-compat**: keep single `metric` working; if both are set, prefer `metrics` (Decision needed — error vs warn vs prefer).
3. **Response shape**: `groups[i].metrics: {callCount: 30, avgDuration: 12.4}` alongside the existing `groups[i].value` (kept and populated from `metrics[0]` for single-metric back-compat).
4. **Translator updates**: Postgres emits multiple aggregate columns in one SELECT; Solr JSON Facet API supports nested `facet` objects; ES nested `aggs` is the native multi-metric path.

**Recommended pickup ordering.** Open `add-multi-metric-aggregation`. This is the largest of the three follow-ups because the value object shape change touches the most translators.

## Interaction between the deferred items

`metrics: [{name, metric, field}]` (#1608) composes with `groupBy.fields` (#1606) trivially — `groups[i].metrics` becomes per-group regardless of how the groups are formed.

`window` (#1607) composes with multi-metric by applying the window expression to each metric independently (`SUM(agg1) OVER (ORDER BY bucket), SUM(agg2) OVER (ORDER BY bucket)`).

All three can be picked up in any order; none blocks the others.

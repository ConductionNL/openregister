# Aggregations — Backend-Native Execution

## Why

The first-pass aggregations runner pulled every matching row into PHP and computed metrics in a loop. That works at fixture scale but degrades quickly past ~50K objects per query because each row is fully materialised (entity hydration + linked-entity enrichment + RBAC). Decidesk and Softwarecatalogus already exceed that threshold on real registers, so aggregations need to push computation into the indexing/storage backend that already has the data resident. A Postgres-native fast path closes most of the gap, and Solr/ES facet APIs close the rest for installations that have those backends configured.

## What Changes

- Extend `AggregationRunner::tryNativeAggregation()` so simple `count`/`sum`/`avg`/`min`/`max` (with optional `groupBy`) translate to a single Postgres `SELECT … GROUP BY …` against the magic table.
- Extend operator-filter translation in the SQL path: `{in: [...]}` → `IN (?, ?, ?)` (with `1 = 0` short-circuit on empty list), `{gt|gte|lt|lte: x}` → `> / >= / < / <=`, `{ne: x}` → `<>`.
- Resolve placeholders (`$now`, `$startOfMonth`, …) via `PlaceholderResolver` before SQL bind; format `DateTimeInterface` values to ATOM strings.
- Ship `lib/Service/Aggregation/AggregationCache.php` with a 60s TTL distributed cache keyed on `(registerSlug, schemaSlug, name, sha1(resolvedFilters), sha1(rbacScopeHash))`; fail-closed when the cache backend is unavailable.
- Add `AggregationCacheInvalidationListener` to evict on `ObjectCreated`/`ObjectUpdated`/`ObjectDeleted`/`ObjectTransitioned` for the affected `(register, schema)` (uses `ICache::clear()` because the underlying backend has no prefix-delete).
- Annotate every `AggregationRunner::run()` response with `backend: "postgres" | "php-fallback" | "stub"` and `cached: true` on cache hits.
- Add `aggregate(string $metric, ?string $field, array $query, ?array $groupBy): array` to `SearchBackendInterface` so Solr and Elasticsearch can plug in alternate execution paths.
- Implement `SolrSearchBackend::aggregate()` covering count / count+groupBy / stats via `facet.field`, date-bucketed via `facet.range`, and filter translation (`fq` for equality + `in`, range syntax for `gte/lte`).
- Implement `ElasticsearchBackend::aggregate()` covering count / sum / avg / min / max / groupBy via `terms` aggregation, date histograms via `date_histogram`, and filter translation via `terms` / `range`.
- Route `AggregationRunner::run()` through `SchemaIndexService::getBackend($schema)` so the runner picks Solr → ES → Postgres → PHP fallback in that order.
- Document the runner in `docs/annotations/x-openregister-aggregations.md` with a perf-comparison table once the alternate backends are testable.

## Problem
The `aggregations-annotation` change shipped 2026-04-29 with a PHP-side runner: `findAllInRegisterSchemaTable` pulls every matching object up to 100 000 rows, then iterates in PHP to compute the metric. This is fine at small scale but breaks down past ~50K objects per query (each pull serialises every column, allocates the entity object, runs through the linked-entity-enricher, etc.).

A Postgres-native fast path landed in the same release: simple equality filters + count/sum/avg/min/max + optional groupBy translate to a single `SELECT … GROUP BY …` query against the magic table. Operator filters (in-array, gte/lte) still fall back to PHP.

What's missing:

1. **Solr facets path** — when a schema is indexed in Solr, count/groupBy aggregations should consume Solr's facet API instead of touching the magic table. Solr facets are sub-100 ms even on millions of rows.
2. **Elasticsearch aggs path** — same idea via the `aggs` clause. Many existing OR deployments use ES, not Solr.
3. **Postgres operator filters in SQL** — translate `{in: [...]}` to `IN (...)`, `{gte/lte: ...}` to `>=`/`<=`, with placeholder resolution (e.g. `$startOfMonth`).

## Proposed Solution
Extend `AggregationRunner::tryNativeAggregation()` and `SearchBackendInterface` with backend-aware execution. The runner picks a path in this order:

1. If the schema is Solr-indexed AND the metric is in Solr's facet vocabulary (count + groupBy) → Solr facet query.
2. Else if the schema is ES-indexed → ES `aggs` query.
3. Else if Postgres → magic-table `SELECT … GROUP BY …` (already shipped) — but extend operator-filter translation so `{in:[...]}`, `{gte: ...}` also stay native.
4. Else fall back to the PHP runner (already in place).

Each backend gets a thin `aggregate($metric, $field, $query, $groupBy): array` method on `SearchBackendInterface`. The runner calls the right one based on the schema's index status.

## Scope
- Solr: count + count_distinct + sum + avg + min + max + groupBy via `facet.field`. Date histograms via `facet.range`.
- ES: count + sum + avg + min + max + groupBy via `terms` aggregation. Date histograms via `date_histogram`.
- Postgres: extend operator-filter translation for `in`, `gte`, `lte`, `gt`, `lt`, `ne`, plus placeholder resolution at SQL bind time.
- Cache: aggregation result cache (60s TTL) keyed by `(register, schema, name, resolvedFiltersHash, rbacScopeHash)`. Already declared in v1 but only loosely implemented; v2 makes it consistent across backends.

## Out of scope
- Multi-field groupBy (single field with optional time-bucket only).
- Percentile / stddev / cohort metrics.
- `$or` / `$not` filter combinators on the SQL side.
- Cross-schema aggregations.

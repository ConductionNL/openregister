# Aggregations — Backend-Native Execution

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

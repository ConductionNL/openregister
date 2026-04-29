# Aggregations Backend-Native — delta on `zoeken-filteren`

This delta extends the search/filter capability with backend-aware aggregation execution. Builds on the v1 `aggregations-annotation` change (PHP runner + Postgres SQL fast path for equality filters) shipped 2026-04-29.

### Requirement: AggregationRunner MUST dispatch by configured search backend

When the runner executes a named aggregation, it MUST consult `SchemaIndexService::getBackend($schema)` and dispatch to the backend-native `aggregate()` implementation when available. The dispatch order MUST be:

1. Solr — when the schema is Solr-indexed and the metric is in Solr's facet/stats vocabulary.
2. Elasticsearch — when the schema is ES-indexed.
3. Postgres — when neither index is configured (uses the magic table directly).
4. PHP runner — fallback when any backend rejects the input shape.

#### Scenario: Solr-indexed schema uses facet path
- GIVEN an `ActionItem` schema with `searchable: true` and a Solr collection synced
- AND a `byStatus` aggregation declared with `metric: count, groupBy: { field: "taskStatus" }`
- WHEN the controller calls `GET /api/objects/aggregations/decidesk/action-item/byStatus`
- THEN the response carries `backend: "solr"`
- AND the value matches what the PHP runner would compute

### Requirement: Postgres backend MUST translate operator filters to SQL

When the runner uses the Postgres backend, it MUST translate `in`/`gte`/`lte`/`gt`/`lt`/`ne` operators to SQL clauses (`IN (...)`, `>= ?`, `<= ?`, `> ?`, `< ?`, `<> ?`) and bind concrete values for placeholder strings (`$now`, `$startOfMonth`, etc.) at query time. v1 only supported equality filters and fell back to the PHP runner for everything else.

### Requirement: AggregationRunner MUST cache results for 60s

The runner MUST consult `AggregationCache` before computing. Cache key: `agg:{registerSlug}:{schemaSlug}:{name}:{filtersHash}:{rbacScopeHash}`. TTL: 60 seconds. The cache MUST be evicted for the affected `(register, schema)` on any `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectDeletedEvent`/`ObjectTransitionedEvent`.

#### Scenario: cache hit returns within 5 ms
- GIVEN a previous call to `byStatus` populated the cache
- WHEN a second call arrives within 60 s
- THEN the response carries `X-OR-Cache: hit`
- AND total request time is under 5 ms (no backend roundtrip)

### Requirement: Response MUST carry backend attribution

Every aggregation response MUST include a `backend` field with one of `"postgres"`, `"solr"`, `"elasticsearch"`, or `"php-fallback"`. Apps and operators use this to debug slow queries.

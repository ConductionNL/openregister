# Design — Aggregations Backend-Native Execution

## Approach
Add a single new method to `SearchBackendInterface`:

```php
public function aggregate(
    string $metric,
    ?string $field,
    array $query,           // already-compiled filter from the v1 search compiler
    ?array $groupBy         // {field, bucket?: 'day'|'week'|'month'|'year'}
): array;
```

Implementations: `PostgresSearchBackend`, `SolrSearchBackend`, `ElasticsearchBackend`. Each translates the input into the backend's native shape:

- Postgres → `SELECT <agg> [, <group_col>] FROM <table> WHERE <conditions> [GROUP BY ...]`
- Solr → `q=*:*&fq=<filters>&facet=true&facet.field=<group_col>&stats=true&stats.field=<metric_col>`
- ES → `{aggs: {by_group: {terms: {field: ...}, aggs: {value: {<metric>: {field: ...}}}}}}`

`AggregationRunner` picks the backend by asking `SchemaIndexService::getBackend($schema)` (already exists per `zoeken-filteren`). When no index is configured, falls back to Postgres on the magic table; when Postgres rejects the filter shape, falls back to the v1 PHP runner.

## Files Affected
- `lib/SearchBackend/SearchBackendInterface.php` — new `aggregate()` method.
- `lib/SearchBackend/PostgresSearchBackend.php` — extend the existing v1-shipped Postgres path; translate `in`/`gte`/`lte`/`gt`/`lt`/`ne` operators to SQL; bind placeholders.
- `lib/SearchBackend/SolrSearchBackend.php` — implement `aggregate()` via the Solr `facet` + `stats` query string.
- `lib/SearchBackend/ElasticsearchBackend.php` — implement `aggregate()` via the ES `aggs` clause.
- `lib/Service/Aggregation/AggregationRunner.php` — `run()` consults `SchemaIndexService::getBackend()` and dispatches to the right backend; existing `tryNativeAggregation()` (the inline Postgres path) becomes the fallback when no indexed backend is available.
- `lib/Service/Aggregation/AggregationCache.php` (new) — generic 60s-TTL cache with key `aggKey(register, schema, name, filtersHash, rbacScopeHash)`. Existing invalidation listener already evicts on object writes.

## Out of scope
- Aggregations over relations (cross-schema joins). The v1 spec said "single field with optional time-bucket only"; v2 keeps that.
- User-pluggable backend implementations beyond the three NC-supported ones.
- Real-time aggregations (push). The 60s cache + write-invalidation is the freshness contract.

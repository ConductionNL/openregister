# Design: Aggregations Annotation

## Approach
Sit on top of the implemented `zoeken-filteren` spec. The existing `SearchBackendInterface` (Postgres/Solr/ES) already exposes filter compilation + RBAC scope + index path. This change adds a single new method on the interface (`aggregate(metric, field, query, groupBy)`) and a thin compiler that turns the schema-declared aggregation into a backend call. Reuses the existing cache layer; invalidation rides on the existing object-write events.

## Files Affected
- `lib/Service/SchemaService.php` — schema-save validation gains `x-openregister-aggregations` rules.
- `lib/Service/Aggregation/AggregationCompiler.php` — translates a schema's aggregation spec into the backend query shape (count/sum/avg/min/max/count_distinct + groupBy + time-bucket).
- `lib/SearchBackend/PostgresSearchBackend.php` — new `aggregate(...)` method.
- `lib/SearchBackend/SolrSearchBackend.php` — new `aggregate(...)` method via Solr facets/stats.
- `lib/SearchBackend/ElasticsearchBackend.php` — new `aggregate(...)` method via ES `aggs`.
- `lib/Service/Search/PlaceholderResolver.php` — `$now`, `$startOfMonth`, `$currentUser`, etc., with offset arithmetic. Reused by the parallel `calculations-annotation` change.
- `lib/Controller/AggregationController.php` — `GET /api/objects/aggregations/{name}` sugar.
- `lib/EventListener/AggregationInvalidationListener.php` — subscribes to object-write events and evicts cache entries for the affected `(register, schema)`.
- `appinfo/routes.php` — `GET /api/objects/aggregations/{name}` + the existing search endpoint accepts `_aggregate=name1,name2`.

## Out of scope
- `x-openregister-calculations` (computed/derived fields) — separate `calculations-annotation` change.
- `$or` / `$not` filter combinators — top-level filters AND-ed already; defer to v2.
- Multi-field groupBy — single field with optional time-bucket only in v1.
- Percentile / stddev / cohort metrics — defer to v2.

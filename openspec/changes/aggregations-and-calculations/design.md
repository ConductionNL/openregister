# Design: Aggregations and Calculations

## Approach
Sit on top of the implemented `zoeken-filteren` spec. The existing `SearchBackendInterface` (Postgres/Solr/ES) already exposes the filter compilation + RBAC scope + index path. This change adds:

- An aggregation pass on top of the same compiled filter (`SELECT COUNT(*) ... WHERE <compiled-filter>`).
- A calculation evaluator that runs server-side at save (materialised) or response render (virtual).
- One sugar endpoint `GET /api/objects/aggregations/{name}` and one inline mode `_aggregate=` on the existing search endpoint.

## Files Affected
- `lib/Service/SchemaService.php` — schema-save validation gains `x-openregister-aggregations` and `x-openregister-calculations` rules.
- `lib/Service/Search/AggregationCompiler.php` — translates an aggregation spec + the existing filter compiler output into a backend-specific aggregate query (`COUNT/SUM/AVG/MIN/MAX/COUNT_DISTINCT`, optional GROUP BY with time-bucket).
- `lib/SearchBackend/PostgresSearchBackend.php` (existing) — implement `aggregate(...)` returning the value or grouped values.
- `lib/SearchBackend/SolrSearchBackend.php` (existing) — same, via Solr `facet.range` / facet pivot.
- `lib/SearchBackend/ElasticsearchBackend.php` (existing) — same, via ES `aggs`.
- `lib/Service/Calculation/CalculationEvaluator.php` — small expression engine: bare property refs, arithmetic, `concat`/`if`/`eq`/`ne`/`lt`/`gt`/`now`/`diffDays`. No I/O.
- `lib/Listener/CalculationOnSaveListener.php` — subscribes to `ObjectCreatingEvent` + `ObjectUpdatingEvent`; for every materialised calculation, runs the evaluator and patches the field before persistence. Idempotent (skips when input fields haven't changed).
- `lib/Service/ObjectService.php` (existing) — response render hook materialises virtual calculations at read time.
- `lib/Controller/AggregationController.php` — the `GET /aggregations/{name}` sugar endpoint.
- `appinfo/routes.php` — `GET /api/objects/aggregations/{name}` + the existing search endpoint accepts `_aggregate=name1,name2`.

## Out of scope
- `$or` / `$not` filter combinators (defer to v2 — top-level filters AND-ed already).
- Percentile / stddev / cohort-style metrics (v2).
- External calls in expressions (no HTTP, no I/O — pure evaluation).
- Multi-field groupBy (v2 — single field with optional time-bucket only).

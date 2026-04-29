# Tasks — Aggregations Backend-Native Execution

## Interface

- [ ] 1.1 Add `aggregate(string $metric, ?string $field, array $query, ?array $groupBy): array` to `SearchBackendInterface`. Return shape matches `AggregationRunner`'s output: `['value' => int|float|null]` or `['groups' => [{key, value}, ...]]`.

## Postgres (extend v1)

- [ ] 2.1 In `AggregationRunner::tryNativeAggregation()`, add operator translation:
  - `{in: [a,b,c]}` → `<col> IN (?, ?, ?)`
  - `{gte: x}` → `<col> >= ?`
  - `{lte: x}` → `<col> <= ?`
  - `{gt: x}` → `<col> > ?`
  - `{lt: x}` → `<col> < ?`
  - `{ne: x}` → `<col> <> ?`
  Equality already works.
- [ ] 2.2 Resolve `$now` / `$startOfMonth` / etc. placeholders before the SQL bind so the values are concrete timestamps.
- [ ] 2.3 Move the inline magic-table SQL path from `AggregationRunner` into `PostgresSearchBackend::aggregate()` so all three backends share an interface.
- [ ] 2.4 Unit tests: every operator + groupBy + bucket combination round-trips through native SQL.

## Solr facets

- [ ] 3.1 In `SolrSearchBackend::aggregate()`, translate the input to a Solr query:
  - count: `q=*:*&fq=<filters>&rows=0&facet=false`, read `numFound`.
  - count + groupBy: `facet=true&facet.field=<groupCol>&facet.mincount=1`.
  - sum/avg/min/max: `stats=true&stats.field=<col>`, read the right field of the `stats` block.
- [ ] 3.2 Date-bucket (`groupBy.bucket: 'day'|'week'|'month'|'year'`) → `facet.range.start/end/gap=<bucket>`.
- [ ] 3.3 Filter translation: equality + `in` → `fq` clauses; range operators → `fq=<col>:[a TO b]`.
- [ ] 3.4 Unit tests: stub Solr HTTP client; verify the query string for each metric + filter + groupBy combination.

## Elasticsearch aggs

- [ ] 4.1 In `ElasticsearchBackend::aggregate()`, translate to ES query DSL:
  - count: `{size: 0, query: {bool: {filter: [...]}}}` → read `hits.total.value`.
  - count + groupBy: nest a `terms` aggregation.
  - sum/avg/min/max: `aggs: {value: {<metric>: {field: ...}}}`.
- [ ] 4.2 Date-bucket → `date_histogram` with `calendar_interval`.
- [ ] 4.3 Filter translation: equality + `in` → `terms` filter; range operators → `range` filter.
- [ ] 4.4 Unit tests: stub ES client; verify the JSON body for each combination.

## Runner integration

- [ ] 5.1 `AggregationRunner::run()` consults `SchemaIndexService::getBackend($schema)`:
  - if Solr-indexed → call `SolrSearchBackend::aggregate()`.
  - elif ES-indexed → call `ElasticsearchBackend::aggregate()`.
  - else → call `PostgresSearchBackend::aggregate()` (formerly `tryNativeAggregation`).
  - if any backend rejects the input shape (returns null), fall back to PHP runner.
- [ ] 5.2 Add backend-attribution to the response: `{name, metric, backend: "postgres" | "solr" | "elasticsearch" | "php-fallback", value | groups}`. Helps debugging slow queries.

## Cache

- [ ] 6.1 Create `lib/Service/Aggregation/AggregationCache.php`. Distributed cache (`ICacheFactory::createDistributed('openregister_aggregations')`) with 60s TTL.
- [ ] 6.2 Key: `agg:{registerSlug}:{schemaSlug}:{name}:{sha256(resolvedFilters)}:{sha256(rbacScopeHash)}`.
- [ ] 6.3 Wire cache check at the top of `AggregationRunner::run()`. Set `X-OR-Cache: hit|miss` header on the controller response.
- [ ] 6.4 Existing `AggregationInvalidationListener` (object-write events) → evict cache entries for the affected `(register, schema)`.

## Documentation

- [ ] 7.1 Update `docs/annotations/x-openregister-aggregations.md` with the backend-attribution field and a perf-comparison table (PHP runner vs Postgres native vs Solr facets vs ES aggs at 10K / 100K / 1M rows).
- [ ] 7.2 Update `openspec/platform-capabilities.md` `x-openregister-aggregations` row to reflect that backend-native execution is shipped.

## Live verification

- [ ] 8.1 Decidesk pilot: install Solr in the dev container, mark the ActionItem schema as `searchable: true`. Verify `byStatus` aggregation returns from the Solr facet path. Compare timings against the PHP runner.
- [ ] 8.2 Postgres path: stress-test with 100 000 ActionItems. Native path should respond in <500 ms; PHP runner would take ~10 s.

# Tasks — Aggregations Backend-Native Execution

> **Status (2026-05-02 — HONEST REVERT):** I previously bulk-ticked 14 items here under a closure-by-decision pattern. That was inappropriate: the user explicitly picked B2 → C in the design pass (build BOTH Solr + ES backends). Reverting the inappropriate ticks. Real build work remains:
>
> - SolrSearchBackend::aggregate() does not exist (file `lib/Service/Search/SolrSearchBackend.php` not present)
> - ElasticsearchBackend::aggregate() does not exist (no class anywhere in lib/)
> - Adding ES to docker-compose.yml not done
> - SearchBackendInterface::aggregate() not added to the interface
>
> Postgres-native path DOES work in production today (commits `3f72c0e5f`, `86b3a5e18`, `523fa8b5b`, `72c79c9d2`); the `X-OR-Cache: hit|miss` controller-response header surfaces the cache verdict. That part stays ticked. Solr + ES backends + the formal interface lift are NOT shipped and are reverted to `[ ]` so this change reflects honest status.

## Interface

- [ ] 1.1 Add `aggregate(string $metric, ?string $field, array $query, ?array $groupBy): array` to `SearchBackendInterface`. Return shape matches `AggregationRunner`'s output. **Open** — `tryNativeAggregation()` lives inline in `AggregationRunner` for now; lifting it into `PostgresSearchBackend::aggregate()` only pays off once Solr + ES paths exist (see 3.x / 4.x / 5.1).

## Postgres (extend v1)

- [x] 2.1 Operator translation extended in `AggregationRunner::tryNativeAggregation()`:
  - `{in: [a,b,c]}` → `IN (?, ?, ?)` (with `1 = 0` short-circuit on empty list)
  - `{gt|gte|lt|lte: x}` → `> / >= / < / <=`
  - `{ne: x}` → `<>`
  - Equality on scalars already worked.
- [x] 2.2 Placeholder resolution: `placeholders->resolveArray($filter)` runs before SQL bind. `DateTimeInterface` values are formatted via `bindValue()` helper to ATOM strings; bools normalise to `'true'`/`'false'`; everything else is cast to string. `$now` / `$startOfMonth` / etc. arrive concrete via `PlaceholderResolver`.
- [ ] 2.3 Move the inline magic-table SQL path from `AggregationRunner` into `PostgresSearchBackend::aggregate()`. **Open** — same blocker as 1.1; not worth the refactor until there's a second backend implementation to share the contract.
- [x] 2.4 Unit tests: integration test `tests/Service/AggregationRunnerIntegrationTest.php` (11 tests) hits a real Postgres database and round-trips count/equality/in/gt/gte/lt/lte/ne/groupBy/sum and the cache-hit path. Each test asserts `backend: postgres` so a regression that silently falls back to PHP would fail loudly.

## Solr facets

- [ ] 3.1 `SolrSearchBackend::aggregate()` — count / count+groupBy / stats. **Open** — requires Solr in the dev container; no current Solr instance to test against.
- [ ] 3.2 Date-bucket via `facet.range.start/end/gap`. **Open**, gated on 3.1.
- [ ] 3.3 Filter translation (equality + in → `fq`; range → `fq=<col>:[a TO b]`). **Open**, gated on 3.1.
- [ ] 3.4 Unit tests stubbing Solr HTTP client. **Open**, gated on 3.1.

## Elasticsearch aggs

- [ ] 4.1 `ElasticsearchBackend::aggregate()`. **Open** — requires ES in the dev container.
- [ ] 4.2 Date-bucket via `date_histogram`. **Open**, gated on 4.1.
- [ ] 4.3 Filter translation (terms / range). **Open**, gated on 4.1.
- [ ] 4.4 Unit tests stubbing ES client. **Open**, gated on 4.1.

## Runner integration

- [ ] 5.1 `AggregationRunner::run()` consults `SchemaIndexService::getBackend($schema)`. **Open** — currently always tries Postgres native first then falls back to PHP. Becomes meaningful once 3.x or 4.x ships.
- [x] 5.2 Backend-attribution in the response: every `AggregationRunner::run()` result now carries `backend: "postgres"` (native path) or `backend: "php-fallback"` (PHP path) or `backend: "stub"` (test override). Cache hits add `cached: true`. Solr / ES values reserved for when those paths land.

## Cache

- [x] 6.1 `lib/Service/Aggregation/AggregationCache.php` shipped. Uses `ICacheFactory::createDistributed('openregister_aggregations')` with `TTL = 60`. Fail-closes when the cache backend is unavailable.
- [x] 6.2 Key shape `agg:{registerSlug}:{schemaSlug}:{name}:{sha1(resolvedFilters)}:{sha1(rbacScopeHash)}`. RBAC scope is `sha1($currentUid ?? 'anonymous')`. Filter is `ksort`-stable so order doesn't break cache hits. (Spec said sha256 — sha1 is functionally equivalent for cache keying and matches the rest of the cache-key conventions in the codebase.)
- [x] 6.3 Wired at the top of `AggregationRunner::run()` — `$cached = $this->cache->get(...)` returns the result with `cached: true` on hit. `AggregationController::aggregate()` now also surfaces an `X-OR-Cache: hit|miss` response header so reverse proxies + observability stacks can inspect the cache verdict without parsing the JSON body. Verified by 3 unit tests in `tests/Unit/Controller/AggregationControllerTest`: header `miss` on fresh computation, header `hit` on `cached: true`, and no header on the 404 path (the lookup never touched the cache layer).
- [x] 6.4 `AggregationCacheInvalidationListener` evicts on `ObjectCreated` / `ObjectUpdated` / `ObjectDeleted` / `ObjectTransitioned` events for the affected `(register, schema)`. Eviction uses `ICache::clear()` (the underlying cache backend has no prefix-delete) — coarse, but the 60s TTL bounds staleness even when a clear is missed.

## Documentation

- [ ] 7.1 `docs/annotations/x-openregister-aggregations.md` doesn't exist yet. **Open** — perf-comparison table also depends on having Solr/ES paths to compare against.
- [x] 7.2 `openspec/platform-capabilities.md` row updated: `implemented + Postgres-native + 60s cache`, with operator-filter inventory + backend attribution + cache scope; Solr/ES called out as deferred.

## Live verification

- [ ] 8.1 Decidesk Solr pilot. **Open** — gated on 3.1 (no Solr instance).
- [ ] 8.2 Postgres stress test at 100 000 ActionItems. **Open** — current dev DB has tens of fixtures, not 6 figures. The integration test in `AggregationRunnerIntegrationTest` asserts correctness at small scale; a separate stress harness is the right place for the perf claim.

## Tests + tooling shipped alongside

- [x] **Drop `final` from `AggregationRunner`** — required to make integration testing tractable; consistent with the same pattern applied to `AnnotationNotificationDispatcher` for the same reason.
- [x] **Drop `final` from `AggregationCache`** — required to make `AggregationCacheInvalidationListenerTest` doublable.
- [x] **`AggregationCacheTest` (12 tests)** — get/set hit/miss, RBAC user isolation, key stability under filter reorder, anonymous shared-scope, backend-down fail-closed, exception swallowing.
- [x] **`AggregationCacheInvalidationListenerTest` (5 tests)** — all 4 write events evict; unrelated event ignored.

# Tasks

- [x] task-1: search#REQ-001 — File search HTTP API (keyword/semantic/hybrid) on FileSearchController (retroactive annotation)
- [x] task-2: search#REQ-002 — Vector semantic + hybrid search via VectorSearchHandler (PHP cosine + Solr KNN + RRF) (retroactive annotation)
- [x] task-3: search#REQ-003 — Search trail logging via SearchTrailService::createSearchTrail with system-param filtering and optional self-clearing (retroactive annotation)
- [x] task-4: search#REQ-004 — Search trail analytics REST API on SearchTrailController + SearchTrailService aggregators (retroactive annotation)
- [x] task-5: search#REQ-005 — Placeholder DSL (`$now`/`$currentUser`/`$startOf*` ± offset) via PlaceholderResolver (retroactive annotation)

## future-pass:next

The following methods from `/tmp/or-scan/rspec-cluster-search.json` are deferred to a follow-up `reverse-spec search` pass:

### Out of scope here — covered by `search-index` capability (PR #1765)

These 84 methods belong to the backend index layer (Elasticsearch backend = parallel to the Solr backend already specced in `search-index`) and will be picked up when `search-index/spec.md` is extended to cover Elasticsearch:

- `lib/Service/Index/SearchBackendInterface.php` (26 methods — interface contract; same shape as Solr-side)
- `lib/Service/Index/Backends/ElasticsearchBackend.php` (27 methods)
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexer.php` (5 methods)
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchHttpClient.php` (13 methods)
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchIndexManager.php` (8 methods)
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchQueryExecutor.php` (4 methods)
- `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php` (1 method — already touched by `search-index` PR)

### Future-pass: SearchTrailService analytics helper internals

- `SearchTrailService::getSearchStatistics` (already touched by REQ-004 wrapper; needs its own dedicated REQ when the `parseUserAgent`/`calculateActivityInsights`/`calculateTrend`/`calculatePerformanceRating`/`aggregateByBrowser`/`calculatePages` helpers grow a stable contract)
- `SearchTrailService::parseUserAgent` — browser regex table
- `SearchTrailService::calculateActivityInsights` — peak/low/trend insights
- `SearchTrailService::calculateTrend` — linear-regression slope classifier
- `SearchTrailService::calculatePerformanceRating` — excellent/good/average/poor heuristic
- `SearchTrailService::calculatePages` — pagination math
- `SearchTrailService::aggregateByBrowser` — browser-distribution rollup

### Future-pass: SearchTrailController stub endpoints

- `SearchTrailController::destroy` — currently returns "deletion not implemented yet" (stub)
- `SearchTrailController::destroyMultiple` — currently returns "Multiple search trail deletion not implemented yet" (stub)

### Future-pass: Frontend search UI

- `src/store/modules/search.ts::useSearchStore` — Pinia search store (legacy + new search modes)
- `src/store/modules/searchTrail.js::useSearchTrailStore` — Pinia search-trail store
- `src/views/search/SearchIndex.vue` — search page (3 methods: `normalizeObjects`, `handleAddObject`, anonymous `if`)
- `src/views/logs/SearchTrailIndex.vue::loadSearchTrails`
- `src/sidebars/search/SearchSideBar.vue::onSearchInput`
- `src/sidebars/logs/SearchTrailSideBar.vue::loadSearchTrailData`

### Future-pass: Facet UI + integration tests

- `src/components/FacetComponent.vue::isActiveFacet`
- `src/modals/settings/FacetConfigModal.vue::loadFacets`, anonymous `if`
- `src/tests/facet-integration-test.js` (10 test methods)

### Future-pass: Facet cache clearing

- `lib/Service/Schemas/FacetCacheHandler.php::clearDistributedFacetCaches` — distributed/local IMemcache prefix clear (private helper invoked from `faceting-configuration` cache-invalidation paths)

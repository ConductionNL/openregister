# Retrofit — search (partial: 5 REQs)

Reverse-engineer the user-facing **search** capability from observed code as 5 new REQs. Code already exists — this change retroactively specifies it. No canonical spec exists for `search` yet, so this change **creates** `openspec/specs/search/spec.md` (status: implemented, retrofit: true).

This is a **partial** pass over the 148-method Bucket 2a batch (`/tmp/or-scan/rspec-cluster-search.json`). 84 methods belong to the parallel `search-index` capability (Elasticsearch backend mirror of the Solr layer + `SearchBackendInterface`, see [#1765](https://github.com/ConductionNL/openregister/pull/1765) — `retrofit-2026-05-24-search-index`) and are DROP'd from this scope; the remaining 64 methods cover search-trail logging/analytics, vector search, placeholder DSL, file search controllers, frontend search UI, and facet UI. This pass specs the 5 most-cohesive behavioral clusters (~38 methods); the rest are deferred under `future-pass:next` in `tasks.md`.

## Capability boundary

`search-index` (PR #1765) covers the **backend layer**: `lib/Service/IndexService.php`, the `lib/Service/Index/` subtree (Solr + Elasticsearch backends, `SearchBackendInterface`, `SchemaHandler`, `BulkIndexer`, `ConfigurationHandler`, `SetupHandler`, `DocumentBuilder`).

`search` (this change) covers the **user-facing search surface** that calls into the index layer (or runs in parallel to it):

- `lib/Controller/FileSearchController.php` — HTTP API for keyword / semantic / hybrid file search.
- `lib/Controller/SearchTrailController.php` — HTTP API for search-trail logs and analytics.
- `lib/Service/SearchTrailService.php` — search-event logging and analytics aggregation.
- `lib/Service/Vectorization/Handlers/VectorSearchHandler.php` — vector semantic + hybrid search.
- `lib/Service/Search/PlaceholderResolver.php` — `$now` / `$currentUser` DSL for query filters.
- `lib/Service/Schemas/FacetCacheHandler.php` (clearing only — full caching layer is `faceting-configuration`).

Cross-ref: `search-index` (backend), `faceting-configuration` (facet config), `audit-trail-immutable` (the analogue audit-side surface that SearchTrail patterns after).

## Affected code units (this pass)

- `lib/Controller/FileSearchController.php` — `keywordSearch`, `semanticSearch`, `hybridSearch` (REQ-001)
- `lib/Service/Vectorization/Handlers/VectorSearchHandler.php` — `semanticSearch`, `hybridSearch`, `searchVectorsInSolr`, `cosineSimilarity`, `reciprocalRankFusion`, `fetchVectors`, `getCollectionsToSearch`, `getSolrCollectionForEntityType`, `extractEntityId` (REQ-002)
- `lib/Service/SearchTrailService.php` — `createSearchTrail`, `clearExpiredSearchTrails`, `getSearchTrails`, `getSearchTrail`, `processConfig`, `__construct` (REQ-003)
- `lib/Controller/SearchTrailController.php` — `index`, `show`, `statistics`, `popularTerms`, `activity`, `registerSchemaStats`, `userAgentStats`, `cleanup`, `export`, `destroy`, `destroyMultiple`, `clearAll`, `extractRequestParameters`, `paginate`, `arrayToCsv` (REQ-004) — plus the underlying analytics aggregators on `SearchTrailService`: `getSearchStatistics`, `getPopularSearchTerms`, `getSearchActivity`, `getRegisterSchemaStatistics`, `getUserAgentStatistics`, `cleanupSearchTrails`
- `lib/Service/Search/PlaceholderResolver.php` — `__construct`, `resolve`, `resolveArray`, `resolveDate`, `defaultUnitFor` (REQ-005)

## Approach

- Observed behavior only — no aspirational requirements.
- 84 search-index methods explicitly DROP'd (see Notes in spec) — they will be covered by PR #1765's `search-index/spec.md` once it merges.
- 5 REQs picked for cohesion: each maps cleanly to one or two files and one observable feature.
- The remaining ~26 methods (frontend Vue stores/views, facet UI components, facet integration tests, FacetCacheHandler clearing internals, SearchTrailService analytics helpers like `parseUserAgent` / `calculateActivityInsights` / `calculateTrend` / `calculatePerformanceRating` / `aggregateByBrowser` / `calculatePages`, plus the `destroy` / `destroyMultiple` stub controllers) are deferred to `future-pass:next` in `tasks.md`.

## REQ map

| REQ | Methods (count) | Files |
|-----|-----------------|-------|
| REQ-001 | 3 | FileSearchController |
| REQ-002 | 9 | VectorSearchHandler |
| REQ-003 | 6 | SearchTrailService |
| REQ-004 | 14 | SearchTrailController (12) + SearchTrailService aggregators (6, of which 2 overlap with REQ-003: `getSearchTrails` + `getSearchTrail`) |
| REQ-005 | 5 | PlaceholderResolver |

**Total: 5 REQs / 37 unique methods annotated / 111 deferred** (84 search-index DROP + 27 future-pass).

Several methods carry two `@spec` tags (e.g. `getSearchTrails` covers both REQ-003 trail-mgmt and REQ-004 controller-pagination). The annotation count (39 `@spec` references) exceeds the unique-method count (37) for that reason.

Source: `/tmp/or-scan/rspec-cluster-search.json` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).

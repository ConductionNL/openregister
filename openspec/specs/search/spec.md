---
status: implemented
retrofit: true
---

# Search

## Purpose

OpenRegister exposes a **user-facing search surface** on top of the pluggable index backend (Solr/Elasticsearch, see `search-index`). This capability covers everything that sits **above** the `SearchBackendInterface`:

- The file-search HTTP API (`FileSearchController`) — three modes: keyword (SOLR full-text), semantic (vector similarity), hybrid (RRF-fused).
- The vector search engine (`VectorSearchHandler`) — dual-backend (PHP-side cosine over the `openregister_vectors` table, or Solr-side dense-vector KNN), with Reciprocal Rank Fusion for hybrid mode.
- The search-trail logging and analytics layer (`SearchTrailService` + `SearchTrailController`) — every search request is recorded for analytics; aggregators expose popular-terms, time-bucketed activity, register/schema breakdowns, and user-agent distributions.
- The placeholder DSL (`PlaceholderResolver`) — `$now`, `$currentUser`, `$startOfDay/Week/Month/Year` with `±N[dwmy]` offset arithmetic, used by aggregation and calculation annotation values so they can be authored as relative DSL rather than baked-in absolute dates.

This spec was reverse-engineered from observed code by the `retrofit-2026-05-24-search` ghost change after the Bucket 2a coverage scan flagged the layer as having zero spec coverage. Code already exists — requirements describe **what the code does**, not what we wish it did. See **Notes** for the stub/drift observations.

**Standards**: SOLR full-text, dense-vector KNN (`{!knn f=…}` syntax), cosine similarity, Reciprocal Rank Fusion (Cormack 2009), Pinia, Nextcloud `IUserSession`.

**Cross-references**:

- **search-index** — the backend layer this capability sits on top of. `FileSearchController::keywordSearch` calls `IndexService::getEndpointUrl()` directly; `VectorSearchHandler::searchVectorsInSolr` casts the backend to `SolrBackend` for KNN. The PR introducing `search-index` is [#1765](https://github.com/ConductionNL/openregister/pull/1765); the spec there owns Solr (and parallel Elasticsearch) primitives.
- **faceting-configuration** — facet computation lives there. This capability only references the cache-clear hook (`FacetCacheHandler::clearDistributedFacetCaches`, deferred).
- **audit-trail-immutable** — the audit log analogue. `SearchTrail` patterns after `AuditTrail` for retention and self-clearing.
- **aggregations-annotation**, **calculations-annotation** — the two consumers of the `PlaceholderResolver` DSL.

## Requirements

### Requirement: The system SHALL expose three file-search HTTP endpoints on FileSearchController

`FileSearchController` provides three POST endpoints under `/api/search/files/` for searching file contents that have been previously indexed into a configured Solr file collection:

1. `POST /api/search/files/keyword` (`keywordSearch`) — full-text SOLR query against `text_content:` with score-desc sort, optional `file_types` MIME filter, and `file_id` grouping in the response.
2. `POST /api/search/files/semantic` (`semanticSearch`) — vector-similarity search delegated to `VectorizationService::semanticSearch` with `filters: ['entityType' => 'file']`.
3. `POST /api/search/files/hybrid` (`hybridSearch`) — combined keyword + semantic via `VectorizationService::hybridSearch` with adjustable `keyword_weight` / `semantic_weight` knobs (both default 0.5).

All three are `@NoAdminRequired` + `@NoCSRFRequired`. Every endpoint MUST validate that `query` is non-empty and return `400` with `{success: false, message: 'Query parameter is required'}` otherwise. Every endpoint MUST wrap its execution in a try/catch that logs the exception via `LoggerInterface::error` (with file/line/error context) and returns a `500` response with `{success: false, message: '<mode> search failed: <reason>'}`. Successful responses MUST include a `search_type` field (`'keyword'`, `'semantic'`, or `'hybrid'`) and a `total` count.

#### Rationale

A single tri-modal HTTP surface lets the UI pick the right retrieval strategy per use case (exact-term lookup vs. fuzzy semantic vs. fused) without having to know about the underlying Solr collection layout or vector tables. The three modes share their input contract (`query`, `limit`) so the UI can fall back between them with minimal branching.

#### Scenario: Keyword search returns grouped-by-file SOLR results

- **GIVEN** a configured `solr.fileCollection` and previously indexed file content
- **WHEN** the client POSTs to `/api/search/files/keyword` with `{ "query": "contract", "limit": 10, "offset": 0 }`
- **THEN** the controller MUST issue a Solr GET against `<endpoint>/<fileCollection>/select` with params `q=text_content:(contract)`, `rows=10`, `start=0`, `fl=file_id,file_name,file_path,mime_type,chunk_index,chunk_text,score`, `sort=score desc`
- **AND** the response MUST be HTTP 200 with `{success: true, query: "contract", total: <numFound>, results: [...], search_type: "keyword"}`
- **AND** results MUST be grouped by `file_id` with per-chunk entries collapsed into a `chunks: []` array per file

#### Scenario: Keyword search short-circuits when file collection is not configured

- **GIVEN** `settings.solr.fileCollection` is `null` or empty
- **WHEN** the client POSTs to `/api/search/files/keyword` with a non-empty `query`
- **THEN** the controller MUST return HTTP 422 with `{success: false, message: 'File collection not configured'}` without making any Solr call

#### Scenario: Empty query is rejected by every mode

- **GIVEN** any of the three endpoints (`keyword`, `semantic`, `hybrid`)
- **WHEN** the client POSTs with `query: ""` (or omitted)
- **THEN** the controller MUST return HTTP 400 with `{success: false, message: 'Query parameter is required'}`

#### Scenario: Hybrid search forwards weights to VectorizationService

- **GIVEN** a request `POST /api/search/files/hybrid` with `{ "query": "x", "keyword_weight": 0.7, "semantic_weight": 0.3 }`
- **WHEN** the controller invokes `VectorizationService::hybridSearch`
- **THEN** the call MUST pass `weights: ['solr' => 0.7, 'vector' => 0.3]` and `solrFilters: ['entityType' => 'file']`
- **AND** the response MUST include a `weights` echo `{keyword: 0.7, semantic: 0.3}` so the client can verify the applied split

---

### Requirement: Vector search SHALL support PHP cosine-similarity and Solr KNN backends, with Reciprocal Rank Fusion for hybrid mode

`VectorSearchHandler::semanticSearch($queryEmbedding, $limit, $filters, $backend)` performs vector similarity search through one of two execution paths selected by the `$backend` argument:

- `$backend === 'solr'` — delegates to the private `searchVectorsInSolr()` which casts the IndexService's backend to `SolrBackend`, constructs a `{!knn f=<vectorField> topK=<limit>}[v1, v2, …]` query, and executes a GET against each configured collection (`object` and/or `file`, resolved by `getCollectionsToSearch()`).
- any other value (default `'php'`) — fetches up to `max_vectors` (default 500) rows from `openregister_vectors` via `fetchVectors()`, unserializes the stored `embedding`, computes `cosineSimilarity()` per row, sorts descending, and returns the top-`$limit` slice.

`cosineSimilarity()` MUST throw `InvalidArgumentException` if the two vectors differ in length. It MUST return `0.0` when either magnitude is zero (avoiding division by zero).

`hybridSearch($queryEmbedding, $solrResults, $limit, $weights, $backend)` MUST normalize `$weights['solr']` + `$weights['vector']` to sum to 1 (when their original sum is > 0), invoke `semanticSearch()` for the vector half (with `limit * 2` to widen the candidate pool), then fuse via `reciprocalRankFusion()` using RRF score `weight / (60 + rank + 1)`. The response MUST include `source_breakdown: {vector_only, solr_only, both}` and `weights: {solr, vector}` (post-normalization).

All search and fusion methods MUST log INFO/DEBUG telemetry on entry and either INFO on success (with `search_time_ms`, `results_count`, `top_similarity`) or ERROR on failure (re-raised as a new `Exception` with prefix `"Semantic search failed: "` / `"Hybrid search failed: "` / `"Solr vector search failed: "`).

#### Rationale

Two backends let small deployments use PHP cosine (no Solr KNN config needed) while large deployments scale to Solr's dense-vector index. RRF is the industry-standard fusion algorithm for combining rank lists with different score distributions (cosine vs BM25) without having to calibrate them.

#### Scenario: PHP backend ranks by cosine similarity

- **GIVEN** `openregister_vectors` contains three rows with embeddings `e1`, `e2`, `e3`
- **WHEN** `VectorSearchHandler::semanticSearch(queryEmbedding: $q, limit: 2, backend: 'php')` is called
- **THEN** the handler MUST call `fetchVectors()` (which applies any `entity_type` / `entity_id` filters and orders by `created_at DESC`, capped at `max_vectors`)
- **AND** for each row, compute `cosineSimilarity($q, unserialize($row['embedding']))`
- **AND** return the top 2 rows sorted by `similarity` descending, each augmented with `vector_id`, `entity_type`, `entity_id`, `chunk_index`, `total_chunks`, `chunk_text`, `metadata` (json_decoded), `model`, `dimensions`

#### Scenario: Solr backend builds a KNN query per collection

- **GIVEN** `settings.llm.vectorConfig.solrField` is `'embedding_v'` and the IndexService's backend is a `SolrBackend`
- **AND** `getCollectionsToSearch(filters: [])` returns `[{type: object, collection: 'or_objects'}, {type: file, collection: 'or_files'}]`
- **WHEN** `searchVectorsInSolr(queryEmbedding: [0.1, 0.2, 0.3], limit: 5, filters: [])` is called
- **THEN** the handler MUST construct `knnQuery = "{!knn f=embedding_v topK=5}[0.1, 0.2, 0.3]"`
- **AND** issue a GET against `<solrBaseUrl>/or_objects/select` and `<solrBaseUrl>/or_files/select` with `{q: knnQuery, rows: 5, fl: '*,score', wt: 'json'}`
- **AND** merge both result sets, sort by `score` descending, slice to `limit`
- **AND** raise `Exception('No Solr collections configured for vector search')` if `getCollectionsToSearch` returns `[]`
- **AND** raise `Exception('Vector search requires SolrBackend')` if the backend is not a `SolrBackend` instance

#### Scenario: Hybrid search fuses with RRF

- **GIVEN** vector search returns ranked results `[A, B, C]` and Solr keyword search returns `[B, D, A]`
- **AND** equal weights `solr: 0.5, vector: 0.5`
- **WHEN** `reciprocalRankFusion(vectorResults: [A, B, C], solrResults: [B, D, A], vectorWeight: 0.5, solrWeight: 0.5)` is called
- **THEN** each result's `combined_score` MUST accumulate `weight / (60 + rank_index + 1)` from each list it appears in
- **AND** results MUST be returned sorted by `combined_score` descending
- **AND** each result MUST carry `in_vector`, `in_solr`, `vector_rank`, `solr_rank`, `vector_similarity`, `solr_score`, and the merged `metadata`

#### Scenario: cosineSimilarity rejects mismatched dimensions

- **GIVEN** two vectors of differing length
- **WHEN** `cosineSimilarity($v1, $v2)` is called
- **THEN** the method MUST throw `InvalidArgumentException('Vectors must have same dimensions')`

#### Scenario: cosineSimilarity returns 0 on a zero-magnitude vector

- **GIVEN** at least one of `$v1` / `$v2` has magnitude `0` (all components are zero)
- **WHEN** `cosineSimilarity($v1, $v2)` is called
- **THEN** the method MUST return `0.0` (no division-by-zero, no exception)

---

### Requirement: The system SHALL log every search request as a search trail with system-param filtering and optional self-clearing

`SearchTrailService::createSearchTrail($query, $resultCount, $totalResults, $responseTime, $executionType)` SHALL delegate to `SearchTrailMapper::createSearchTrail()` to persist a `SearchTrail` entity. The mapper is responsible for filtering out any system parameters (keys starting with `_`) before persistence so they never enter analytics. The service-level call MUST wrap mapper exceptions in `Exception("Search trail creation failed: " . <original>)` to give callers a consistent contextual prefix.

`SearchTrailService` MUST accept two optional constructor parameters that govern the retention policy:

- `?int $retentionDays = null` — keeps the field default of `365` if not supplied.
- `?bool $selfClearing = null` — keeps the field default of `false` if not supplied.

When `selfClearingEnabled === true`, `createSearchTrail()` MUST invoke `clearExpiredSearchTrails()` after the create succeeds. The clearing call MUST delegate to `SearchTrailMapper::clearLogs()` (which only deletes rows past their `expires` column, ignoring `$before` parameters — this is the same shape as `AuditTrailService`). On success it MUST return `['success' => true, 'deleted' => 0|1, 'cleanup_date' => '<Y-m-d H:i:s>', 'message' => '<…>']`; on failure it MUST catch the exception and return `['success' => false, 'deleted' => 0, 'error' => <message>, 'message' => 'Self-clearing operation failed']` (i.e. failures MUST NOT propagate out of `createSearchTrail`).

`SearchTrailService::getSearchTrails($config)` MUST funnel its raw config through `processConfig()`, which:

1. Defaults: `limit=20`, `offset=null`, `page=null`, `filters=[]`, `sort=['created'=>'DESC']`, `search=null`, `from=null`, `to=null`.
2. Accepts both underscore-prefixed (`_limit`, `_offset`, `_page`, `_search`, `_sort`, `_order`) and bare (`limit`, `offset`, `page`, `search`, `sort`, `order`) parameter names; the bare form wins when both are present.
3. Derives `offset` from `page` (or vice-versa) when only one is supplied; defaults both to `1` / `0` otherwise.
4. Parses `from` / `to` via `new DateTime(...)`; invalid dates are silently dropped (caught and ignored).
5. Strips a fixed list of system keys (`limit`, `_limit`, `offset`, `_offset`, `page`, `_page`, `search`, `_search`, `sort`, `_sort`, `order`, `_order`, `from`, `to`, `_route`, `id`) **and** any other key starting with `_` from the `filters` map.

The returned shape MUST be `{results, total, page, pages, limit, offset}` with `pages = ceil(total / limit)` (minimum 1).

#### Rationale

Filtering `_`-prefixed keys at both the trail-write side (mapper) and the trail-read side (`processConfig`) keeps query plumbing parameters out of the analytics surface (which would otherwise show `_sort: created` and `_limit: 20` as "popular search terms"). Self-clearing on write is a deployment knob — disabled by default so the cron job owns retention, but available so embedded / single-tenant deployments can avoid the cron requirement entirely.

#### Scenario: Search trail is written with mapper-side filtering

- **GIVEN** a request that ran with query params `{search: "contract", _limit: 20, _page: 1, type: "doc"}`
- **WHEN** the controller invokes `createSearchTrail(query: $query, resultCount: 17, totalResults: 42, responseTime: 35.2, executionType: 'sync')`
- **THEN** the service MUST call `SearchTrailMapper::createSearchTrail()` with named args `searchQuery`, `resultCount`, `totalResults`, `responseTime`, `executionType` (no transformation at the service layer)
- **AND** the mapper MUST be responsible for stripping `_limit` and `_page` before persistence (system-param filtering)
- **AND** the service MUST return the created `SearchTrail` entity

#### Scenario: Mapper failure is rewrapped

- **GIVEN** `SearchTrailMapper::createSearchTrail()` throws `\Exception('DB error: connection lost')`
- **WHEN** `SearchTrailService::createSearchTrail(...)` runs
- **THEN** the service MUST catch the exception and throw a new `\Exception('Search trail creation failed: DB error: connection lost', 0, $original)`

#### Scenario: Self-clearing is invoked only when enabled

- **GIVEN** `SearchTrailService` was constructed with `selfClearing: false`
- **WHEN** `createSearchTrail(...)` succeeds
- **THEN** `clearExpiredSearchTrails()` MUST NOT be invoked

#### Scenario: processConfig honours underscore-prefixed and bare parameter pairs

- **GIVEN** `config = { _limit: 10, limit: 50, _page: 2, _sort: 'created', _order: 'ASC', tag: 'a' }`
- **WHEN** `processConfig($config)` runs
- **THEN** the result MUST have `limit = 50` (bare wins over underscore), `page = 2`, `offset = 50` (derived as `(page-1)*limit`), `sort = ['created' => 'ASC']`
- **AND** `filters` MUST be `{tag: 'a'}` (system + `_`-prefixed keys stripped)

---

### Requirement: SearchTrailController SHALL expose the search-trail REST API and analytics aggregators

`SearchTrailController` exposes the following routes (all `@NoAdminRequired` + `@NoCSRFRequired`), backed by `SearchTrailService`:

| Method | URL | Handler | Behaviour |
|--------|-----|---------|-----------|
| `GET`  | `/api/search-trails` | `index` | Paginated list of search trails; delegates to `getSearchTrails()`; re-paginates via the controller's `paginate()` helper for shape consistency with `ObjectsController` |
| `GET`  | `/api/search-trails/{id}` | `show` | Single trail by ID; returns 404 on `DoesNotExistException` |
| `GET`  | `/api/search-trails/statistics` | `statistics` | Aggregated stats over a `from`/`to` window |
| `GET`  | `/api/search-trails/popular-terms` | `popularTerms` | Top-N search terms with percentage + effectiveness rating |
| `GET`  | `/api/search-trails/activity` | `activity` | Time-bucketed activity (`interval=hour\|day\|week\|month`) + insights |
| `GET`  | `/api/search-trails/register-schema-stats` | `registerSchemaStats` | Per-register/per-schema usage breakdown |
| `GET`  | `/api/search-trails/user-agent-stats` | `userAgentStats` | User-agent distribution + browser rollup |
| `POST` | `/api/search-trails/cleanup` | `cleanup` | Manual retention cleanup (delegates to `SearchTrailMapper::clearLogs()`) |
| `GET`  | `/api/search-trails/export` | `export` | CSV or JSON dump; format chosen by `?format=` param (default `csv`); optional `includeMetadata=true` flag |
| `DELETE` | `/api/search-trails/{id}` | `destroy` | **Stub** — returns 200 with `"Search trail deletion not implemented yet"` (see Notes) |
| `DELETE` | `/api/search-trails` | `destroyMultiple` | **Stub** — returns 200 with `"Multiple search trail deletion not implemented yet"` (see Notes) |
| `DELETE` | `/api/search-trails/clear-all` | `clearAll` | Delegates to `SearchTrailMapper::clearAllLogs()` via the container |

Every analytics endpoint MUST parse pagination + filter + date-range parameters via `extractRequestParameters()` (private helper), MUST wrap its body in a try/catch returning `500` with `{error: 'Failed to <verb>: <reason>'}`, and MUST re-paginate any list output via the controller's `paginate()` helper which produces `{results, total, page, pages, limit, offset, next?, prev?}` (next/prev are query-string-rewritten URLs using `_page=` regardless of which `page` flavour the caller used).

Analytics aggregators on `SearchTrailService` (`getSearchStatistics`, `getPopularSearchTerms`, `getSearchActivity`, `getRegisterSchemaStatistics`, `getUserAgentStatistics`, `cleanupSearchTrails`) MUST each accept `?DateTime $from = null, ?DateTime $to = null` (and optionally `$limit`, `$interval`) and MUST decorate the raw mapper result with calculated metrics:

- `getSearchStatistics` adds `success_rate = round(non_empty_searches / total_searches * 100, 2)`, `unique_search_terms`, `unique_users`, `avg_searches_per_session`, `avg_object_views_per_session`, a placeholder `query_complexity = {simple: 60%, medium: 30%, complex: 10%}` of `total_searches`, the `period` (with `days` count), and `daily_averages` when the period has known length.
- `getPopularSearchTerms` adds `percentage` per term + `effectiveness: 'low'|'high'` (high if `avg_results > 0`).
- `getSearchActivity` calls `calculateActivityInsights` to add `peak_period`/`low_period`/`trend` (linear-regression slope: `> 0.1` increasing, `< -0.1` decreasing, else stable) + `average_searches_per_period`.
- `getRegisterSchemaStatistics` sorts by `percentage` descending and tags each row with `performance_rating: excellent|good|average|poor`.
- `getUserAgentStatistics` parses each user-agent string via `parseUserAgent` and aggregates into `browser_distribution` via `aggregateByBrowser`.
- `cleanupSearchTrails($_before = null)` ignores the `$_before` param (kept for API compatibility) and delegates to `clearLogs()` with the same `{success, deleted, cleanup_date, message}` shape as `clearExpiredSearchTrails`.

#### Rationale

The search-trail surface is intentionally the analytics dashboard for "what is the user community searching for". Decorating the raw counts with percentages, success rates, browser breakdowns, and trend classifiers happens at the service layer so every consumer (dashboard widget, export CSV, MCP tool) sees the same normalized shape. The controller's `paginate()` helper enforces a single pagination contract across all list-shaped endpoints (`index`, `popularTerms`, `userAgentStats`) so the frontend can use one pagination component everywhere.

#### Scenario: index returns paginated trails with controller-shape

- **GIVEN** the request `GET /api/search-trails?_limit=10&_page=2&search=contract`
- **WHEN** `SearchTrailController::index()` runs
- **THEN** raw request params (minus `_route` + `id`) MUST be forwarded to `SearchTrailService::getSearchTrails()`
- **AND** the response MUST be HTTP 200 with `{results, total, page, pages, limit, offset, next?, prev?}` (the `next` / `prev` URLs MUST rewrite the page parameter as `_page=...`)
- **AND** failures MUST yield HTTP 500 with `{error: 'Failed to retrieve search trails: <reason>'}`

#### Scenario: popularTerms decorates terms with percentage + effectiveness

- **GIVEN** `SearchTrailMapper::getPopularSearchTerms` returns `[{term: 'contract', count: 30, avg_results: 5}, {term: 'invoice', count: 10, avg_results: 0}]`
- **WHEN** `getPopularSearchTerms(limit: 10)` is called
- **THEN** the service MUST add `percentage` (`75.0` and `25.0` respectively) and `effectiveness` (`'high'` and `'low'` respectively)
- **AND** the wrapped controller response MUST also expose `total_searches: 40` and the `period` echo

#### Scenario: statistics derives success_rate and daily averages

- **GIVEN** `from = 2026-01-01`, `to = 2026-01-10`, `total_searches = 100`, `non_empty_searches = 80`, `total_results = 500`
- **WHEN** `getSearchStatistics(from: $from, to: $to)` runs
- **THEN** the result MUST include `success_rate = 80.0`, `period.days = 10`, `daily_averages = {searches_per_day: 10.0, results_per_day: 50.0}`

#### Scenario: export streams CSV by default

- **GIVEN** `GET /api/search-trails/export` with no `format` query param
- **WHEN** the controller assembles `searchTrailService->getSearchTrails(...)` results
- **THEN** the response payload MUST be `{success: true, data: {content: <csv-string>, filename: 'search-trails-<Y-m-d-H-i-s>.csv', contentType: 'text/csv', size: <strlen>}}`
- **AND** when `format=json`, the payload MUST swap to JSON-encoded content with `.json` extension and `application/json` contentType

#### Scenario: paginate computes minimum-1-page when total is less than results

- **GIVEN** `paginate(results: $rows, total: 0, limit: 20, offset: 0, page: 1)` with `count($rows) = 5`
- **WHEN** the helper runs
- **THEN** it MUST coerce `total = 5` and `pages = 1` so that the response always honours `count(results) <= total`
- **AND** the returned shape MUST be `{results, total: 5, page: 1, pages: 1, limit: 20, offset: 0}` (no `next` / `prev` because `page === pages`)

---

### Requirement: PlaceholderResolver SHALL resolve `$now` / `$startOf*` / `$currentUser` DSL with optional `±N[dwmy]` offset arithmetic

`PlaceholderResolver::resolve($value)` MUST recognise the following placeholder strings:

- `$currentUser` — resolves to `IUserSession::getUser()?->getUID() ?? ''` (empty string when unauthenticated).
- `$now` — resolves to `new DateTimeImmutable('now', <server timezone>)`.
- `$startOfDay` — `$now->modify('today')`.
- `$startOfWeek` — `$now->modify('monday this week')`.
- `$startOfMonth` — `$now->modify('first day of this month')->modify('today')`.
- `$startOfYear` — `$now->modify('first day of January <YYYY>')->modify('today')`.

Each date placeholder MAY be suffixed with an offset matching `/^(\$[a-zA-Z]+)([+-]\d+)([dwmy]?)$/`. The unit suffix (`d`, `w`, `m`, `y`) is optional; when omitted, the resolver MUST default to the placeholder's natural unit:

- `$startOfWeek` defaults to `w` (weeks).
- `$startOfMonth` defaults to `m` (months).
- `$startOfYear` defaults to `y` (years).
- everything else defaults to `d` (days).

For example, `$startOfMonth-1` means "first day of last month at 00:00"; `$now-7d` means "seven days ago"; `$startOfYear+2` means "first day of two years from now".

Non-string values MUST be returned untouched. Strings that do not start with `$` MUST be returned untouched. Strings that start with `$` but match no known placeholder MUST be returned as-is (caller surfaces the failure).

`resolveArray($values)` MUST recurse into nested arrays, applying `resolve()` to every leaf and preserving the array structure.

#### Rationale

The aggregation/calculation annotation DSL needs a way to express relative time bounds ("last 30 days") that survive being persisted in the schema. Baking absolute dates would stale immediately; embedding PHP would be unsafe. A small string DSL with `$now-7d` as the canonical form gives readable, portable, evaluable expressions; the natural-unit defaulting (`$startOfMonth-1` = one month) makes the common cases shorter.

#### Scenario: Bare `$now` resolves to current time

- **WHEN** `resolve('$now')` is called
- **THEN** the result MUST be a `DateTimeImmutable` equal to `new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()))`

#### Scenario: Offset arithmetic with explicit unit

- **WHEN** `resolve('$now-7d')` is called
- **THEN** the result MUST be `(new DateTimeImmutable('now'))->modify('-7 days')`

#### Scenario: Offset arithmetic with implicit unit

- **WHEN** `resolve('$startOfMonth-1')` is called
- **THEN** the result MUST be `$now->modify('first day of this month')->modify('today')->modify('-1 months')` (natural unit `m`)

#### Scenario: $currentUser short-circuits to the session UID

- **GIVEN** `IUserSession::getUser()->getUID()` returns `'alice'`
- **WHEN** `resolve('$currentUser')` is called
- **THEN** the result MUST be the string `'alice'`

#### Scenario: Unknown placeholder is returned verbatim

- **WHEN** `resolve('$tomorrow')` is called (not in the known set)
- **THEN** the result MUST be the string `'$tomorrow'` (no exception)

#### Scenario: Non-string and non-`$`-prefixed values pass through

- **WHEN** `resolve(42)` or `resolve('hello')` is called
- **THEN** the result MUST be the original value `42` / `'hello'` (no DateTime conversion attempted)

#### Scenario: resolveArray recurses into nested structures

- **GIVEN** `values = { from: '$now-7d', meta: { user: '$currentUser', label: 'static' }, count: 5 }`
- **WHEN** `resolveArray(values)` is called
- **THEN** the returned array MUST preserve every key and recurse into `meta`
- **AND** `from` MUST become a `DateTimeImmutable` seven days in the past; `meta.user` MUST become the session UID string; `meta.label` MUST stay `'static'`; `count` MUST stay `5`

---

## Notes

Observed-but-suspicious behaviour discovered during the retrofit. Not fixed in this change; flagged for follow-up.

### Stub controllers in SearchTrailController

- `SearchTrailController::destroy($id)` — validates the trail exists, then returns 200 with `{success: true, message: 'Search trail deletion not implemented yet'}` regardless. Single-row deletion is not wired up.
- `SearchTrailController::destroyMultiple()` — returns 200 with `'Multiple search trail deletion not implemented yet'` without doing anything. There is a `// TODO: Implement multiple search trail deletion` comment in-body.
- Both stubs ship behind `@NoAdminRequired` routes (`DELETE /api/search-trails/{id}` and `DELETE /api/search-trails`), so callers receive a misleading 200 with no actual delete. Consider either implementing or returning 501. Tracked for `future-pass:next`.

### Service-layer container leak in `clearAll`

`SearchTrailController::clearAll()` reaches into the container directly (`\OC::$server->get('OCA\OpenRegister\Db\SearchTrailMapper')`) rather than receiving the mapper via constructor injection. Every other method on the controller uses `$this->searchTrailService`. The container leak bypasses the service layer and the test seam.

### `getSearchStatistics` ships placeholder query-complexity rollup

The `query_complexity` block (`{simple: 60%, medium: 30%, complex: 10%}` of total) is hard-coded as a 60/30/10 split with no actual analysis of the persisted query strings. The comment in the method body labels it as "placeholder implementation". The percentages are also rounded with `round(...)` so they may not sum to total. Surfaced as-is; a real implementation would parse `searchTrail.searchParameters` and count field/operator combinations.

### `cleanupSearchTrails($_before)` ignores its `$before` argument

The `$before` parameter is renamed to `$_before` (Psalm-unused convention) and only retained for API compatibility — the underlying `clearLogs()` only deletes rows past their own `expires` column, ignoring any externally supplied cutoff. Same shape as `AuditTrailService::cleanup`. Documented as intentional in the source comment; surfacing here so the contract is visible from the spec.

### Hybrid mode oversearches the vector half by `2x`

`hybridSearch()` requests `limit * 2` vector results before passing to RRF fusion, then slices `limit` from the fused list. Solr-side input (`$solrResults`) is consumed as-is — no oversearch — so the asymmetric widening can bias the merge toward vector results when the vector backend is fast (PHP) but slow per-row. No bug per se; surfaced for future tuning.

### Stored embeddings are PHP-serialized, not JSON

`fetchVectors()` returns rows whose `embedding` column is `unserialize()`'d (PHP serialize/unserialize format). This makes the vector table un-readable from non-PHP consumers and constrains schema portability. Surfaced for the future "make the embedding column JSON" refactor (out of retrofit scope).

### `search-index` capability not yet on `development`

This retrofit references the `search-index` capability spec (PR [#1765](https://github.com/ConductionNL/openregister/pull/1765)) for boundary, but at the time of writing that spec is on a feature branch and not yet merged to `origin/development`. After #1765 merges, `search-index/spec.md` will be the canonical home for everything under `lib/Service/IndexService.php` + `lib/Service/Index/`; the 84 search-index methods in the source batch JSON (Elasticsearch backend + `SearchBackendInterface`) will need to be picked up by extending that spec, not this one.

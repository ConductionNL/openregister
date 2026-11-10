## MODIFIED Requirements

### Requirement: Full-text search across object properties
The system MUST support free-text search across all string-typed properties of register objects. The `_search` query parameter MUST trigger a case-insensitive search that matches against every string column in the schema's dynamic table, plus the metadata fields `_name`, `_description`, and `_summary`. Search MUST be performed using SQL `ILIKE` patterns in the PostgreSQL/Magic-Tables backend, which is the sole search backend.

#### Scenario: Full-text search across all string properties
- **GIVEN** schema `meldingen` with objects containing `title` (string), `description` (string), `location` (string), and `priority` (integer) properties
- **AND** object `melding-1` has title `Geluidsoverlast` and description `Buren maken veel lawaai na middernacht`
- **WHEN** the user searches with `?_search=lawaai`
- **THEN** `melding-1` MUST appear in the results because `lawaai` matches the `description` column via `ILIKE '%lawaai%'`
- **AND** the `priority` integer column MUST NOT be included in the search conditions (only `type: string` columns are searched)

#### Scenario: Search matches metadata fields
- **GIVEN** an object with `_name` set to `Parkeeroverlast Kerkstraat` and `_summary` set to `Melding over foutparkeren`
- **WHEN** the user searches with `?_search=Kerkstraat`
- **THEN** the object MUST appear in results because `_name` is always included in full-text search via `_name::text ILIKE '%kerkstraat%'`
- **AND** searching for `foutparkeren` MUST also match via `_summary`

#### Scenario: Case-insensitive search
- **GIVEN** an object with title `Geluidsoverlast in Het Centrum`
- **WHEN** the user searches with `?_search=het centrum`
- **THEN** the object MUST appear in results because `MagicSearchHandler.applyFullTextSearch()` applies `LOWER()` to both column values and search terms before comparison

#### Scenario: Date-formatted string properties excluded from text search
- **GIVEN** a schema with property `aanmaakdatum` of `type: string, format: date`
- **WHEN** the user performs a full-text search with `?_search=2026`
- **THEN** the `aanmaakdatum` column MUST NOT be included in the ILIKE search conditions because `MagicSearchHandler` skips properties with format `date`, `date-time`, or `time`

#### Scenario: Search across multiple schemas (UNION query)
- **GIVEN** register `zaken` with schemas `meldingen` (table `or_r1_s1`) and `vergunningen` (table `or_r1_s2`)
- **WHEN** the user searches with `?_search=centrum&_schemas[]=1&_schemas[]=2` at the register level
- **THEN** `MagicMapper.searchObjectsPaginatedMultiSchema()` MUST build a UNION ALL query across both dynamic tables
- **AND** each result MUST include `_register` and `_schema` metadata indicating its source
- **AND** results MUST be combined into a single paginated response with unified `total` count

### Requirement: Faceted search with configurable facets
The system MUST compute facet counts (value distributions) for properties marked as `facetable` in the schema definition. Facets MUST be calculated on the full filtered dataset independent of pagination. The faceting system MUST support aggregated facets (merged across schemas), non-aggregated facets (schema-scoped), configurable titles/descriptions/ordering, and date histogram facets. See `faceting-configuration` spec for full facet configuration details.

#### Scenario: Display facet counts for search results
- **GIVEN** 100 `meldingen` objects with property `status` marked `facetable: true`
- **AND** values distributed as: `nieuw` (30), `in_behandeling` (45), `afgehandeld` (25)
- **WHEN** a search query returns these results
- **THEN** the `facets` section of the response MUST include `status` with buckets showing each value and its count
- **AND** facet computation MUST use `MagicFacetHandler` (SQL) on PostgreSQL or `MariaDbFacetHandler` on MariaDB

#### Scenario: Facets recalculate with applied filters
- **GIVEN** the user has applied filter `?wijk=centrum` reducing results to 20 objects
- **WHEN** facets are recalculated
- **THEN** `status` facet counts MUST reflect only the 20 filtered objects (e.g., `nieuw: 5, in_behandeling: 10, afgehandeld: 5`)
- **AND** `FacetHandler` MUST use its smart fallback: if filtered facets are empty, it falls back to collection-wide facets

#### Scenario: Combine multiple facet filters
- **GIVEN** the user applies `?status=in_behandeling&wijk=centrum`
- **WHEN** both filters are active
- **THEN** results MUST match BOTH criteria (AND logic between different properties)
- **AND** facet counts for all other faceted properties MUST reflect the combined filter state

#### Scenario: Facet caching for performance
- **GIVEN** facets were recently computed for the same query
- **WHEN** the same query is repeated within the cache TTL
- **THEN** `FacetCacheHandler` MUST return cached facet results from APCu (1 hour TTL)
- **AND** cache keys MUST incorporate register, schema, and active filters to prevent stale data

### Requirement: Response MUST carry backend attribution
Every aggregation response MUST include a `backend` field with one of `"postgres"` or `"php-fallback"`. Apps and operators use this to debug slow queries.

#### Scenario: Backend attribution surfaced on every response
- **GIVEN** any aggregation request
- **WHEN** the response is rendered
- **THEN** the JSON body MUST include a top-level `backend` field
- **AND** the value MUST be one of `"postgres"` or `"php-fallback"`
- **AND** a fallback path (Postgres rejecting the input shape) MUST attribute `"php-fallback"`, the backend that produced the result

## ADDED Requirements

### Requirement: Search results MUST paginate by offset or page
The system MUST support pagination through `_limit`, `_offset`, and `_page` parameters. Page-based pagination MUST be 1-indexed. The response MUST include `total` (total matching count), `page` (current page), `pages` (total pages), `limit`, and `offset` fields. Navigation URLs (`next`, `prev`) MUST be generated when multiple pages exist.

#### Scenario: Page-based pagination
- **GIVEN** 150 matching objects and `_limit=30`
- **WHEN** the user requests `?_page=2&_limit=30`
- **THEN** `MagicSearchHandler.searchObjects()` MUST convert page to offset: `offset = (2 - 1) * 30 = 30`
- **AND** the response MUST include `{ total: 150, page: 2, pages: 5, limit: 30, offset: 30 }`

#### Scenario: Offset-based pagination
- **GIVEN** 150 matching objects
- **WHEN** the user requests `?_offset=60&_limit=30`
- **THEN** the system MUST return objects 61-90
- **AND** `SearchQueryHandler.addPaginationUrls()` MUST add `next` and `prev` URL links

#### Scenario: Pagination URLs generated only when needed
- **GIVEN** a query returning 20 results with `_limit=30`
- **WHEN** `addPaginationUrls()` is called with `page=1, pages=1`
- **THEN** no `next` or `prev` URLs MUST be added (single page of results)

#### Scenario: First page has no prev URL
- **GIVEN** 100 results with `_limit=30`, currently on page 1
- **WHEN** pagination URLs are generated
- **THEN** only `next` MUST be present (pointing to page 2), not `prev`

### Requirement: Database-backed search architecture
The search system MUST operate exclusively on the database (Magic-Tables) backend using SQL `ILIKE`/`pg_trgm` on PostgreSQL and the JSON path on MariaDB. There is no external search engine, no `SearchBackendInterface` and no `IndexService` facade. `MagicSearchHandler` and `MagicMapper` MUST own all search execution, indexing-free, querying the live dynamic tables. `GET /api/settings/search-backend` MUST stay available as a read-only report and MUST answer `{ active: "database", available: ["database"] }`; there is no endpoint to select another backend.

#### Scenario: Database-backed search is the only path
- **GIVEN** an OpenRegister instance (no external search engine can be configured)
- **WHEN** the user performs a full-text search
- **THEN** `MagicSearchHandler` MUST execute SQL queries with ILIKE patterns against the dynamic tables
- **AND** results MUST be returned within acceptable response times for datasets under 100,000 objects
- **AND** the response envelope MUST be `{ results, total, page, pages }`

#### Scenario: No object indexing step on save
- **GIVEN** an object is created or updated via `ObjectService`
- **WHEN** the save completes
- **THEN** the object MUST be persisted to its magic table only
- **AND** no external search index call (`indexObject`/`bulkIndexObjects`) MUST occur, because none exists

#### Scenario: Search backend report names the database
- **GIVEN** an admin
- **WHEN** they request `GET /api/settings/search-backend`
- **THEN** the response MUST be `{ active: "database", available: ["database"] }`
- **AND** `PUT` or `PATCH` on the same path MUST NOT be routed (HTTP 404 or 405)

### Requirement: Search result highlighting MUST be computed client-side
Matching terms in search results MUST be visually highlightable. The database backend does not compute highlights, so the API response MUST NOT carry server-side `_highlights` fragments; highlighting MUST be computed client-side from the `_search` term.

#### Scenario: Database backend highlighting fallback
- **GIVEN** a search with a `_search` term
- **WHEN** search results are returned
- **THEN** the `_highlights` field MUST be absent or empty
- **AND** the frontend MUST perform client-side highlighting using the `_search` term from the query

#### Scenario: Multiple field highlighting
- **GIVEN** a search term matches in both `title` and `description` of an object
- **WHEN** the frontend renders the result
- **THEN** the term MUST be highlighted in each displayed field where it matches

### Requirement: Dutch language search MUST match case-insensitively on the database backend
The system MUST support Dutch language search capabilities. Stemming and stop-word analysis are not provided (they were Solr-only). The database backend MUST support case-insensitive matching for Dutch diacritics via PostgreSQL's `ILIKE` which handles UTF-8 natively.

#### Scenario: Case-insensitive diacritics in database backend
- **GIVEN** the database search backend
- **AND** an object has location `Cafe de Flore` (without accent)
- **WHEN** the user searches for `cafe`
- **THEN** `ILIKE` MUST match case-insensitively: `LOWER(t.location) LIKE '%cafe%'`

### Requirement: Search performance MUST come from caching, query metrics and separate count queries
The system MUST provide configurable performance optimizations including: facet result caching via APCu, query execution metrics in responses, and count query optimization separate from search queries. There is no external index, so there is no index warmup and no bulk indexing step.

#### Scenario: Search performance metrics in response
- **GIVEN** a search query is executed
- **WHEN** `MagicMapper.searchObjectsPaginated()` completes
- **THEN** the response MUST include `metrics: { search_ms: X, count_ms: Y }` with actual execution times

#### Scenario: Separate count and search queries
- **GIVEN** a paginated search request
- **WHEN** the system processes the query
- **THEN** it MUST execute TWO queries: one for results (with LIMIT/OFFSET) and one for total count (SELECT COUNT(*))
- **AND** the count query MUST use `_count: true` to trigger `MagicSearchHandler` to return only the integer count

#### Scenario: Query parameter deduplication via PHP dot-to-underscore fix
- **GIVEN** PHP converts dots in query parameter names to underscores (e.g., `@self.register` becomes `@self_register`)
- **WHEN** `SearchQueryHandler.buildSearchQuery()` processes request parameters
- **THEN** it MUST reconstruct the nested structure by splitting underscore-separated keys back into nested arrays
- **AND** system parameters starting with `_` MUST be preserved as-is

### Requirement: AggregationRunner MUST dispatch to the Postgres backend
When the runner executes a named aggregation it MUST use the Postgres-native fast path against the magic table, falling back to the PHP runner when the Postgres path rejects the input shape. There is no external (Solr/Elasticsearch) dispatch tier.

#### Scenario: Postgres-indexed schema computes via SQL
- **GIVEN** an `ActionItem` schema with a `byStatus` aggregation declared with `metric: count, groupBy: { field: "taskStatus" }`
- **WHEN** the controller calls `GET /api/objects/aggregations/decidesk/action-item/byStatus`
- **THEN** the response carries `backend: "postgres"`
- **AND** the value matches what the PHP runner would compute

#### Scenario: Unsupported input shape falls back to the PHP runner
- **GIVEN** an aggregation whose filter shape the Postgres path cannot translate
- **WHEN** the runner executes it
- **THEN** the PHP runner MUST compute the result
- **AND** the response MUST carry `backend: "php-fallback"`

## REMOVED Requirements

### Requirement: Search-backend selection and reindex administration
**Reason**: There is no longer any external search backend to select or reindex; the database (Magic-Tables) path is the sole backend. `updateSearchBackend` (`PUT`/`PATCH /api/settings/search-backend`), `testSetupHandler`, `reindexSpecificCollection` and all `SolrSettingsController` methods and their routes are removed. The read-only `GET /api/settings/search-backend` report stays, specified under "Database-backed search architecture".
**Migration**: Remove search-backend administration from settings UI and clients. The `/api/settings/solr*` endpoints and the backend selection endpoints return HTTP 404. No reindex is needed: search runs live against the database.

### Requirement: Solr collection, configset, and field administration
**Reason**: Solr collections, configsets, and fields no longer exist. `SolrController`, `SolrManagementController`, `SolrOperationsController`, and the `ConfigurationSettingsController` object-collection field methods are removed.
**Migration**: Remove all Solr administration from the UI and clients. The `/api/solr/*` endpoints return HTTP 404.

### Requirement: Offset and page-based pagination
**Reason**: The Solr pagination conversion (`SolrQueryExecutor.searchPaginated`, `convertToPaginatedFormat`) is removed.
**Migration**: Replaced by "Search results MUST paginate by offset or page", which carries the page, offset and navigation URL scenarios unchanged.

### Requirement: Backend-agnostic search architecture
**Reason**: Solr, Elasticsearch, `SearchBackendInterface`, `IndexService`, object indexing on save and index warmup jobs are removed.
**Migration**: Replaced by "Database-backed search architecture": the database search scenario is carried, object indexing on save becomes "No object indexing step on save", and the read-only backend report is specified there.

### Requirement: Search result highlighting
**Reason**: Server-side highlighting existed only on Solr (`hl`) and Elasticsearch, both removed.
**Migration**: Replaced by "Search result highlighting MUST be computed client-side", which carries the database fallback and multiple-field scenarios.

### Requirement: Dutch language search support (i18n)
**Reason**: Dutch stemming and stop-word filtering were Solr analyzers and are removed with Solr.
**Migration**: Replaced by "Dutch language search MUST match case-insensitively on the database backend", which carries the diacritics scenario. Fuzzy matching through `pg_trgm` stays under "Fuzzy search with pg_trgm integration".

### Requirement: Search performance and indexing strategy
**Reason**: Index warmup and bulk indexing into Solr are removed.
**Migration**: Replaced by "Search performance MUST come from caching, query metrics and separate count queries", which carries the metrics, count query and parameter deduplication scenarios.

### Requirement: AggregationRunner MUST dispatch by configured search backend
**Reason**: Solr and Elasticsearch aggregation dispatch and `SchemaIndexService::getBackend()` are removed.
**Migration**: Replaced by "AggregationRunner MUST dispatch to the Postgres backend" (Postgres-native first, PHP runner as fallback).

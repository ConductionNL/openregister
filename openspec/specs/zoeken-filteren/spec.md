---
status: implemented
---
# Zoeken en Filteren


# Zoeken en Filteren
## Purpose
Provide a comprehensive, backend-agnostic search and filtering system for register objects that supports full-text search with relevance ranking, field-level filtering with comparison operators, faceted drill-down navigation, multi-field sorting, cursor and offset pagination, and saved search trails. The system MUST transparently operate against PostgreSQL (with optional pg_trgm fuzzy matching), Apache Solr, or Elasticsearch as interchangeable backends, while exposing a single unified API surface through `ObjectService.searchObjectsPaginated()` and `SearchBackendInterface`.

**Tender demand**: 78% of analyzed government tenders require advanced search and filtering capabilities, including full-text search, faceted navigation, and multi-criteria filtering across structured data.

## Requirements

### Requirement: Full-text search across object properties
The system MUST support free-text search across all string-typed properties of register objects. The `_search` query parameter MUST trigger a case-insensitive search that matches against every string column in the schema's dynamic table, plus the metadata fields `_name`, `_description`, and `_summary`. Search MUST be performed using SQL `ILIKE` patterns in the database backend and native query parsing in Solr/Elasticsearch.

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

### Requirement: Field-level filtering with comparison operators
The system MUST support exact match, array containment, IN-list, null-check, and range comparison operators for filtering on individual schema properties. Filter parameters are passed as query parameters where the parameter name matches the schema property name. The `SearchQueryHandler.cleanQuery()` method MUST normalize operator suffixes (`_in`, `_gt`, `_lt`, `_gte`, `_lte`, `_isnull`) into structured filter objects.

#### Scenario: Exact match filter on a string property
- **GIVEN** schema `meldingen` with property `status` (string)
- **WHEN** the user filters with `?status=in_behandeling`
- **THEN** `MagicSearchHandler.applyObjectFilters()` MUST add `WHERE t.status = 'in_behandeling'` to the query
- **AND** only objects with exactly `status = 'in_behandeling'` MUST be returned

#### Scenario: IN-list filter for multiple values
- **GIVEN** schema `meldingen` with property `status` (string)
- **WHEN** the user filters with `?status[]=nieuw&status[]=in_behandeling` (PHP array syntax)
- **THEN** the system MUST generate `WHERE t.status IN ('nieuw', 'in_behandeling')`
- **AND** objects with either status value MUST be returned

#### Scenario: Greater-than and less-than range filters
- **GIVEN** schema `subsidies` with property `bedrag` (number)
- **WHEN** the user filters with `?bedrag_gte=5000&bedrag_lte=10000`
- **THEN** `SearchQueryHandler.cleanQuery()` MUST normalize these into `bedrag: { gte: 5000, lte: 10000 }`
- **AND** only objects with `bedrag >= 5000 AND bedrag <= 10000` MUST be returned

#### Scenario: Null-check filter
- **GIVEN** schema `meldingen` with property `afgehandeld_op` (string, format: date)
- **WHEN** the user filters with `?afgehandeld_op_isnull=true`
- **THEN** `SearchQueryHandler.cleanQuery()` MUST convert this to `WHERE afgehandeld_op IS NULL`
- **AND** only objects without an `afgehandeld_op` value MUST be returned

#### Scenario: Filter on non-existent property returns empty results
- **GIVEN** schema `meldingen` that does NOT have a property `nonexistent`
- **WHEN** the user filters with `?nonexistent=somevalue`
- **THEN** `MagicSearchHandler.applyObjectFilters()` MUST add `WHERE 1 = 0` to ensure zero results
- **AND** the property name MUST be tracked in `ignoredFilters` for client feedback in the response

### Requirement: JSON array and object property filtering
The system MUST support filtering on `type: array` (JSONB array columns) using PostgreSQL's `@>` containment operator, and on `type: object` properties using JSON path extraction. This enables filtering on multi-valued and nested structured properties.

#### Scenario: Filter on array property with single value
- **GIVEN** schema `meldingen` with property `tags` of `type: array`
- **AND** object A has `tags: ["overlast", "geluid"]` and object B has `tags: ["parkeren"]`
- **WHEN** the user filters with `?tags=overlast`
- **THEN** `MagicSearchHandler.applyJsonArrayFilter()` MUST use `COALESCE(t.tags, '[]')::jsonb @> '["overlast"]'::jsonb`
- **AND** only object A MUST be returned

#### Scenario: Filter on array property with multiple values (OR logic)
- **GIVEN** the same schema with objects having various tags
- **WHEN** the user filters with `?tags[]=overlast&tags[]=parkeren`
- **THEN** the system MUST generate OR conditions: `(tags @> '["overlast"]' OR tags @> '["parkeren"]')`
- **AND** both object A and object B MUST be returned

#### Scenario: Filter on object property with UUID value
- **GIVEN** schema `meldingen` with property `melder` of `type: object` containing `{ "value": "uuid-123", "label": "Jan" }`
- **WHEN** the user filters with `?melder=uuid-123`
- **THEN** `MagicSearchHandler.applyJsonObjectFilter()` MUST extract the `value` key from the JSONB column and compare it

### Requirement: Metadata filtering via @self namespace
The system MUST support filtering on object metadata fields (register, schema, uuid, organisation, owner, application, created, updated, deleted) through the `@self` namespace in the query structure. These map to underscore-prefixed columns in the dynamic tables (`_register`, `_schema`, `_uuid`, etc.).

#### Scenario: Filter by register and schema
- **GIVEN** objects across multiple registers and schemas
- **WHEN** the API receives `?register=1&schema=2`
- **THEN** `SearchQueryHandler.buildSearchQuery()` MUST place these into `query['@self']['register'] = 1` and `query['@self']['schema'] = 2`
- **AND** `MagicSearchHandler.applyMetadataFilters()` MUST add `WHERE t._register = 1 AND t._schema = 2`

#### Scenario: Filter by owner
- **GIVEN** objects owned by different users
- **WHEN** the API receives `?owner=admin`
- **THEN** the system MUST filter on `t._owner = 'admin'` via the `@self` metadata filter mechanism

#### Scenario: Filter by multiple registers (array)
- **GIVEN** a view combining objects from registers 1, 2, and 3
- **WHEN** `SearchQueryHandler.applyViewsToQuery()` merges view registers into the query
- **THEN** `query['@self']['register']` MUST be `[1, 2, 3]`
- **AND** `MagicSearchHandler.applyMetadataFilters()` MUST use `WHERE t._register IN (1, 2, 3)`

### Requirement: Fuzzy search with pg_trgm integration
The system MUST support optional fuzzy (typo-tolerant) search when the `_fuzzy=true` parameter is explicitly set AND the PostgreSQL `pg_trgm` extension is available. Fuzzy search MUST use the `similarity()` function on the `_name` column with a threshold of `0.1`. When fuzzy search is active, a `_relevance` score column MUST be available for sorting.

#### Scenario: Fuzzy search enabled with pg_trgm
- **GIVEN** PostgreSQL database with `pg_trgm` extension installed
- **AND** an object with `_name = "Geluidsoverlast"`
- **WHEN** the user searches with `?_search=Geluidoverlast&_fuzzy=true` (missing 's')
- **THEN** the system MUST add `similarity(_name::text, 'Geluidoverlast') > 0.1` to the OR conditions
- **AND** the object MUST appear in results despite the typo

#### Scenario: Relevance score in results
- **GIVEN** fuzzy search is enabled
- **WHEN** search results are returned
- **THEN** each result MUST include a `_relevance` field computed as `ROUND(similarity(_name::text, searchTerm) * 100)::integer`
- **AND** results MUST be sortable by `_relevance DESC` via `?_order={"_relevance":"DESC"}`

#### Scenario: Fuzzy search disabled by default
- **GIVEN** a search request without `_fuzzy=true`
- **WHEN** `MagicSearchHandler.isFuzzySearchEnabled()` is called
- **THEN** it MUST return `false` regardless of pg_trgm availability
- **AND** only ILIKE-based search MUST be performed (approximately 13% faster than fuzzy)

#### Scenario: Fuzzy search gracefully degrades without pg_trgm
- **GIVEN** a MariaDB or PostgreSQL database WITHOUT `pg_trgm` extension
- **WHEN** the user searches with `?_search=test&_fuzzy=true`
- **THEN** `hasPgTrgmExtension()` MUST return `false` (cached for request lifetime)
- **AND** the search MUST fall back to ILIKE-only matching without error

### Requirement: Multi-field sorting with metadata and relevance support
The system MUST support sorting by one or more fields via the `_order` parameter, which accepts a JSON object mapping field names to sort directions (`ASC` or `DESC`). Sorting MUST work on schema property columns, metadata columns (prefixed with `_` or `@self.`), and the special `_relevance` pseudo-column for fuzzy search ranking.

#### Scenario: Single-field sort on schema property
- **GIVEN** schema `meldingen` with property `aanmaakdatum`
- **WHEN** the user requests `?_order={"aanmaakdatum":"DESC"}`
- **THEN** `MagicSearchHandler.applySorting()` MUST add `ORDER BY t.aanmaakdatum DESC`

#### Scenario: Multi-field sort
- **GIVEN** schema `meldingen` with properties `status` and `aanmaakdatum`
- **WHEN** the user requests `?_order={"status":"ASC","aanmaakdatum":"DESC"}`
- **THEN** the system MUST add `ORDER BY t.status ASC, t.aanmaakdatum DESC`
- **AND** sorting MUST be applied BEFORE pagination so the query optimizer can use indexes

#### Scenario: Sort by metadata field using @self prefix
- **GIVEN** objects with `_created` and `_updated` metadata timestamps
- **WHEN** the user requests `?_order={"@self.created":"DESC"}`
- **THEN** `applySorting()` MUST translate `@self.created` to `t._created` and add `ORDER BY t._created DESC`

#### Scenario: Sort by relevance in fuzzy search
- **GIVEN** a search with `?_search=overlast&_fuzzy=true&_order={"_relevance":"DESC"}`
- **WHEN** `applySorting()` encounters the `_relevance` field
- **THEN** it MUST add `ORDER BY similarity(t._name::text, 'overlast') DESC`
- **AND** if `pg_trgm` is not available, the `_relevance` sort MUST be silently skipped

#### Scenario: Legacy ordering parameter
- **GIVEN** a request with `?ordering=-aanmaakdatum` (legacy format)
- **WHEN** `SearchQueryHandler.cleanQuery()` processes the parameter
- **THEN** it MUST convert the leading `-` to `DESC` direction: `_order: { aanmaakdatum: DESC }`

### Requirement: Offset and page-based pagination
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

#### Scenario: Solr backend pagination format
- **GIVEN** Solr is the active search backend
- **WHEN** `SolrQueryExecutor.searchPaginated()` returns results
- **THEN** it MUST convert Solr's `start`/`numFound` to OpenRegister's `{ results, total, limit, offset, page, pages }` format via `convertToPaginatedFormat()`

### Requirement: Faceted search with configurable facets
The system MUST compute facet counts (value distributions) for properties marked as `facetable` in the schema definition. Facets MUST be calculated on the full filtered dataset independent of pagination. The faceting system MUST support aggregated facets (merged across schemas), non-aggregated facets (schema-scoped), configurable titles/descriptions/ordering, and date histogram facets. See `faceting-configuration` spec for full facet configuration details.

#### Scenario: Display facet counts for search results
- **GIVEN** 100 `meldingen` objects with property `status` marked `facetable: true`
- **AND** values distributed as: `nieuw` (30), `in_behandeling` (45), `afgehandeld` (25)
- **WHEN** a search query returns these results
- **THEN** the `facets` section of the response MUST include `status` with buckets showing each value and its count
- **AND** facet computation MUST use `MagicFacetHandler` (SQL) or `SolrFacetProcessor` (Solr) depending on backend

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

### Requirement: Backend-agnostic search architecture
The search system MUST operate transparently across three backends: PostgreSQL (default, using SQL ILIKE/pg_trgm), Apache Solr (via `SolrBackend`), and Elasticsearch (via `ElasticsearchBackend`). All backends MUST implement `SearchBackendInterface` with methods for `searchObjectsPaginated()`, `indexObject()`, `bulkIndexObjects()`, `deleteObject()`, `warmupIndex()`, `getStats()`, and collection management. The `IndexService` MUST coordinate backend operations as a facade.

#### Scenario: Database-backed search (default, no external engine)
- **GIVEN** no external search engine is configured (Solr disabled in settings)
- **WHEN** the user performs a full-text search
- **THEN** `MagicSearchHandler` MUST execute SQL queries with ILIKE patterns against the dynamic tables
- **AND** `SearchQueryHandler.isSolrAvailable()` MUST return `false` by checking `settingsService.getSolrSettings()`
- **AND** results MUST be returned within acceptable response times for datasets under 100,000 objects

#### Scenario: Solr backend search with relevance ranking
- **GIVEN** Solr is configured and the collection is synced via `SolrBackend.warmupIndex()`
- **WHEN** the user performs a search with `?_search=overlast`
- **THEN** `SolrQueryExecutor.searchPaginated()` MUST build a Solr query with `q=overlast` and execute against the active collection
- **AND** results MUST benefit from Solr's native relevance ranking (TF-IDF/BM25)
- **AND** `convertToPaginatedFormat()` MUST normalize Solr's response to the standard `{ results, total, page, pages }` format

#### Scenario: Elasticsearch backend search
- **GIVEN** Elasticsearch is configured as the search backend
- **WHEN** the user performs a search
- **THEN** `ElasticsearchBackend.searchObjectsPaginated()` MUST delegate to `ElasticsearchQueryExecutor`
- **AND** the response format MUST be identical to the PostgreSQL and Solr backends

#### Scenario: Object indexing on save
- **GIVEN** Solr or Elasticsearch is the active backend
- **WHEN** an object is created or updated via `ObjectService`
- **THEN** `SearchBackendInterface.indexObject()` MUST be called to sync the object to the search index
- **AND** `BulkIndexer` MUST be used for batch imports to minimize commit overhead

#### Scenario: Index warmup via background jobs
- **GIVEN** Solr is configured
- **WHEN** the `SolrWarmupJob` or `SolrNightlyWarmupJob` TimedJob runs
- **THEN** it MUST call `warmupIndex()` to pre-populate the index with all searchable objects
- **AND** `SolrManagementCommand` MUST provide CLI tools for manual index management

### Requirement: Search result highlighting
Matching terms in search results MUST be visually highlightable. When the search backend supports highlighting (Solr `hl` parameter, Elasticsearch `highlight`), the API response MUST include highlighted fragments in a `_highlights` field per result. For the database backend, highlighting MUST be computed client-side.

#### Scenario: Solr highlighting in API response
- **GIVEN** Solr is the active backend and a search for `geluidsoverlast` matches object `melding-1`
- **AND** `melding-1` has title `Melding geluidsoverlast Kerkstraat`
- **WHEN** the API returns the search results
- **THEN** the result MUST include `_highlights: { title: "Melding <em>geluidsoverlast</em> Kerkstraat" }`
- **AND** highlighted fragments in long descriptions MUST show a relevant excerpt (max 200 characters) around the match

#### Scenario: Database backend highlighting fallback
- **GIVEN** PostgreSQL is the active backend (no Solr)
- **WHEN** search results are returned
- **THEN** the `_highlights` field MUST be absent or empty
- **AND** the frontend MUST perform client-side highlighting using the `_search` term from the query

#### Scenario: Multiple field highlighting
- **GIVEN** a search term matches in both `title` and `description` of an object
- **WHEN** highlighting is returned
- **THEN** `_highlights` MUST contain entries for each matching field with highlighted fragments

### Requirement: Saved searches and search trails
The system MUST support persisting search queries as `SearchTrail` entities for analytics and quick re-execution. Each trail MUST record the search term, query parameters, result count, total results, register/schema context, user information, session, IP address, request URI, HTTP method, response time, and page number. Search trail creation MUST be controlled by the `searchTrailsEnabled` retention setting.

#### Scenario: Save a search trail entry
- **GIVEN** search trails are enabled via `settingsService.getRetentionSettingsOnly()['searchTrailsEnabled'] = true`
- **AND** a user searches for `overlast` with filters `status=in_behandeling&wijk=centrum`
- **WHEN** `SearchQueryHandler.logSearchTrail()` is called after search execution
- **THEN** a `SearchTrail` entity MUST be created with `searchTerm: 'overlast'`, `queryParameters: { status: 'in_behandeling', wijk: 'centrum' }`, `resultCount`, `totalResults`, `responseTime`, and user/session metadata

#### Scenario: Search trail includes context metadata
- **GIVEN** a search is performed against register ID 1, schema ID 2
- **WHEN** the trail is created
- **THEN** it MUST include `register: 1`, `schema: 2`, `registerUuid`, `schemaUuid`, `registerName`, `schemaName` for analytics grouping

#### Scenario: CRUD operations on search trails
- **GIVEN** the `SearchTrailController` exposes REST endpoints
- **WHEN** a client makes GET/POST/PUT/DELETE requests to the search trail API
- **THEN** `SearchTrailService` MUST handle CRUD operations including a self-clearing capability for expired trails

#### Scenario: Search trails disabled
- **GIVEN** `searchTrailsEnabled = false` in retention settings
- **WHEN** a search is performed
- **THEN** `logSearchTrail()` MUST skip trail creation entirely without error

### Requirement: Nextcloud Unified Search integration
The system MUST integrate with Nextcloud's global search bar via `IFilteringProvider`. The `ObjectsProvider` MUST appear as a search provider in NC's unified search, returning register objects as `SearchResultEntry` items with proper titles, descriptions, and deep-linked URLs.

#### Scenario: Objects appear in NC global search
- **GIVEN** a user types `overlast` in the Nextcloud top search bar
- **WHEN** NC's search framework invokes `ObjectsProvider.search()`
- **THEN** it MUST call `ObjectService.searchObjectsPaginated()` with `_search: 'overlast'`
- **AND** return `SearchResult` with `SearchResultEntry` items containing object name, summary, and URL

#### Scenario: Deep-linked search results
- **GIVEN** a consuming app (e.g., opencatalogi) has registered a deep link pattern via `DeepLinkRegistryService`
- **WHEN** a search result is returned for an object in that app's register/schema
- **THEN** the `SearchResultEntry` URL MUST point to the consuming app's detail page, not the raw OpenRegister URL

#### Scenario: Pagination via ISearchQuery
- **GIVEN** NC passes `ISearchQuery` with `cursor` and `limit` parameters
- **WHEN** `ObjectsProvider.search()` processes the query
- **THEN** it MUST translate NC's cursor-based pagination to OpenRegister's offset-based pagination

### Requirement: Search across registers (global search)
The system MUST support searching across ALL registers and schemas when no register/schema context is provided. Global text search MUST scan all dynamic tables. Global ID search MUST look up objects by UUID across all magic tables.

#### Scenario: Global text search without register/schema
- **GIVEN** objects exist across registers 1, 2, 3 with various schemas
- **WHEN** the user searches with `?_search=centrum` without specifying register or schema
- **THEN** `MagicMapper.searchObjectsPaginated()` MUST detect `isGlobalTextSearch = true`
- **AND** call `searchObjectsGloballyBySearch()` which iterates all magic tables
- **AND** return combined, deduplicated results with register/schema metadata

#### Scenario: Global ID search across all tables
- **GIVEN** object with UUID `abc-123` exists in register 2, schema 5
- **WHEN** the user searches with `?_ids=abc-123` without register/schema context
- **THEN** `MagicMapper` MUST call `findMultipleAcrossAllMagicTables()` to locate the object
- **AND** return it via `getGlobalSearchResult()`

#### Scenario: Global relations search
- **GIVEN** objects across multiple schemas reference UUID `ref-456` in their `_relations` field
- **WHEN** the user searches with `?_relations_contains=ref-456` without register/schema
- **THEN** `findByRelationAcrossAllMagicTables()` MUST search all magic tables using JSONB containment (`@>`)
- **AND** return all objects that reference the given UUID

### Requirement: View-based search composition
The system MUST support composing searches from saved view definitions. Views define pre-configured filters for registers, schemas, and search terms. Multiple views MUST be combinable with additive filter logic.

#### Scenario: Apply a single view to a search
- **GIVEN** a view with `query: { registers: [1, 2], schemas: [3, 4], searchTerms: ["overlast"] }`
- **WHEN** `SearchQueryHandler.applyViewsToQuery()` merges the view into the base query
- **THEN** `query['@self']['register']` MUST be `[1, 2]`
- **AND** `query['@self']['schema']` MUST be `[3, 4]`
- **AND** `query['_search']` MUST include `overlast`

#### Scenario: Combine multiple views
- **GIVEN** view A filters for registers `[1]` and view B filters for registers `[2, 3]`
- **WHEN** both views are applied
- **THEN** `query['@self']['register']` MUST be `[1, 2, 3]` (merged with `array_unique`)

#### Scenario: View with search terms merged into existing search
- **GIVEN** a user has typed `centrum` in the search box
- **AND** a view adds search term `overlast`
- **WHEN** the view is applied
- **THEN** `query['_search']` MUST become `centrum overlast` (space-concatenated)

### Requirement: Access control in search results (RBAC and multi-tenancy)
Search results MUST respect role-based access control (RBAC) and multi-tenancy filters. RBAC MUST filter results based on the user's roles and schema-level authorization rules. Multi-tenancy MUST restrict results to the user's active organisation, with automatic bypass for public schemas.

#### Scenario: RBAC filtering applied to search
- **GIVEN** schema `meldingen` has authorization rule `read: [role:medewerker]`
- **AND** the current user has role `medewerker`
- **WHEN** the user searches with `?_search=overlast`
- **THEN** `MagicSearchHandler.applyAccessControlFilters()` MUST include RBAC conditions from `MagicRbacHandler`
- **AND** only objects the user is authorized to read MUST appear in results

#### Scenario: Public schema bypasses multi-tenancy
- **GIVEN** schema `publicaties` has authorization `read: ["public"]`
- **AND** multi-tenancy is enabled but NOT explicitly requested via `_multitenancy_explicit`
- **WHEN** a search is performed
- **THEN** `resolveMultitenancyFlag()` MUST detect public read access and set `_multitenancy = false`
- **AND** objects from ALL organisations MUST be visible

#### Scenario: Explicit multi-tenancy with RBAC
- **GIVEN** a user with RBAC access explicitly sets `?_multitenancy_explicit=true`
- **WHEN** search results are returned
- **THEN** both RBAC and organisation-level filtering MUST be applied simultaneously
- **AND** results MUST be restricted to the user's organisation even though they have RBAC access

### Requirement: Dutch language search support (i18n)
The system MUST support Dutch language search capabilities. When Solr is active, Dutch language analysis (Snowball stemmer, Dutch stop words) MUST be configured. The database backend MUST support case-insensitive matching for Dutch diacritics via PostgreSQL's `ILIKE` which handles UTF-8 natively.

#### Scenario: Dutch stemming in Solr
- **GIVEN** Solr is configured with Dutch language analyzers (Snowball stemmer for Dutch)
- **AND** an object has description `De fietsenrekken zijn beschadigd`
- **WHEN** the user searches for `fietsrek`
- **THEN** Solr's Dutch stemmer MUST match `fietsenrekken` to the stem `fietsrek`
- **AND** the object MUST appear in results

#### Scenario: Case-insensitive diacritics in database backend
- **GIVEN** PostgreSQL is the active backend
- **AND** an object has location `Cafe de Flore` (without accent)
- **WHEN** the user searches for `cafe`
- **THEN** `ILIKE` MUST match case-insensitively: `LOWER(t.location) LIKE '%cafe%'`

#### Scenario: Dutch stop words filtered in Solr
- **GIVEN** Solr is configured with Dutch stop word filters
- **WHEN** the user searches for `de fietsenrekken`
- **THEN** the stop word `de` MUST be filtered out and only `fietsenrekken` MUST be used for matching

### Requirement: Search performance and indexing strategy
The system MUST provide configurable performance optimizations including: index warmup via background jobs, facet result caching via APCu, query execution metrics in responses, bulk indexing for batch operations, and count query optimization separate from search queries.

#### Scenario: Search performance metrics in response
- **GIVEN** a search query is executed
- **WHEN** `MagicMapper.searchObjectsPaginated()` completes
- **THEN** the response MUST include `metrics: { search_ms: X, count_ms: Y }` with actual execution times

#### Scenario: Separate count and search queries
- **GIVEN** a paginated search request
- **WHEN** the system processes the query
- **THEN** it MUST execute TWO queries: one for results (with LIMIT/OFFSET) and one for total count (SELECT COUNT(*))
- **AND** the count query MUST use `_count: true` to trigger `MagicSearchHandler` to return only the integer count

#### Scenario: Bulk indexing with batch commits
- **GIVEN** 10,000 objects need to be indexed in Solr
- **WHEN** `SearchBackendInterface.bulkIndexObjects()` is called
- **THEN** objects MUST be indexed in configurable batch sizes (default 1000)
- **AND** commits MUST only occur after each batch, not after each individual document

#### Scenario: Query parameter deduplication via PHP dot-to-underscore fix
- **GIVEN** PHP converts dots in query parameter names to underscores (e.g., `@self.register` becomes `@self_register`)
- **WHEN** `SearchQueryHandler.buildSearchQuery()` processes request parameters
- **THEN** it MUST reconstruct the nested structure by splitting underscore-separated keys back into nested arrays
- **AND** system parameters starting with `_` MUST be preserved as-is

## Current Implementation Status

**Substantially implemented.** The search and filtering system is mature with comprehensive SQL-based and Solr-based backends.

**Fully implemented:**
- `lib/Db/MagicMapper/MagicSearchHandler.php` -- SQL-based full-text search (ILIKE), fuzzy search (pg_trgm), metadata filtering, object field filtering, JSON array/object filtering, access control (RBAC + multi-tenancy), multi-field sorting, pagination
- `lib/Db/MagicMapper/MagicFacetHandler.php` -- SQL-based facet computation with UNION queries, configurable max buckets
- `lib/Db/MagicMapper.php` -- Orchestrates single-schema, multi-schema (UNION), global text, global ID, and global relations search via `searchObjectsPaginated()`
- `lib/Service/Object/SearchQueryHandler.php` -- Query building, parameter normalization, operator suffix parsing, view application, pagination URL generation, search trail logging
- `lib/Service/Object/FacetHandler.php` -- Centralized faceting with smart fallback, response caching, non-aggregated facet isolation, custom titles/descriptions/ordering, date histogram facets
- `lib/Service/Schemas/FacetCacheHandler.php` -- APCu-based facet result caching
- `lib/Service/Index/SearchBackendInterface.php` -- Backend-agnostic interface (22 methods)
- `lib/Service/Index/Backends/SolrBackend.php` -- Full Solr integration with indexing, searching, collection management
- `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php` -- Solr query building, execution, pagination format conversion
- `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php` -- Solr-native faceting
- `lib/Service/Index/Backends/ElasticsearchBackend.php` -- Elasticsearch integration with `ElasticsearchQueryExecutor`, `ElasticsearchDocumentIndexer`, `ElasticsearchIndexManager`, `ElasticsearchHttpClient`
- `lib/Service/IndexService.php` -- Facade coordinating FileHandler, ObjectHandler, SchemaHandler across backends
- `lib/Service/Index/BulkIndexer.php` -- Batch indexing with configurable batch sizes
- `lib/Search/ObjectsProvider.php` -- Nextcloud unified search provider (implements `IFilteringProvider`)
- `lib/Db/SearchTrail.php` + `SearchTrailMapper.php` -- Search trail entity and persistence
- `lib/Controller/SearchTrailController.php` + `SearchTrailService.php` -- CRUD API for search trails with self-clearing
- `lib/Controller/SearchController.php` -- REST API for Solr-based search
- `lib/Db/ObjectHandlers/OptimizedFacetHandler.php`, `HyperFacetHandler.php`, `MariaDbFacetHandler.php`, `MetaDataFacetHandler.php` -- Various facet computation strategies
- `lib/BackgroundJob/SolrWarmupJob.php`, `SolrNightlyWarmupJob.php` -- Background index warmup
- `lib/Command/SolrManagementCommand.php`, `SolrDebugCommand.php` -- CLI tools for Solr management

**Not fully implemented:**
- Search result highlighting: Solr supports `hl` parameter but it is not exposed in API responses; no highlighting in database backend
- Dutch language stemming in SQL-based search: only Solr has Dutch analyzers configured; database backend relies on ILIKE
- Search trail persistence: `logSearchTrail()` method has a TODO comment; the service/entity exist but actual trail creation is commented out
- Geo-spatial search: not yet implemented in any backend
- Saved search re-execution UI: backend CRUD exists but frontend integration for re-executing saved searches is not verified

## Standards & References
- Apache Solr (https://solr.apache.org/) -- primary external search engine
- Elasticsearch (https://www.elastic.co/) -- secondary external search engine
- PostgreSQL pg_trgm (https://www.postgresql.org/docs/current/pgtrgm.html) -- fuzzy text matching extension
- Nextcloud Unified Search API (`IFilteringProvider`, `ISearchQuery`, `SearchResult`)
- Dutch language analysis (Snowball stemmer, Dutch stop words)
- JSON API filtering conventions (operator suffixes: `_gt`, `_lt`, `_gte`, `_lte`, `_in`, `_isnull`)
- Cross-reference: `faceting-configuration` spec (per-property facet config, non-aggregated facets, date histogram types)
- Cross-reference: `api-test-coverage` spec (search endpoint test coverage)

## Specificity Assessment
- **Specific enough to implement?** Yes -- the 15 requirements cover the complete search/filter/sort/paginate/facet lifecycle with concrete scenarios referencing actual class names and method signatures.
- **Missing/ambiguous:**
  - Relevance boost configuration: no specification for per-field or per-schema boosting in Solr/Elasticsearch
  - Highlighting format: should use `<em>` tags? configurable markers? max fragment length?
  - Search indexing latency: real-time (sync on save) vs. background (eventual consistency) -- currently sync for Solr, but no SLA defined
  - Search permissions: RBAC is applied but there is no specification for field-level security (FLS) in search results
  - Search analytics: search trails are partially implemented but no specification for popular query reporting or zero-result query alerting
  - Geo-spatial search: not yet specified (would require Solr spatial fields or PostGIS)
- **Open questions:**
  - Should search trail creation be re-enabled? The `logSearchTrail()` method body is commented out.
  - How should highlighting fragments be delivered in the API response? As a separate `_highlights` map or inline within result fields?
  - Should the Elasticsearch backend support the same faceting capabilities as Solr, or is Solr the primary faceted search backend?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: Full-text search via `MagicSearchHandler` (SQL ILIKE + pg_trgm fuzzy) and `SolrBackend` / `ElasticsearchBackend` (native search engines). `ObjectsProvider` implements NC unified search via `IFilteringProvider`. Multiple facet handlers (`MagicFacetHandler`, `SolrFacetProcessor`, `OptimizedFacetHandler`, `HyperFacetHandler`, `MariaDbFacetHandler`). `SearchTrail` entity for saved searches. `IndexService` orchestrates cross-backend search. Solr warmup jobs for performance. `DeepLinkRegistryService` for search result URLs.
- **Nextcloud Core Integration**: Implements `IFilteringProvider` (NC unified search provider) via `ObjectsProvider`, enabling OpenRegister objects to appear in NC's global search bar. Uses `ISearchQuery` for pagination parameters. APCu caching for facet results via NC's `ICacheFactory` infrastructure. Background jobs (`SolrWarmupJob`, `SolrNightlyWarmupJob`) use NC's `TimedJob`. CLI commands extend NC's `Command` base class. Multi-tenancy integrates with NC's user/group management.
- **Recommendation**: Mark as implemented. The `IFilteringProvider` integration is the key NC-native touchpoint. Priority improvements: (1) expose Solr highlighting in API responses, (2) re-enable search trail persistence, (3) add Dutch stemming fallback for SQL backend.

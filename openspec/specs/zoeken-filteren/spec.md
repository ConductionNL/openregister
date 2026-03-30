# zoeken-filteren Specification

---
status: implemented
---

## Purpose
Implement full-text search with faceted filtering, result highlighting, and saved search functionality for register objects. The search system MUST support searching across multiple schemas and registers, provide instant results with relevance ranking, and offer configurable facets for drill-down navigation.

**Tender demand**: 78% of analyzed government tenders require advanced search and filtering capabilities.

## ADDED Requirements

### Requirement: The system MUST support full-text search across object properties
Users MUST be able to search register objects using free-text queries that match against all text properties.

#### Scenario: Full-text search across properties
- GIVEN schema `meldingen` with objects containing `title`, `description`, and `location` text properties
- AND object `melding-1` has title `Geluidsoverlast` and description `Buren maken veel lawaai na middernacht`
- WHEN the user searches for `lawaai`
- THEN `melding-1` MUST appear in the results (matches description)
- AND the search MUST be case-insensitive

#### Scenario: Search across multiple schemas
- GIVEN register `zaken` with schemas `meldingen` and `vergunningen`
- WHEN the user searches for `centrum` at the register level
- THEN results MUST include matching objects from both schemas
- AND each result MUST indicate which schema it belongs to

#### Scenario: Search with Dutch language analysis
- GIVEN an object with description `De fietsenrekken zijn beschadigd`
- WHEN the user searches for `fietsrek` (stem of fietsenrekken)
- THEN the object MUST appear in results (Dutch stemming applied)

### Requirement: The system MUST support faceted filtering
Search results MUST include configurable facets that allow drill-down filtering by property values.

#### Scenario: Display facets for search results
- GIVEN a search returning 100 meldingen objects
- AND facets are configured for properties `status`, `categorie`, and `wijk`
- WHEN the search results are displayed
- THEN facet panels MUST show:
  - Status: nieuw (30), in_behandeling (45), afgehandeld (25)
  - Categorie: overlast (40), schade (35), milieu (25)
  - Wijk: centrum (20), oost (30), west (25), noord (25)
- AND each facet value MUST show the count of matching objects

#### Scenario: Apply facet filter
- GIVEN search results with facets displayed
- WHEN the user clicks facet value `status: in_behandeling`
- THEN results MUST be filtered to show only the 45 in_behandeling objects
- AND other facets MUST be recalculated based on the filtered set
- AND the active filter MUST be visually indicated with a removable chip

#### Scenario: Combine multiple facets
- GIVEN the user has applied `status: in_behandeling`
- WHEN they additionally apply `wijk: centrum`
- THEN results MUST show only objects matching BOTH criteria
- AND the facet counts MUST reflect the combined filter

### Requirement: Search results MUST support highlighting
Matching terms in search results MUST be visually highlighted to show why each result matched.

#### Scenario: Highlight matching terms
- GIVEN a search for `geluidsoverlast`
- AND object `melding-1` has title `Melding geluidsoverlast Kerkstraat`
- WHEN the results are displayed
- THEN the title MUST be rendered with `geluidsoverlast` highlighted
- AND if the match is in a long description, a relevant excerpt MUST be shown with highlighting

### Requirement: The system MUST support saved searches
Users MUST be able to save search queries with filters for quick re-execution.

#### Scenario: Save a search query
- GIVEN the user has searched for `overlast` with filters `status: in_behandeling, wijk: centrum`
- WHEN the user clicks "Save search" and names it `Actieve overlastmeldingen centrum`
- THEN the saved search MUST be stored with the query text and all active filters
- AND it MUST appear in the user's saved searches list

#### Scenario: Execute a saved search
- GIVEN a saved search `Actieve overlastmeldingen centrum`
- WHEN the user clicks it
- THEN the search MUST execute with the saved query and filters
- AND results MUST reflect current data (not cached results from save time)

### Requirement: The system MUST support date range and numeric range filters
Beyond facets, the system MUST support range-based filtering for dates and numbers.

#### Scenario: Filter by date range
- GIVEN meldingen objects with `aanmaakdatum` spanning January to March 2026
- WHEN the user filters `aanmaakdatum` from 2026-02-01 to 2026-02-28
- THEN only objects created in February MUST be returned

#### Scenario: Filter by numeric range
- GIVEN subsidie objects with `bedrag` from 1000 to 50000
- WHEN the user filters `bedrag` from 5000 to 10000
- THEN only objects with bedrag in that range MUST be returned

### Requirement: The search system MUST be backend-agnostic
The search system MUST work with the built-in database and optionally with Elasticsearch or Solr for improved performance.

#### Scenario: Database-backed search (default)
- GIVEN no external search engine is configured
- WHEN the user performs a full-text search
- THEN the system MUST use SQL LIKE or database full-text index queries
- AND results MUST be returned within acceptable response times for datasets under 100,000 objects

#### Scenario: External search engine integration
- GIVEN Elasticsearch or Solr is configured and indices are synced
- WHEN the user performs a full-text search
- THEN the system MUST query the external engine for results
- AND benefit from improved relevance ranking, highlighting, and faceting performance

### Current Implementation Status

**Substantially implemented.** Most search and faceting requirements are in place:

**Implemented (full-text search):**
- `lib/Db/MagicMapper/MagicSearchHandler.php` (also `MariaDbSearchHandler`) -- SQL-based full-text search with LIKE queries and JSON field extraction
- `lib/Service/Index/Backends/SolrBackend.php` -- Solr integration for advanced search with relevance ranking
- `lib/Service/Index/SearchBackendInterface.php` -- Backend-agnostic search interface
- `lib/Service/IndexService.php` -- Orchestrates search operations across backends
- `lib/Search/ObjectsProvider.php` -- Nextcloud unified search provider (implements `IFilteringProvider`)
- `lib/Controller/SearchController.php` -- REST API for search operations
- `lib/Controller/FileSearchController.php` -- File-specific search controller
- `lib/Service/Object/SearchQueryHandler.php` -- Builds search queries from API parameters

**Implemented (faceted filtering):**
- `lib/Db/MagicMapper/MagicFacetHandler.php` -- SQL-based facet computation for magic tables (with configurable max buckets)
- `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php` -- Solr-native faceting with field facets
- `lib/Service/Index/FacetBuilder.php` -- Builds facet configurations for Solr queries
- `lib/Db/ObjectHandlers/MetaDataFacetHandler.php` -- Metadata-based facets (@self fields)
- `lib/Db/ObjectHandlers/OptimizedFacetHandler.php`, `HyperFacetHandler.php`, `MariaDbFacetHandler.php` -- Various facet computation strategies
- `lib/Service/Schemas/FacetCacheHandler.php` -- Facet result caching for performance
- `lib/Service/Object/FacetHandler.php` -- Facet processing in object service

**Implemented (saved searches / search trails):**
- `lib/Db/SearchTrail.php` -- Entity for saved search queries with filters
- `lib/Db/SearchTrailMapper.php` -- Database mapper for search trails
- `lib/Controller/SearchTrailController.php` -- CRUD API for search trails
- `lib/Service/SearchTrailService.php` -- Service with self-clearing capability

**Implemented (backend-agnostic):**
- Database (SQL LIKE) search works without external engines
- Solr backend with full indexing, warmup jobs (`SolrWarmupJob`, `SolrNightlyWarmupJob`)
- `lib/Command/SolrManagementCommand.php` and `SolrDebugCommand.php` -- CLI tools for Solr management

**Not fully implemented:**
- Search result highlighting (Solr supports it but not exposed in API responses)
- Dutch language analysis/stemming in SQL-based search (Solr has Dutch analyzers)
- Cross-register search at register level
- Numeric range filters (date ranges partially supported via Solr)
- Elasticsearch backend (interface exists but no implementation found)
- UI for saved searches (backend exists, frontend integration unclear)

### Standards & References
- Apache Solr (https://solr.apache.org/) -- primary external search engine
- Elasticsearch (https://www.elastic.co/) -- planned alternative backend
- Nextcloud Unified Search API (`IFilteringProvider`)
- Dutch language analysis (Snowball stemmer, Dutch stop words)
- JSON API filtering conventions

### Specificity Assessment
- **Specific enough to implement?** Mostly yes -- the scenarios cover the main use cases clearly.
- **Missing/ambiguous:**
  - No specification for relevance ranking algorithm or boost configuration
  - No specification for search result highlighting format (HTML tags? markers?)
  - No specification for search indexing latency (real-time vs. background sync)
  - No specification for search permissions (should search respect RLS/FLS?)
  - No specification for fuzzy/typo-tolerant search
  - No specification for search analytics (popular queries, zero-result queries)
- **Open questions:**
  - Should Elasticsearch be supported alongside Solr, or is Solr the sole external backend?
  - How should search highlighting be rendered in the Vue frontend?
  - Should saved searches support notification on new matches (saved search alerts)?

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: Full-text search via `MagicSearchHandler` (SQL LIKE) and `SolrBackend` (Apache Solr with relevance ranking). `ObjectsProvider` implements NC unified search. Multiple facet handlers (`MagicFacetHandler`, `SolrFacetProcessor`, `OptimizedFacetHandler`, `HyperFacetHandler`, `MariaDbFacetHandler`). `SearchTrail` entity for saved searches. `IndexService` orchestrates cross-backend search. Solr warmup jobs for performance.
- **Nextcloud Core Integration**: Implements `IFilteringProvider` (NC unified search provider) via `ObjectsProvider`, enabling OpenRegister objects to appear in NC's global search bar. Uses `ISearchQuery` for pagination parameters. APCu caching for facet results via NC's cache infrastructure. Background jobs (`SolrWarmupJob`, `SolrNightlyWarmupJob`) use NC's `TimedJob`. CLI commands extend NC's `Command` base class.
- **Recommendation**: Mark as implemented. The `IFilteringProvider` integration is the key NC-native touchpoint. Consider exposing search highlighting in API responses and adding Dutch language stemming for the SQL backend.

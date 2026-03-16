# zoeken-filteren Specification

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

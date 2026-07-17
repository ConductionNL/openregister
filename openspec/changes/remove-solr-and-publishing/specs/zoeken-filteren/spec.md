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

### Requirement: Database-backed search architecture
The search system MUST operate exclusively on the PostgreSQL/Magic-Tables backend using SQL `ILIKE`/`pg_trgm`. There is no external search engine and no `SearchBackendInterface`/`IndexService` facade. `MagicSearchHandler` and `MagicMapper` MUST own all search execution, indexing-free, querying the live dynamic tables.

#### Scenario: Database-backed search is the only path
- **GIVEN** an OpenRegister instance with no external search engine configured (none can be)
- **WHEN** the user performs a full-text search
- **THEN** `MagicSearchHandler` MUST execute SQL queries with ILIKE patterns against the dynamic tables
- **AND** results MUST be returned within acceptable response times for datasets under 100,000 objects
- **AND** the response envelope MUST be `{ results, total, page, pages }`

#### Scenario: No object indexing step on save
- **GIVEN** an object is created or updated via `ObjectService`
- **WHEN** the save completes
- **THEN** the object MUST be persisted to its magic table only
- **AND** no external search index call (`indexObject`/`bulkIndexObjects`) MUST occur, because none exists

### Requirement: AggregationRunner MUST dispatch to the Postgres backend
When the runner executes a named aggregation it MUST use the Postgres-native fast path against the magic table, falling back to the PHP runner when the Postgres path rejects the input shape. There is no external (Solr/Elasticsearch) dispatch tier.

#### Scenario: Postgres-indexed schema computes via SQL
- **GIVEN** an `ActionItem` schema with a `byStatus` aggregation declared with `metric: count, groupBy: { field: "taskStatus" }`
- **WHEN** the controller calls `GET /api/objects/aggregations/decidesk/action-item/byStatus`
- **THEN** the response carries `backend: "postgres"`
- **AND** the value matches what the PHP runner would compute

### Requirement: Response MUST carry backend attribution
Every aggregation response MUST include a `backend` field with one of `"postgres"` or `"php-fallback"`. Apps and operators use this to debug slow queries.

#### Scenario: Backend attribution surfaced on every response
- **GIVEN** any aggregation request
- **WHEN** the response is rendered
- **THEN** the JSON body MUST include a top-level `backend` field
- **AND** the value MUST be one of `"postgres"` or `"php-fallback"`
- **AND** a fallback path (Postgres rejecting the input shape) MUST attribute `"php-fallback"`, the backend that produced the result

## REMOVED Requirements

### Requirement: Search-backend selection and reindex administration
**Reason**: There is no longer any external search backend to select or reindex; PostgreSQL/Magic-Tables is the sole backend. The admin endpoints (`getSearchBackend`, `updateSearchBackend`, `testSetupHandler`, `reindexSpecificCollection`, and all `SolrSettingsController` methods) and their routes are removed.
**Migration**: Remove search-backend administration from settings UI and clients. The `/api/settings/solr*` and search-backend selection endpoints return HTTP 404. No reindex is needed — search runs live against the database.

### Requirement: Solr collection, configset, and field administration
**Reason**: Solr collections, configsets, and fields no longer exist. `SolrController`, `SolrManagementController`, `SolrOperationsController`, and the `ConfigurationSettingsController` object-collection field methods are removed.
**Migration**: Remove all Solr administration from the UI and clients. The `/api/solr/*` endpoints return HTTP 404.

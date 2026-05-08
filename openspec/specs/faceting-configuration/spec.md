---
status: in-progress
---
# Faceting Configuration


# Faceting Configuration
## Purpose
Provides a comprehensive, backend-agnostic faceting system for OpenRegister that enables per-property facet definition on schema properties, supports multiple facet types (terms, date histogram, range), and delivers configurable facet metadata (title, description, order, aggregation control) through the REST and GraphQL APIs. The system is designed to solve the fundamental conflict between pagination and facet computation by calculating facets on the full filtered dataset independently of pagination, while maintaining backward compatibility with the legacy boolean `facetable` flag and offering intelligent caching at multiple layers (in-memory, APCu/distributed, and database-persistent) to ensure sub-200ms facet response times even on large datasets.

**OpenSpec changes**
- `fix-date-histogram-mariadb` (active) — adds explicit cross-DB correctness requirements for `date_histogram` facets: MariaDB platform branching, ISO-week alignment, correct week-bucket bounds.

## Requirements

### Requirement: Facetable config object support with backward compatibility
The system MUST accept the `facetable` property on schema properties as either a boolean (`true`/`false`) or a configuration object. When a configuration object is provided, it MUST support the fields `aggregated` (boolean), `title` (string), `description` (string), `order` (integer), `type` (string: `terms`, `date_range`, or `date_histogram`), and `options` (object with type-specific settings). All fields in the configuration object MUST be optional with sensible defaults. The `FacetHandler.normalizeFacetConfig()` method (line ~1119) MUST normalize both formats into a standard internal representation. Boolean `true` MUST be treated as `{ aggregated: true, title: null, description: null, order: null }`.

#### Scenario: Property with boolean facetable (backward compatibility)
- **GIVEN** a schema property `status` has `"facetable": true`
- **WHEN** `FacetHandler.normalizeFacetConfig()` processes the property
- **THEN** the property MUST be treated as facetable with `aggregated: true`, `type: null` (auto-detect), `options: null`, and all other config fields as `null`
- **AND** the facet MUST behave identically to the legacy boolean behavior, appearing in aggregated results merged across schemas

#### Scenario: Property with facetable config object including type override
- **GIVEN** a schema property `publicatiedatum` has `"facetable": { "aggregated": false, "title": "Publication Date", "type": "date_histogram", "options": { "interval": "year" } }`
- **WHEN** the facet is computed by `MagicFacetHandler.getSimpleFacets()`
- **THEN** the property MUST be treated as a non-aggregated date histogram facet with yearly interval buckets
- **AND** the `type` field MUST override the auto-detected facet type that `determineFacetTypeFromProperty()` would have chosen

#### Scenario: Property with facetable config object without type (auto-detection)
- **GIVEN** a schema property `aanmaakdatum` has `"facetable": { "title": "My Date Field" }` with property `type: string` and `format: date`
- **WHEN** `FacetHandler.determineFacetTypeFromProperty()` processes the property
- **THEN** the system MUST auto-detect the facet type as `date_histogram` based on the property's format
- **AND** date/datetime properties MUST default to `date_histogram` with `month` interval

#### Scenario: Property with partial config object uses sensible defaults
- **GIVEN** a schema property `type` has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- **WHEN** `normalizeFacetConfig()` processes the value
- **THEN** `description` MUST default to `null` (falling back to auto-generated `"object field: type"`)
- **AND** `order` MUST default to `null` (falling back to auto-incremented position in `transformAggregatedFacet()`)
- **AND** `type` MUST default to `null` (triggering auto-detection via `determineFacetTypeFromProperty()`)

#### Scenario: Property with facetable false is excluded
- **GIVEN** a schema property `internalNotes` has `"facetable": false`
- **WHEN** `getFacetableFieldsFromSchemas()` iterates schema properties
- **THEN** `normalizeFacetConfig()` MUST return `null` for the property
- **AND** the property MUST NOT appear in any facet results or facetable field discovery

### Requirement: Facet type auto-detection from property definitions
The system MUST automatically determine the appropriate facet type based on the schema property's `type` and `format` fields when no explicit `type` is set in the facetable configuration. The `Schema.determineFacetType()` and `SchemaMapper.determineFacetTypeForProperty()` methods MUST implement consistent auto-detection logic. String properties with `format: date` or `format: date-time` MUST use `date_histogram`. Numeric properties (`type: number` or `type: integer`) MUST use `range`. String, boolean, and array properties MUST use `terms`. The `SchemaMapper` MUST additionally auto-detect common facetable field names (`type`, `status`, `category`, `tags`, `priority`, `location`, etc.) and enum properties for automatic faceting even without an explicit `facetable: true` marker.

#### Scenario: Date property auto-detects as date_histogram
- **GIVEN** a property `aanmaakdatum` with `type: string` and `format: date`
- **WHEN** `determineFacetType()` processes the property
- **THEN** the facet type MUST be `date_histogram`
- **AND** `default_interval` MUST be set to `month` with `supported_intervals: ['day', 'week', 'month', 'year']`

#### Scenario: Numeric property auto-detects as range
- **GIVEN** a property `bedrag` with `type: number`
- **WHEN** `Schema.determineFacetType()` processes the property
- **THEN** the facet type MUST be `range`
- **AND** `supports_custom_ranges` MUST be set to `true` in the facet configuration

#### Scenario: Enum property auto-detects as terms with predefined values
- **GIVEN** a property `status` with `type: string` and `enum: ["nieuw", "in_behandeling", "afgehandeld"]`
- **WHEN** `Schema.regenerateFacetsFromProperties()` processes the property
- **THEN** the facet type MUST be `terms`
- **AND** `predefined_values` MUST contain `["nieuw", "in_behandeling", "afgehandeld"]`

#### Scenario: Array property auto-detects as terms
- **GIVEN** a property `tags` with `type: array`
- **WHEN** `determineFacetType()` processes the property
- **THEN** the facet type MUST be `terms`
- **AND** `MariaDbFacetHandler.getTermsFacet()` MUST detect array fields via `fieldContainsArrays()` and create separate buckets per array element

#### Scenario: Auto-detection of common field names without explicit facetable marker
- **GIVEN** a property named `status` with `type: string` and no `facetable` property set
- **WHEN** `SchemaMapper.determineFacetTypeForProperty()` processes the property
- **THEN** it MUST auto-detect `status` as a common facetable field name from the built-in list
- **AND** return `terms` as the facet type

### Requirement: Non-aggregated facet isolation
When a property has `aggregated: false` in its faceting configuration, its facet values MUST NOT be merged with same-named properties from other schemas. `FacetHandler.calculateFacetsWithFallback()` MUST execute separate schema-scoped queries for each non-aggregated field using `MagicMapper.getSimpleFacets()` with `query['@self']['schema'] = schemaId`. Non-aggregated facets MUST appear as distinct entries in the API response with unique keys generated by `generateNonAggregatedFacetKey()`.

#### Scenario: Two schemas with same property name, one non-aggregated
- **GIVEN** schema "Organisatie" (ID 42) has property `type` with `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- **AND** schema "Product" (ID 43) has property `type` with `"facetable": true`
- **WHEN** `FacetHandler.calculateFacetsWithFallback()` calculates facets across both schemas
- **THEN** the response MUST contain two separate facet entries: `organisatie_type` (non-aggregated, from schema 42 only) and `type` (aggregated, from all schemas)
- **AND** the non-aggregated facet MUST only contain bucket values from the "Organisatie" schema

#### Scenario: Non-aggregated facet uses sanitized title as key
- **GIVEN** a property has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- **WHEN** `generateNonAggregatedFacetKey()` creates the facet key
- **THEN** the key MUST be `organisatie_type` (lowercase, spaces replaced with underscores, non-alphanumeric removed)
- **AND** the key MUST NOT collide with other facet keys in the response

#### Scenario: Non-aggregated facet without title falls back to field_schema pattern
- **GIVEN** a property `type` on schema ID 42 has `"facetable": { "aggregated": false }`
- **WHEN** `generateNonAggregatedFacetKey()` creates the facet key with no title set
- **THEN** the key MUST be `type_schema_42`

#### Scenario: Non-aggregated fields removed from aggregated results
- **GIVEN** a property `type` is configured as non-aggregated in schema 42 and not present as aggregated in any other schema
- **WHEN** `calculateFacetsWithFallback()` processes the initial aggregated facets from `getSimpleFacets()`
- **THEN** the `type` field MUST be removed from the aggregated results to prevent duplication
- **AND** only the non-aggregated scoped entry MUST appear

### Requirement: Schema ID in non-aggregated facet response
Non-aggregated facets MUST include the schema ID in the API facet response so the frontend can scope queries to the correct schema. The `buildFacetEntry()` method MUST add a `schema` field when the `$schemaId` parameter is non-null.

#### Scenario: Non-aggregated facet includes schema ID
- **GIVEN** a property `type` on schema ID 42 has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- **WHEN** `buildFacetEntry()` constructs the facet entry with `schemaId: 42`
- **THEN** the facet entry MUST include `"schema": 42`
- **AND** the `queryParameter` field MUST be `"type"` (the actual property name used for filtering)

#### Scenario: Aggregated facet does not include schema ID
- **GIVEN** a property has `"facetable": true` (aggregated by default)
- **WHEN** `buildFacetEntry()` constructs the facet entry with `schemaId: null`
- **THEN** the facet entry MUST NOT include a `schema` field

#### Scenario: Frontend uses schema ID to scope filter queries
- **GIVEN** the facet response contains `{ "schema": 42, "queryParameter": "type" }` for a non-aggregated facet
- **WHEN** the user selects bucket value `"leverancier"` in that facet
- **THEN** the subsequent search request MUST include both `type=leverancier` and `@self[schema]=42` to scope the filter

### Requirement: Custom facet title, description, and order in response
When a faceting configuration specifies `title`, `description`, or `order`, the facet response MUST use those values instead of auto-generated ones. `transformNonAggregatedFacet()` and `transformAggregatedFacet()` MUST apply config overrides. Facets with explicit `order` values MUST be placed before facets with auto-incremented orders.

#### Scenario: Config title overrides auto-generated title
- **GIVEN** a property `cloudDienstverleningsmodel` has `"facetable": { "title": "Cloud Model" }`
- **WHEN** `transformAggregatedFacet()` builds the facet entry
- **THEN** the facet entry's `title` field MUST be `"Cloud Model"`
- **AND** NOT `"Cloud Dienstverleningsmodel"` (the auto-generated title from `formatFieldTitle()` which converts camelCase to Title Case)

#### Scenario: No config title falls back to camelCase-derived title
- **GIVEN** a property `cloudDienstverleningsmodel` has `"facetable": true`
- **WHEN** `formatFieldTitle()` generates the title
- **THEN** the facet entry's `title` field MUST be `"Cloud Dienstverleningsmodel"` (camelCase split into separate words with first letter capitalized)

#### Scenario: Config description overrides auto-generated
- **GIVEN** a property has `"facetable": { "description": "Filter by organisation type" }`
- **WHEN** the facet response is built
- **THEN** the facet entry's `description` field MUST be `"Filter by organisation type"`
- **AND** NOT `"object field: type"` (the default description pattern)

#### Scenario: Config order overrides auto-increment
- **GIVEN** property A has `"facetable": { "order": 10 }` and property B has `"facetable": { "order": 1 }`
- **WHEN** `transformAggregatedFacet()` processes both properties
- **THEN** property B MUST have `order: 1` and property A MUST have `order: 10`
- **AND** facets with explicit orders MUST be placed before facets with auto-incremented orders

#### Scenario: No config order falls back to auto-increment
- **GIVEN** a property has `"facetable": true`
- **WHEN** the facet response is built
- **THEN** the `order` field MUST be auto-incremented based on processing order (current `$order++` counter in transform methods)

### Requirement: Facet counts computed independently of pagination
Facets MUST be calculated on the complete filtered dataset, ignoring pagination parameters (`_limit`, `_offset`, `_page`). `FacetHandler.getFacetsForObjects()` MUST strip pagination parameters from the query before passing to the facet calculation pipeline. This ensures users always see accurate facet counts regardless of the current page or page size.

#### Scenario: Facet counts reflect full dataset not current page
- **GIVEN** 150 objects match the current filters with `_limit=30&_page=1`
- **WHEN** `getFacetsForObjects()` calculates facets
- **THEN** it MUST remove `_limit`, `_offset`, `_page`, and `_facetable` from `$facetQuery` before calling `calculateFacetsWithFallback()`
- **AND** facet bucket counts MUST reflect all 150 matching objects, not just the 30 on the current page

#### Scenario: Changing page does not alter facet counts
- **GIVEN** a user navigates from page 1 to page 3
- **WHEN** the facets are recalculated
- **THEN** the facet counts MUST remain identical because the underlying filters have not changed
- **AND** the response MUST include `performance_metadata.strategy` indicating the facet calculation method used

#### Scenario: Facet counts change when filters change
- **GIVEN** a user adds filter `?status=nieuw` reducing results from 150 to 30
- **WHEN** facets are recalculated
- **THEN** all other facet bucket counts MUST reflect only the 30 filtered objects
- **AND** the no-fallback policy MUST apply: empty facets with restrictive filters return empty, NOT collection-wide counts (fix for issue #453)

### Requirement: Metadata facets via @self namespace
The system MUST provide built-in facets for object metadata fields through the `@self` namespace: `register`, `schema`, `owner`, `organisation`, `created`, and `updated`. These metadata facets MUST be defined by `getDefaultMetadataFacets()` and rendered by `transformMetadataFacets()` with definitions from `getMetadataDefinitions()`. Metadata facets MUST use query parameter format `@self[field]` (e.g., `@self[schema]`) and appear with underscore-prefixed names (e.g., `_schema`) in the response.

#### Scenario: Schema metadata facet shows type distribution
- **GIVEN** a register contains objects across 3 schemas: Organisatie (50), Product (30), Dienst (20)
- **WHEN** the `@self.schema` facet is computed
- **THEN** the facet MUST appear as `_schema` with `queryParameter: "@self[schema]"`
- **AND** buckets MUST show `[{ value: 1, count: 50, label: "1" }, { value: 2, count: 30, label: "2" }, { value: 3, count: 20, label: "3" }]`

#### Scenario: Created metadata facet uses date_histogram
- **GIVEN** the `created` metadata definition specifies `type: date_histogram, interval: month`
- **WHEN** `MagicFacetHandler.getDateHistogramFacet()` processes the `_created` column
- **THEN** buckets MUST be grouped by month
- **AND** the facet entry MUST have `data_type: datetime` and `index_type: pdate`

#### Scenario: Disabled metadata facets excluded from response
- **GIVEN** the `register` metadata definition has `enabled: false`
- **WHEN** metadata facets are rendered
- **THEN** the `_register` facet MUST still appear in the response with `enabled: false` so the frontend can decide whether to display it

### Requirement: Backend-agnostic faceting across PostgreSQL and Solr
The faceting system MUST operate transparently across database backends (PostgreSQL/MariaDB) and external search engines (Solr, Elasticsearch). `MagicFacetHandler` MUST handle SQL-based faceting with per-column `GROUP BY` queries on dynamic magic tables. `SolrFacetProcessor` MUST handle Solr-native faceting using `facet.field` parameters. Both backends MUST produce output that `FacetHandler.transformFacetsToStandardFormat()` normalizes into the same API response format with `name`, `type`, `title`, `description`, `queryParameter`, `order`, `data.buckets[]` structure.

#### Scenario: PostgreSQL terms facet via MagicFacetHandler
- **GIVEN** PostgreSQL is the active backend with magic table `or_r1_s1` containing column `status`
- **WHEN** `MagicFacetHandler.getTermsFacet()` is called for `status`
- **THEN** it MUST execute `SELECT status AS field_value, COUNT(*) AS doc_count FROM oc_or_r1_s1 WHERE status IS NOT NULL GROUP BY status ORDER BY doc_count DESC LIMIT 10000`
- **AND** return `{ type: 'terms', buckets: [{ key: 'nieuw', results: 30 }, ...] }`

#### Scenario: Solr terms facet via SolrFacetProcessor
- **GIVEN** Solr is the active search backend with indexed field `status_s`
- **WHEN** `SolrFacetProcessor.buildFacetQuery()` builds the facet request
- **THEN** it MUST produce `{ facet: 'true', 'facet.field': ['status_s'], 'facet.limit': 100 }`
- **AND** `processFacetResponse()` MUST convert Solr's alternating `[value, count, value, count, ...]` format into structured buckets

#### Scenario: MariaDB JSON faceting via MariaDbFacetHandler
- **GIVEN** MariaDB is the database and faceting is performed on the legacy `openregister_objects` table
- **WHEN** `MariaDbFacetHandler.getTermsFacet()` processes a JSON field `type`
- **THEN** it MUST use `JSON_UNQUOTE(JSON_EXTRACT(object, '$.type'))` for value extraction
- **AND** array-typed fields MUST be detected via `fieldContainsArrays()` and faceted per-element

#### Scenario: UNION ALL faceting across multiple schemas
- **GIVEN** a query spans schemas 1 (table `or_r1_s1`) and 2 (table `or_r1_s2`), both with column `status`
- **WHEN** `MagicFacetHandler.getSimpleFacetsUnion()` computes facets
- **THEN** it MUST build a single UNION ALL query combining `SELECT status, COUNT(*) FROM oc_or_r1_s1 GROUP BY status` with `SELECT status, COUNT(*) FROM oc_or_r1_s2 GROUP BY status`
- **AND** bucket counts from both tables MUST be merged into aggregated totals

### Requirement: Multi-layered facet caching
The system MUST implement caching at three levels to minimize redundant computation. (1) **Response cache**: `FacetHandler` MUST cache complete facet responses in distributed/local memory (`ICacheFactory`) with 1-hour TTL, keyed by RBAC-aware hashes including user ID, organisation, filters, and facet config. (2) **Schema facet cache**: `FacetCacheHandler` MUST persistently cache facet configurations per schema in the `openregister_schema_facet_cache` database table with configurable TTL (default 30 minutes, max 8 hours). (3) **In-memory label cache**: `MagicFacetHandler` MUST cache UUID-to-label mappings per request and in a distributed label cache (`openregister_facet_labels`) with 24-hour TTL. Cache MUST be invalidated when schemas are updated via `FacetCacheHandler.invalidateForSchemaChange()`.

#### Scenario: Response cache hit returns cached facets instantly
- **GIVEN** a facet query was executed 5 minutes ago for the same user, organisation, and filters
- **WHEN** `getFacetsForObjects()` generates the same RBAC-aware cache key via `generateFacetCacheKey()`
- **THEN** the cached response MUST be returned with `performance_metadata.cache_hit: true`
- **AND** no database queries MUST be executed for facet computation

#### Scenario: Schema change invalidates all related caches
- **GIVEN** schema ID 42 is updated (property added or facetable config changed)
- **WHEN** `FacetCacheHandler.invalidateForSchemaChange(42, 'update')` is called
- **THEN** all database cache entries for schema 42 MUST be deleted from `openregister_schema_facet_cache`
- **AND** all in-memory cache entries containing `_42` MUST be cleared
- **AND** the distributed `openregister_facets` and `openregister_facet_labels` caches MUST be fully cleared via `clearDistributedFacetCaches()`

#### Scenario: RBAC-aware cache keys prevent cross-user data leakage
- **GIVEN** user `admin` and user `medewerker` query the same facets
- **WHEN** `generateFacetCacheKey()` generates cache keys
- **THEN** the keys MUST differ because they include `user: 'admin'` vs `user: 'medewerker'`
- **AND** organisation context MUST also be included so multi-tenant facet results are isolated

#### Scenario: Cache statistics available for monitoring
- **GIVEN** an administrator requests facet cache statistics
- **WHEN** `FacetCacheHandler.getCacheStatistics()` is called
- **THEN** it MUST return `total_entries`, `by_type` breakdown, `memory_cache_size`, `cache_table`, `query_time`, and `timestamp`

### Requirement: Facet discovery via _facetable parameter
The API MUST support a `_facetable=true` query parameter that returns the list of all facetable fields for the current query context (registers/schemas) without computing actual facet counts. `FacetHandler.getFacetableFields()` MUST use pre-computed schema facet configurations from `getFacetableFieldsFromSchemas()` for performance. The response MUST include `@self` metadata facets and `object_fields` with type, title, and data_type information.

#### Scenario: Discover facetable fields for a single schema
- **GIVEN** schema `meldingen` has properties `status` (facetable: true, type: string), `aanmaakdatum` (facetable: true, type: string, format: date), and `description` (not facetable)
- **WHEN** the API receives `?_facetable=true&schema=1`
- **THEN** the response MUST include `object_fields: { status: { type: 'terms' }, aanmaakdatum: { type: 'date_histogram', default_interval: 'month' } }`
- **AND** `description` MUST NOT appear because it is not facetable

#### Scenario: Discover facetable fields across multiple schemas
- **GIVEN** schemas 1 and 2 each have different facetable properties
- **WHEN** the API receives `?_facetable=true&_schemas[]=1&_schemas[]=2`
- **THEN** the response MUST include the union of facetable fields from both schemas
- **AND** non-aggregated fields MUST be tracked separately in `non_aggregated_fields` array

#### Scenario: Performance of facetable discovery
- **GIVEN** a large system with 50 schemas each having 20+ properties
- **WHEN** `getFacetableFields()` is called
- **THEN** it MUST complete within 50ms by using pre-computed schema properties
- **AND** execution time MUST be logged in debug output

### Requirement: Facet request configuration via _facets parameter
The `_facets` query parameter MUST control which facets are computed. It MUST accept: (1) the string `extend` to compute all facets defined in schema configurations, (2) a comma-separated list of field names to compute specific facets, (3) an array of field names (`_facets[]=status&_facets[]=type`). `MagicFacetHandler.expandFacetConfig()` MUST resolve shorthand formats into full facet configuration objects by reading from the schema's `facets` property. For multi-schema queries, `expandFacetConfigFromAllSchemas()` MUST merge facet configs from all participating schemas.

#### Scenario: _facets=extend computes all schema-defined facets
- **GIVEN** schema `meldingen` has facets configuration with `@self: { schema: { type: terms } }` and `object_fields: { status: { type: terms } }`
- **WHEN** the API receives `?_facets=extend`
- **THEN** `expandFacetConfig()` MUST resolve `extend` into the full facet configuration from the schema
- **AND** both metadata and object field facets MUST be computed

#### Scenario: _facets array requests specific facets only
- **GIVEN** schema `meldingen` has 5 facetable properties
- **WHEN** the API receives `?_facets[]=status&_facets[]=wijk`
- **THEN** only `status` and `wijk` facets MUST be computed
- **AND** other facetable properties MUST be skipped for performance

#### Scenario: Multi-schema facet config merging
- **GIVEN** schema 1 has facetable property `status` and schema 2 has facetable property `categorie`
- **WHEN** `expandFacetConfigFromAllSchemas()` merges configs for a multi-schema query
- **THEN** the merged config MUST include both `status` and `categorie` as facet fields
- **AND** `@self` metadata facets MUST be included once (not duplicated)

### Requirement: Facet response standardized format
The API MUST return facets in a standardized format regardless of backend. Each facet entry MUST include: `name` (field identifier), `type` (terms/date_histogram/range), `title` (human-readable label), `description`, `data_type` (string/integer/datetime/number), `index_field` (Solr field name), `index_type` (Solr type), `queryParameter` (URL filter param name), `source` (metadata/object), `show_count` (boolean, always true), `enabled` (boolean), `order` (integer), and `data` object containing `type`, `total_count`, and `buckets[]` array where each bucket has `value`, `count`, and `label`.

#### Scenario: Terms facet response format
- **GIVEN** property `status` has 3 distinct values: nieuw (30), in_behandeling (45), afgehandeld (25)
- **WHEN** `buildFacetEntry()` constructs the response
- **THEN** the entry MUST be:
  ```json
  {
    "name": "status",
    "type": "terms",
    "title": "Status",
    "description": "object field: status",
    "data_type": "string",
    "queryParameter": "status",
    "source": "object",
    "order": 3,
    "data": {
      "type": "terms",
      "total_count": 3,
      "buckets": [
        { "value": "in_behandeling", "count": 45, "label": "in_behandeling" },
        { "value": "nieuw", "count": 30, "label": "nieuw" },
        { "value": "afgehandeld", "count": 25, "label": "afgehandeld" }
      ]
    }
  }
  ```

#### Scenario: Bucket key/results mapped to value/count
- **GIVEN** `MagicFacetHandler` returns buckets with `{ key: 'nieuw', results: 30 }`
- **WHEN** `buildFacetEntry()` transforms the buckets
- **THEN** each bucket MUST be mapped to `{ value: 'nieuw', count: 30, label: 'nieuw' }`

#### Scenario: Performance metadata included in response
- **GIVEN** facets are computed with the `filtered` strategy
- **WHEN** the response is returned
- **THEN** it MUST include `performance_metadata: { strategy: 'filtered', fallback_used: false, total_facet_results: N, has_restrictive_filters: bool, total_execution_time_ms: X }`
- **AND** per-facet timing MUST be included in `facet_db_ms` when available from `MagicFacetHandler._metrics`

### Requirement: Faceting MUST be available through GraphQL connection types
GraphQL list queries MUST expose facets and facetable field discovery through the connection type, reusing the existing `FacetHandler`. `GraphQLResolver` MUST delegate facet computation to `FacetHandler.getFacetsForObjects()` with the same query structure used by the REST API.

#### Scenario: Request facets in a GraphQL list query
- **GIVEN** a GraphQL schema exposes `meldingen` as a queryable type
- **WHEN** a client queries `meldingen(facets: ["status", "priority"]) { edges { node { title } } facets facetable }`
- **THEN** the `facets` field MUST contain value counts per requested field matching `FacetHandler` output
- **AND** facets MUST be calculated on the full filtered dataset independent of pagination (`first`/`offset`/`after`)

#### Scenario: Discover facetable fields via GraphQL
- **WHEN** a client queries `meldingen { facetable }`
- **THEN** all property names with `facetable` configuration (boolean `true` or config object) MUST be listed

#### Scenario: Non-aggregated facets include schema context in GraphQL
- **GIVEN** a schema property has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- **WHEN** the facets are returned through GraphQL
- **THEN** the facet entry MUST include `schema` ID and `queryParameter` fields matching the REST response format

#### Scenario: Facets with date histogram type in GraphQL
- **GIVEN** a date property has `"facetable": { "type": "date_histogram", "options": { "interval": "month" } }`
- **WHEN** the facet is requested through GraphQL
- **THEN** the facet buckets MUST be grouped by month intervals matching the REST API behavior

### Requirement: Schema editor faceting configuration UI
The `EditSchemaProperty.vue` modal MUST allow configuring faceting options when the facetable toggle is enabled. The config fields MUST be shown conditionally. For date/datetime properties, additional type-specific fields (facet type selector, interval options) MUST be available. Saving with all defaults MUST produce `"facetable": true` (not a config object) for backward compatibility.

#### Scenario: Facetable toggle enables config fields
- **WHEN** a user is editing a schema property in the EditSchemaProperty modal
- **AND** the user enables the "Facetable" toggle
- **THEN** additional fields MUST appear: "Aggregated" toggle (default: checked), "Facet Title", "Facet Description", "Facet Order"
- **AND** if the property has `format: date` or `format: date-time`, a "Facet Type" dropdown MUST also appear with options `auto`, `terms`, `date_histogram`, `date_range`

#### Scenario: Facetable toggle disabled hides config fields
- **WHEN** the "Facetable" toggle is unchecked
- **THEN** the faceting config fields MUST NOT be visible

#### Scenario: Saving property with faceting config including type
- **WHEN** a user has set facetable to enabled, type to "date_histogram", and interval to "year"
- **THEN** the property MUST be saved with `"facetable": { "type": "date_histogram", "options": { "interval": "year" } }`
- **AND** any other config values (title, description, order, aggregated) MUST be included if set

#### Scenario: Saving property with default faceting config produces boolean
- **WHEN** a user has set facetable to enabled and left all config fields at defaults (aggregated checked, title empty, description empty, order empty, type auto)
- **THEN** the property MUST be saved with `"facetable": true` (not a config object) for backward compatibility

### Requirement: Frontend _schema parameter for non-aggregated facets
The frontend search page MUST add `_schema=<schemaId>` (or `@self[schema]=<schemaId>`) to the query parameters when a user selects a non-aggregated facet. This ensures the filter is scoped to the correct schema.

#### Scenario: Selecting a non-aggregated facet adds _schema
- **GIVEN** the facet response contains a facet with `"schema": 42` and `"queryParameter": "type"`
- **WHEN** the user checks a bucket value `"leverancier"` in that facet
- **THEN** the URL query parameters MUST include both `type=leverancier` and `_schema=42` (or `@self[schema]=42`)

#### Scenario: Deselecting a non-aggregated facet removes _schema
- **GIVEN** the query currently includes `type=leverancier&_schema=42`
- **WHEN** the user unchecks the `"leverancier"` bucket
- **THEN** both `type=leverancier` and `_schema=42` MUST be removed from the query parameters

#### Scenario: Selecting an aggregated facet does not add _schema
- **GIVEN** the facet response contains a facet without a `schema` field
- **WHEN** the user checks a bucket value
- **THEN** the URL query parameters MUST NOT include `_schema`

### Requirement: Facet performance optimization via HyperFacetHandler
The system MUST provide an advanced performance tier via `HyperFacetHandler` (`lib/Db/ObjectHandlers/HyperFacetHandler.php`) that implements multi-layered caching (result cache 5min, fragment cache 15min, cardinality cache 1hr, schema facet cache 24hr), HyperLogLog cardinality estimation for large datasets, random sampling (5-10%) with statistical extrapolation, parallel query execution via ReactPHP promises, and adaptive exact/approximate switching based on dataset size. Simple facet requests SHOULD complete in under 50ms, complex requests under 200ms, and popular combinations under 10ms from cache.

#### Scenario: Small dataset uses exact computation
- **GIVEN** a schema with fewer than 10,000 objects
- **WHEN** facets are requested
- **THEN** `HyperFacetHandler` MUST use exact `GROUP BY` queries without sampling
- **AND** results MUST be accurate to the individual count

#### Scenario: Large dataset uses sampling with confidence intervals
- **GIVEN** a schema with more than 100,000 objects
- **WHEN** facets are requested and no cache is available
- **THEN** `HyperFacetHandler` MAY use 5-10% random sampling with statistical extrapolation
- **AND** the response MUST include confidence interval metadata so the frontend can indicate approximate counts

#### Scenario: Cardinality estimation optimizes query strategy
- **GIVEN** a property `status` with low cardinality (5 distinct values) and a property `name` with high cardinality (10,000+ distinct values)
- **WHEN** facets are requested for both
- **THEN** `status` MUST use exact computation (low cost)
- **AND** `name` MUST use cardinality-aware optimization (e.g., sampling or limiting buckets)

### Requirement: Facet label resolution for entity references
When facet bucket values contain UUIDs that reference other register objects (e.g., organisation references), the system MUST resolve those UUIDs to human-readable labels. `MagicFacetHandler` MUST use `CacheHandler` for UUID-to-name resolution and cache resolved labels in both in-memory (`uuidLabelCache`, `fieldLabelCache`) and distributed (`openregister_facet_labels`) caches with 24-hour TTL. Cache statistics MUST be tracked via `cacheStats` for performance monitoring.

#### Scenario: UUID bucket values resolved to labels
- **GIVEN** a facet on property `organisatie` returns bucket `{ key: 'uuid-org-123', results: 50 }`
- **AND** the UUID `uuid-org-123` maps to object with `_name: "Gemeente Tilburg"`
- **WHEN** `MagicFacetHandler` resolves labels
- **THEN** the bucket MUST be returned as `{ key: 'uuid-org-123', results: 50, label: 'Gemeente Tilburg' }`

#### Scenario: Label cache prevents repeated lookups
- **GIVEN** the same UUID appears in multiple facet queries within a request
- **WHEN** the label is looked up the second time
- **THEN** it MUST be served from `uuidLabelCache` or `fieldLabelCache` without a database query
- **AND** `cacheStats.field_cache_hits` MUST increment

#### Scenario: Distributed label cache persists across requests
- **GIVEN** a UUID was resolved in a previous request
- **WHEN** a new request queries facets containing the same UUID
- **THEN** the label MUST be served from the distributed `openregister_facet_labels` cache
- **AND** `cacheStats.distributed_cache_hits` MUST increment

## Current Implementation Status
- **Fully implemented -- facetable config object support**: `FacetHandler.normalizeFacetConfig()` (line ~1119) handles both boolean and config object formats with `aggregated`, `title`, `description`, `order` fields. Type and options fields supported.
- **Fully implemented -- facet type auto-detection**: `Schema.determineFacetType()` (line ~1767), `SchemaMapper.determineFacetTypeForProperty()` (line ~1384), and `FacetHandler.determineFacetTypeFromProperty()` (line ~1250) implement consistent type detection for terms, date_histogram, and range types.
- **Fully implemented -- non-aggregated facet isolation**: `FacetHandler.calculateFacetsWithFallback()` (line ~334) executes separate schema-scoped queries for non-aggregated fields and `generateNonAggregatedFacetKey()` (line ~458) produces unique keys.
- **Fully implemented -- schema ID in non-aggregated facet response**: `buildFacetEntry()` (line ~791) adds `schema` field when `$schemaId` is non-null.
- **Fully implemented -- custom title/description/order**: `transformNonAggregatedFacet()` (line ~653) and `transformAggregatedFacet()` (line ~721) apply config overrides.
- **Fully implemented -- pagination-independent faceting**: `getFacetsForObjects()` (line ~155) strips `_limit`, `_offset`, `_page`, `_facetable` before facet computation.
- **Fully implemented -- metadata facets**: `getDefaultMetadataFacets()` (line ~1232) defines `@self` facets; `transformMetadataFacets()` (line ~611) renders them; `getMetadataDefinitions()` (line ~548) provides titles/types.
- **Fully implemented -- multi-backend faceting**: `MagicFacetHandler` (SQL), `SolrFacetProcessor` (Solr), `MariaDbFacetHandler` (MariaDB JSON), `HyperFacetHandler` (advanced performance).
- **Fully implemented -- multi-layered caching**: Response cache in `FacetHandler` (distributed IMemcache, 1hr TTL), schema facet cache in `FacetCacheHandler` (database, 30min-8hr TTL), label cache in `MagicFacetHandler` (distributed + in-memory, 24hr TTL).
- **Fully implemented -- UNION faceting**: `MagicFacetHandler.getSimpleFacetsUnion()` combines facets across multiple schema tables in single queries.
- **Fully implemented -- label resolution**: `MagicFacetHandler` resolves UUID references to human-readable labels via `CacheHandler` with multi-level caching.
- **Partially implemented -- schema editor UI**: The `EditSchemaProperty.vue` modal needs verification for full support of `type` and `options` config fields.
- **Not yet verified -- frontend `_schema` parameter**: The `_schema` query parameter handling for non-aggregated facets in frontend applications needs verification.

## Standards & References
- JSON Schema specification for property-level metadata extensions
- Apache Solr faceting API (`facet.field`, `facet.range`, `facet.pivot`)
- Elasticsearch aggregations API (terms, date_histogram, range aggregations)
- OpenRegister internal faceting API conventions (documented in `docs/Features/search.md`)
- Nextcloud `ICacheFactory` / `IMemcache` for distributed caching integration
- Cross-reference: `zoeken-filteren` spec (search integration, faceted navigation, backend-agnostic architecture)
- Cross-reference: `built-in-dashboards` spec (dashboards consume facet aggregation data via `DashboardService`)

## Specificity Assessment
- **Highly specific and implementable as-is**: The spec provides 15 requirements with 50+ scenarios covering facet configuration, type detection, aggregation control, caching, multi-backend support, API format, GraphQL integration, UI configuration, and performance optimization.
- **Well-defined edge cases**: Covers partial config objects, default values, backward-compatible boolean handling, cross-schema aggregation vs isolation, cache invalidation chains.
- **Open question**: How should `date_histogram` and `date_range` facets interact with the Solr backend? The spec defines behavior at the `FacetHandler` and `MagicFacetHandler` level but Solr facet range configuration (`facet.range.start`, `facet.range.end`, `facet.range.gap`) is not yet specified.
- **Open question**: What happens when multiple non-aggregated facets from different schemas are active simultaneously? The `_schema` parameter is singular, which could conflict. A possible solution is array syntax `_schema[]=42&_schema[]=43` or per-facet scoping.

## Nextcloud Integration Analysis

- **Status**: Already implemented in OpenRegister
- **Existing Implementation**: `FacetHandler` supports both boolean and config object facetable configurations with `normalizeFacetConfig()`. Non-aggregated facet isolation via `calculateFacetsWithFallback()` with schema-scoped queries. Custom title/description/order via `transformNonAggregatedFacet()` and `transformAggregatedFacet()`. Facet type support (`terms`, `date_range`, `date_histogram`) with auto-detection. Multiple SQL-level handlers (`MagicFacetHandler`, `MariaDbFacetHandler`, `HyperFacetHandler`, `OptimizedFacetHandler`, `MetaDataFacetHandler`). UNION ALL faceting via `getSimpleFacetsUnion()`.
- **Nextcloud Core Integration**: Facet results exposed through the search API which integrates with NC's unified search via `IFilteringProvider`. Uses APCu/distributed caching (`ICacheFactory`, `IMemcache`) for response caching (1hr TTL) and label caching (24hr TTL). Persistent facet cache via `FacetCacheHandler` using NC's `IDBConnection`. Schema change invalidation integrated with NC cache clearing. Solr faceting via `SolrFacetProcessor` for indexed backends. The faceting configuration is stored as JSON metadata on schema properties within NC's database layer.
- **Recommendation**: Mark as implemented. The faceting system is well-integrated with NC's caching infrastructure across three tiers (memory, distributed, database). Priority improvements: (1) verify schema editor UI support for `type` and `options` fields, (2) verify frontend `_schema` parameter handling for non-aggregated facets, (3) specify Solr range facet configuration for `date_range` type.

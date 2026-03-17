# Faceting Configuration Specification

## Purpose
Extends the OpenRegister faceting system to support per-property configuration (title, description, order, aggregation control) while maintaining backward compatibility with the existing boolean `facetable` flag. Enables non-aggregated facets that scope queries to a specific schema, eliminating the need for data duplication caused by property renaming.

## Requirements

### Requirement: Facetable config object support
The system MUST accept `facetable` as either a boolean (`true`/`false`) or a configuration object on schema properties. The configuration object MUST support the following fields:
- `aggregated` (boolean) — whether the facet is merged with same-named properties from other schemas
- `title` (string) — custom display title for the facet
- `description` (string) — custom description for the facet
- `order` (integer) — numeric display order (lower = shown first)
- `type` (string) — facet type override: `terms`, `date_range`, or `date_histogram`. When omitted, the system auto-detects based on property type/format.
- `options` (object) — type-specific configuration options. Structure depends on `type`. When omitted, sensible defaults are used.

All fields in the configuration object MUST be optional with sensible defaults.

#### Scenario: Property with boolean facetable (backward compatibility)
- **WHEN** a schema property has `"facetable": true`
- **THEN** the property MUST be treated as facetable with `aggregated: true`, `type: null` (auto-detect), `options: null`, and all other config fields as `null`
- **AND** the facet MUST behave identically to current behavior

#### Scenario: Property with facetable config object including type
- **WHEN** a schema property has `"facetable": { "aggregated": false, "title": "Publication Date", "type": "date_histogram", "options": { "interval": "year" } }`
- **THEN** the property MUST be treated as facetable with the specified type and options
- **AND** the `type` field MUST override the auto-detected facet type

#### Scenario: Property with facetable config object without type
- **WHEN** a schema property has `"facetable": { "title": "My Date Field" }` (no type specified)
- **THEN** the system MUST auto-detect the facet type based on the property's type and format
- **AND** date/datetime properties MUST default to `date_histogram` with `month` interval

#### Scenario: Property with facetable config object
- **WHEN** a schema property has `"facetable": { "aggregated": false, "title": "Organisatie Type", "description": "Filter by organisation type", "order": 2 }`
- **THEN** the property MUST be treated as facetable with the specified configuration values

#### Scenario: Property with partial config object
- **WHEN** a schema property has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- **THEN** `description` MUST default to `null` (falling back to auto-generated)
- **AND** `order` MUST default to `null` (falling back to auto-incremented)
- **AND** `type` MUST default to `null` (auto-detected)
- **AND** `options` MUST default to `null` (type-specific defaults)

#### Scenario: Property with facetable false
- **WHEN** a schema property has `"facetable": false`
- **THEN** the property MUST NOT appear in the facet results

### Requirement: Non-aggregated facet isolation
When a property has `aggregated: false` in its faceting config, its facet values MUST NOT be merged with same-named properties from other schemas. The facet MUST appear as a distinct entry in the API response.

#### Scenario: Two schemas with same property name, one non-aggregated
- GIVEN schema "Organisatie" has property `type` with `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- AND schema "Product" has property `type` with `"facetable": true`
- WHEN the FacetHandler calculates facets across both schemas
- THEN the response MUST contain two separate facet entries: one for "Organisatie Type" (non-aggregated) and one for the aggregated `type` facet
- AND the non-aggregated facet MUST only contain bucket values from the "Organisatie" schema

#### Scenario: Non-aggregated facet uses custom title as key
- GIVEN a property has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- WHEN the facet response is built
- THEN the facet key in the response object MUST be a unique key derived from the schema context (not the raw property name) to avoid key collisions

### Requirement: Schema ID in non-aggregated facet response
Non-aggregated facets MUST include the schema ID in the API facet response so the frontend can scope queries.

#### Scenario: Non-aggregated facet includes schema ID
- GIVEN a property `type` on schema ID `42` has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- WHEN the facet response is returned
- THEN the facet entry MUST include a `"schema": 42` field
- AND the `queryParameter` field MUST remain `"type"` (the actual property name for filtering)

#### Scenario: Aggregated facet does not include schema ID
- GIVEN a property has `"facetable": true` (aggregated by default)
- WHEN the facet response is returned
- THEN the facet entry MUST NOT include a `schema` field

### Requirement: Custom facet title in response
When a faceting config specifies a `title`, the facet response MUST use that title instead of the auto-generated one.

#### Scenario: Config title overrides auto-generated title
- GIVEN a property `type` has `"facetable": { "title": "Organisatie Type" }`
- WHEN the facet response is built
- THEN the facet entry's `title` field MUST be `"Organisatie Type"`
- AND NOT `"Type"` (the auto-generated title from the field name)

#### Scenario: No config title falls back to auto-generated
- GIVEN a property `cloudDienstverleningsmodel` has `"facetable": true`
- WHEN the facet response is built
- THEN the facet entry's `title` field MUST be `"Cloud Dienstverleningsmodel"` (auto-generated from camelCase)

### Requirement: Custom facet description in response
When a faceting config specifies a `description`, the facet response MUST use that description.

#### Scenario: Config description overrides auto-generated
- GIVEN a property has `"facetable": { "description": "Filter by organisation type" }`
- WHEN the facet response is built
- THEN the facet entry's `description` field MUST be `"Filter by organisation type"`

### Requirement: Custom facet order in response
When a faceting config specifies an `order`, the facet response MUST use that value for the `order` field. Lower numbers MUST appear first.

#### Scenario: Config order overrides auto-increment
- GIVEN property A has `"facetable": { "order": 10 }` and property B has `"facetable": { "order": 1 }`
- WHEN the facet response is built
- THEN property B MUST have `order: 1` and property A MUST have `order: 10`
- AND facets with explicit orders MUST be placed before facets with auto-incremented orders

#### Scenario: No config order falls back to auto-increment
- GIVEN a property has `"facetable": true`
- WHEN the facet response is built
- THEN the `order` field MUST be auto-incremented based on processing order (current behavior)

### Requirement: Schema editor faceting configuration UI
The `EditSchemaProperty.vue` modal MUST allow configuring faceting options when the facetable toggle is enabled. The config fields MUST be shown conditionally. For date/datetime properties, additional type-specific fields MUST be available.

#### Scenario: Facetable toggle enables config fields
- **WHEN** a user is editing a schema property in the EditSchemaProperty modal
- **AND** the user enables the "Facetable" toggle
- **THEN** additional fields MUST appear: "Aggregated" toggle (default: checked), "Facet Title", "Facet Description", "Facet Order"
- **AND** if the property has `format: date` or `format: date-time`, a "Facet Type" dropdown MUST also appear

#### Scenario: Facetable toggle disabled hides config fields
- **WHEN** the "Facetable" toggle is unchecked
- **THEN** the faceting config fields MUST NOT be visible

#### Scenario: Saving property with faceting config including type
- **WHEN** a user has set facetable to enabled, type to "date_histogram", and interval to "year"
- **THEN** the property MUST be saved with `"facetable": { "type": "date_histogram", "options": { "interval": "year" } }`
- **AND** any other config values (title, description, order, aggregated) MUST be included if set

#### Scenario: Saving property with faceting config
- **WHEN** a user has set facetable to enabled, aggregated to unchecked, and title to "Organisatie Type"
- **THEN** the property MUST be saved with `"facetable": { "aggregated": false, "title": "Organisatie Type", "description": null, "order": null }`

#### Scenario: Saving property with default faceting config
- **WHEN** a user has set facetable to enabled and left all config fields at defaults (aggregated checked, title empty, description empty, order empty, type auto)
- **THEN** the property MUST be saved with `"facetable": true` (not a config object) for backward compatibility

### Requirement: Frontend _schema parameter for non-aggregated facets
The tilburg-woo-ui search page MUST add `_schema=<schemaId>` to the query parameters when a user selects a non-aggregated facet.

#### Scenario: Selecting a non-aggregated facet adds _schema
- GIVEN the facet response contains a facet with `"schema": 42` and `"queryParameter": "type"`
- WHEN the user checks a bucket value `"leverancier"` in that facet
- THEN the URL query parameters MUST include both `type=leverancier` and `_schema=42`

#### Scenario: Deselecting a non-aggregated facet removes _schema
- GIVEN the query currently includes `type=leverancier&_schema=42`
- WHEN the user unchecks the `"leverancier"` bucket
- THEN both `type=leverancier` and `_schema=42` MUST be removed from the query parameters

#### Scenario: Selecting an aggregated facet does not add _schema
- GIVEN the facet response contains a facet without a `schema` field
- WHEN the user checks a bucket value
- THEN the URL query parameters MUST NOT include `_schema`

### Requirement: Frontend facet ordering by order field
The tilburg-woo-ui facet sidebar MUST sort facets by their `order` field when present, with lower numbers appearing first.

#### Scenario: Facets sorted by order field
- GIVEN the facet response contains facets with `order: 1`, `order: 5`, and `order: 10`
- WHEN the facets are rendered in the sidebar
- THEN they MUST appear in order: 1, 5, 10 (ascending)

### Current Implementation Status
- **Fully implemented — facetable config object support**: `FacetHandler` (`lib/Service/Object/FacetHandler.php`) supports both boolean `true`/`false` and config objects with `aggregated`, `title`, `description`, `order`, `type`, and `options` fields. The `normalizeFacetableConfig()` method (line ~1123) handles both formats.
- **Fully implemented — non-aggregated facet isolation**: `FacetHandler::calculateFacetsWithFallback()` (line ~334) makes separate schema-scoped queries for non-aggregated fields and generates unique keys via `generateNonAggregatedKey()` (line ~459). Non-aggregated fields are tracked separately in `getFacetableFields()` (line ~1160).
- **Fully implemented — schema ID in non-aggregated facet response**: Non-aggregated facets include `schema` ID in the response (line ~846) so the frontend can scope queries via `_schema` parameter.
- **Fully implemented — custom title/description/order**: `transformNonAggregatedFieldFacet()` (line ~651) and `transformAggregatedFieldFacet()` (line ~719) apply config overrides for title, description, and order.
- **Fully implemented — facet type support**: `determineFacetType()` (line ~1287) supports `terms`, `date_range`, and `date_histogram` types with auto-detection based on property type/format.
- **Backend support in MagicFacetHandler**: `lib/Db/MagicMapper/MagicFacetHandler.php` handles SQL-level facet queries. `MariaDbFacetHandler` (`lib/Db/ObjectHandlers/MariaDbFacetHandler.php`) handles MariaDB-specific JSON facet queries.
- **Partially implemented — schema editor UI**: The `EditSchemaProperty.vue` modal likely needs verification for full support of the `type` and `options` config fields in the frontend.
- **Not yet verified — tilburg-woo-ui `_schema` parameter support**: The frontend integration for non-aggregated facets adding `_schema` to query params needs verification in the `tilburg-woo-ui` repo (separate from this app).

### Standards & References
- JSON Schema specification for property-level metadata extensions
- OpenRegister internal faceting API conventions (documented in `docs/Features/search.md`)
- Solr faceting via `SolrFacetProcessor` (`lib/Service/Index/Backends/Solr/SolrFacetProcessor.php`) for indexed search backends

### Specificity Assessment
- **Highly specific and implementable as-is**: The spec provides detailed scenarios for every facet configuration option, including backward compatibility, non-aggregated isolation, and UI interaction.
- **Well-defined edge cases**: Covers partial config objects, default values, and backward-compatible boolean handling.
- **Open question**: How should `date_histogram` and `date_range` facets interact with the Solr backend? The spec defines behavior at the FacetHandler level but does not specify Solr-specific configuration.
- **Open question**: What happens when multiple non-aggregated facets from different schemas are active simultaneously? The `_schema` parameter is singular, which could conflict.

### Requirement: Faceting MUST be available through GraphQL connection types
GraphQL list queries MUST expose facets and facetable field discovery through the connection type, reusing the existing FacetHandler.

#### Scenario: Request facets in a GraphQL list query
- **WHEN** a client queries `meldingen(facets: ["status", "priority"]) { edges { node { title } } facets facetable }`
- **THEN** the `facets` field MUST contain value counts per requested field matching FacetHandler output
- **AND** facets MUST be calculated on the full filtered dataset independent of pagination (`first`/`offset`/`after`)

#### Scenario: Discover facetable fields via GraphQL
- **WHEN** a client queries `meldingen { facetable }`
- **THEN** all property names with `facetable` configuration (boolean `true` or config object) MUST be listed

#### Scenario: Non-aggregated facets include schema context in GraphQL
- **WHEN** a schema property has `"facetable": { "aggregated": false, "title": "Organisatie Type" }`
- **AND** the facets are returned through GraphQL
- **THEN** the facet entry MUST include `schema` ID and `queryParameter` fields matching the REST response format

#### Scenario: Facet title and order respected in GraphQL
- **WHEN** facets with custom `title` and `order` are returned through GraphQL
- **THEN** the custom titles MUST be used instead of auto-generated ones
- **AND** the `order` field MUST be included for client-side sorting

#### Scenario: Facets with date histogram type in GraphQL
- **WHEN** a date property has `"facetable": { "type": "date_histogram", "options": { "interval": "month" } }`
- **AND** the facet is requested through GraphQL
- **THEN** the facet buckets MUST be grouped by month intervals matching the REST API behavior

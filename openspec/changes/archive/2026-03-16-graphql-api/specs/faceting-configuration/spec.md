## ADDED Requirements

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

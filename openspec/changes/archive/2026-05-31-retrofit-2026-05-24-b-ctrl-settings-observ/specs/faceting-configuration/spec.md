## ADDED Requirements

### Requirement: Solr facet configuration discovery and management API
The system SHALL expose an admin-gated API for discovering facetable fields and managing
the Solr facet configuration. `SolrSettingsController` provides `getSolrFacetConfiguration`
and `updateSolrFacetConfiguration` for the stored facet config, `discoverSolrFacets` for
auto-detecting facetable fields from the live index, and the combined
`getSolrFacetConfigWithDiscovery` / `updateSolrFacetConfigWithDiscovery` endpoints that
merge stored configuration with live discovery results.

#### Scenario: Discover facetable fields
- **WHEN** `discoverSolrFacets` is called
- **THEN** it MUST return the set of facetable fields detected from the active Solr index

#### Scenario: Read facet config merged with discovery
- **WHEN** `getSolrFacetConfigWithDiscovery` is called
- **THEN** it MUST return the stored facet configuration enriched with currently discoverable facets

#### Scenario: Persist updated facet configuration
- **GIVEN** an admin posts an updated facet configuration
- **WHEN** `updateSolrFacetConfiguration` runs
- **THEN** it MUST persist the configuration and return the result

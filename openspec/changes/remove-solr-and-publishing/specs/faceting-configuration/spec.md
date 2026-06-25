## MODIFIED Requirements

### Requirement: Database-native faceting across PostgreSQL and MariaDB
The faceting system MUST operate on the database backends only (PostgreSQL/MariaDB). `MagicFacetHandler` MUST handle SQL-based faceting with per-column `GROUP BY` queries on dynamic magic tables, and `MariaDbFacetHandler` MUST handle JSON faceting on the legacy `openregister_objects` table. There is no external search engine and no `SolrFacetProcessor`. `FacetHandler.transformFacetsToStandardFormat()` MUST normalize output into the API response format with `name`, `type`, `title`, `description`, `queryParameter`, `order`, `data.buckets[]` structure.

#### Scenario: PostgreSQL terms facet via MagicFacetHandler
- **GIVEN** PostgreSQL is the active backend with magic table `or_r1_s1` containing column `status`
- **WHEN** `MagicFacetHandler.getTermsFacet()` is called for `status`
- **THEN** it MUST execute `SELECT status AS field_value, COUNT(*) AS doc_count FROM oc_or_r1_s1 WHERE status IS NOT NULL GROUP BY status ORDER BY doc_count DESC LIMIT 10000`
- **AND** return `{ type: 'terms', buckets: [{ key: 'nieuw', results: 30 }, ...] }`

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

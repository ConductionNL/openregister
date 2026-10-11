# objects-crud

## ADDED Requirements

### Requirement: A multi-schema search orders the same on every path

A search across more than one register and schema SHALL return its rows in
the same order, and cut the same pages, whether it runs as one UNION
statement or table by table (the path taken for `_aggregations`). The order
SHALL be the requested `_order` (including `@self.*` metadata keys, an
unknown one being ignored), else the search score when a search term is
given, and SHALL always end with the uuid ascending as the tiebreaker.

#### Scenario: an aggregating search pages like a plain one

- **GIVEN** two schemas whose objects are ordered by `title` ascending
- **WHEN** the same query is run with and without `_aggregations`, two rows per page
- **THEN** every page holds the same uuids in the same order on both paths
- @e2e exclude {path selection is internal to the mapper; covered by MagicMapperSequentialOrderTest}

#### Scenario: no order asked

- **GIVEN** a multi-schema search with no `_order` and no search term
- **WHEN** it runs on the sequential path
- **THEN** the rows come back by uuid ascending across both schemas, not schema by schema
- @e2e exclude {path selection is internal to the mapper; covered by MagicMapperSequentialOrderTest}

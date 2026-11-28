# upgrade-performance

## ADDED Requirements

### Requirement: A plain text search across many tables leaves out the tables without rows

A plain text search across more than one magic table (a query whose keys are
only `@self`, `_search`, `_limit`, `_offset`, `_page`, `_rbac`,
`_multitenancy`, `_multitenancy_explicit` and `_snippet`) SHALL find out in one
statement per 50 tables which of its tables hold at least one row, and SHALL
build search and count arms only for those tables and for tables this request
does not know to exist. Its page and its total SHALL be the same as when every
table has an arm. A query with any other key SHALL keep an arm for every table.
A failed probe SHALL keep an arm for every table.

#### Scenario: a text search where most tables are empty
- **GIVEN** 43 magic tables a caller may read, 12 of which hold rows
- **WHEN** the caller searches `GET /api/objects?_search=e`
- **THEN** one probe statement SHALL name all 43 tables
- **AND** the search statement SHALL have an arm for each of the 12 tables only
- **AND** the results and the total SHALL be byte-identical to a search with 43 arms
- @e2e exclude {arm count has no screen of its own; covered by MagicMapperUnionRowProbeTest and the live byte-identical comparison}

#### Scenario: a search that filters on a property
- **GIVEN** the same tables
- **WHEN** the caller searches with `_search=e` and a property filter or `_order`
- **THEN** every table SHALL keep its arm and no probe SHALL run
- @e2e exclude {covered by MagicMapperUnionRowProbeTest}

#### Scenario: no table holds a row
- **GIVEN** a search whose tables are all empty
- **WHEN** the caller searches
- **THEN** the answer SHALL be an empty page with total 0
- @e2e exclude {covered by MagicMapperUnionRowProbeTest}

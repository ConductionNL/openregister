# upgrade-performance

## ADDED Requirements

### Requirement: A search across many magic tables reads their catalogue in one query

A search that reads more than one magic table SHALL read the columns of all
of them with one `information_schema.columns` query, limited to the instance's
own schema, before it builds its per-table SQL. That read SHALL answer both
whether each table exists and which columns it has for the rest of the
request. A table the read does not return SHALL NOT be recorded as missing: it
keeps the one-table lookup. The search's answer SHALL be the same as with the
one-table lookups.

#### Scenario: a text search over every readable table
- **GIVEN** 32 magic tables a caller may read
- **WHEN** the caller searches `GET /api/objects?_search=e`
- **THEN** the catalogue SHALL be read once for the columns of all 32 tables
- **AND** no table SHALL be probed on its own for its existence or its columns
- @e2e exclude {catalogue query count has no screen of its own; covered by MagicMapperCatalogueBatchTest}

#### Scenario: a table that does not exist yet
- **GIVEN** a register+schema pair whose table does not exist and has magic mapping enabled
- **WHEN** a cross-table search includes it
- **THEN** the batched read SHALL not record the table as missing
- **AND** the one-table path SHALL create it and read its columns as before
- @e2e exclude {DDL has no screen of its own; covered by MagicMapperCatalogueBatchTest}

### Requirement: A global text search counts its total in one statement

The total of a text search across every readable magic table SHALL be counted
with one statement per batch of UNION arms (a `SUM` over one `COUNT(*)` arm per
table, built from the same WHERE as the search arms), not with one count query
per table. When the search itself ran as one UNION statement and returned
rows, that statement SHALL carry the total (a window count over the arms) and
no count statement SHALL run. The total SHALL be the same as the sum of the
per-table counts.

#### Scenario: the total of a search over 32 tables
- **GIVEN** 32 magic tables a caller may read
- **WHEN** the caller searches `GET /api/objects?_search=e`
- **THEN** the total SHALL come from the search statement's window count
- **AND** it SHALL equal the number of rows the search matches before paging
- @e2e exclude {query count has no screen of its own; covered by MagicMapperSearchObjectsPaginatedTest and MagicMapperUnionBatchGoldenTest}

#### Scenario: a page past the last row
- **GIVEN** a global text search whose page is past its last row
- **WHEN** the page comes back empty
- **THEN** the total SHALL come from one count statement across the same tables
- @e2e exclude {query count has no screen of its own; covered by MagicMapperUnionBatchGoldenTest and MagicMapperCrossTableCountTest}

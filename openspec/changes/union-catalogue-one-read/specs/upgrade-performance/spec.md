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

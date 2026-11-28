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
per-table counts. This SHALL hold for the multi-schema search of the
unified-search provider too. A search that filters on a property, or carries a
`_where` tree, SHALL be counted per table before the search runs, so a filter
on an encrypted property is refused as before.

#### Scenario: a filter on an encrypted property is still refused
- **GIVEN** a schema with an encrypted property `title` among the searched tables
- **WHEN** a caller searches `GET /api/objects?_search=e&title=x`
- **THEN** the per-table count SHALL run first and refuse the filter, as before
- @e2e exclude {encrypted schema fixture is unit-level; covered by MagicMapperSearchObjectsPaginatedTest and MagicMapperMultiSchemaSearchGoldenTest}

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

### Requirement: A search across every table loads its registers and schemas in one query each

Before a global text search loads the register and the schema of each table
it may read, it SHALL read all of those registers with one query and all of
those schemas with one query, without the organisation filter, and answer
each find by id from them. A schema SHALL be the same entity the find by id
would have loaded (the stored row, its composition resolved). Lookups by uuid
or slug, and lookups with the organisation filter, SHALL keep their own query.

#### Scenario: a global search over 32 tables of 26 registers
- **GIVEN** 32 magic tables of 26 registers a caller may read
- **WHEN** the caller searches `GET /api/objects?_search=e`
- **THEN** the registers SHALL be read with one query and the schemas with one query
- **AND** the response SHALL be the same as when each was read on its own
- @e2e exclude {query count has no screen of its own; covered by RegisterMapperFindGoldenTest, SchemaMapperFindGoldenTest and RequestIdentityMapTest}

### Requirement: An ordered search across many tables runs on every supported database

A search across several magic tables SHALL read its UNION arms as one derived
table, so that its ordering (which may sort on expressions of the columns)
is valid on PostgreSQL as well as on MariaDB and MySQL.

#### Scenario: a case-insensitive order on PostgreSQL
- **GIVEN** an instance on PostgreSQL with more than one readable magic table
- **WHEN** a caller searches `GET /api/objects?_search=a&_order[title]=asc`
- **THEN** the search SHALL answer with the ordered page and its total
- @e2e exclude {database platform is not switchable in the e2e stack; covered by MagicMapperUnionBatchGoldenTest}

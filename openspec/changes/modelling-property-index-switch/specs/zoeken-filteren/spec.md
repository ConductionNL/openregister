# zoeken-filteren

## ADDED Requirements

### Requirement: A property can ask for an index without being a facet

A schema property MAY declare `indexed: true`. The system SHALL create a btree index on
the property's magic-table column when the table is created or synced, and SHALL drop
a convention-named index on sync when no property flag (`indexed`, `facetable` or a
relation) asks for it any more. A hand-made index MUST NOT be dropped.

#### Scenario: A functional administrator indexes a date for sorting

- **GIVEN** a functional administrator editing the property `registratiedatum` of the schema `zaken`
- **WHEN** they switch on Index for filtering and sorting, save, and run the table sync
- **THEN** `GET /api/schemas/{id}/indexes` lists an index on `registratiedatum` asked for by `indexed`
- **AND** `registratiedatum` does not appear in the facet response
- @e2e exclude {specified only; task 2.1 adds tests/e2e/property-index-switch.spec.ts}

#### Scenario: Switching the index off removes it

- **GIVEN** the indexed property `registratiedatum` that is not facetable and not a relation
- **WHEN** the administrator switches the index off and runs the table sync
- **THEN** the index list no longer shows that index
- @e2e exclude {specified only; task 1.2 adds the unit test}

### Requirement: The property editor offers both index kinds

The property editor SHALL show a switch for `indexed` and a switch for `searchable`
beside Facetable. The `searchable` switch MUST be disabled, with the reason shown, when
the instance cannot build a trigram index.

#### Scenario: The text index switch explains itself on MariaDB

- **GIVEN** an instance running on MariaDB
- **WHEN** an administrator opens the property editor
- **THEN** Index for text search is disabled and says it needs PostgreSQL with pg_trgm
- @e2e exclude {specified only; task 2.1 covers the switch on the PostgreSQL CI run; the MariaDB text is a component test}

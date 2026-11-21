## ADDED Requirements

### Requirement: An integer column holds every value its property accepts (REQ-MTC-003)

The magic-table column of a schema property of `type: integer` SHALL be a `bigint` when the property's `maximum` is above 2147483647. An existing `smallint` or `integer` column whose property now maps to `bigint` SHALL be widened in place on the same explicit paths as a number column (a table-structure update and `openregister:tables:reconcile --apply`), and a `bigint` column SHALL never be narrowed.

#### Scenario: a microsecond timestamp column is widened
- **GIVEN** a magic table whose `started_at_us` column is `integer`, and its property now declares `maximum: 9007199254740991`
- **WHEN** `openregister:tables:reconcile --apply` runs
- **THEN** the column SHALL become `bigint` and a value of 1794903368121960 SHALL be stored
- @e2e exclude {DDL migration has no screen of its own; covered by MagicDecimalWidenerMySqlTest and a live reconcile run}

#### Scenario: a bigint column is not narrowed
- **GIVEN** a magic table whose column is `bigint` and whose property maps to `integer`
- **WHEN** the table structure is updated
- **THEN** the column SHALL stay `bigint`
- @e2e exclude {DDL migration has no screen of its own; covered by MagicDecimalWidenerMySqlTest}

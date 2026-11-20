# magic-table-columns Specification

## Purpose
A schema with its own magic table stores each property in a typed column. This spec sets the column type a property maps to, so a column holds every value the property accepts, and when an existing column is widened.

## Requirements

### Requirement: A number column holds every value its property accepts (REQ-MTC-001)

The magic-table column of a schema property of `type: number` SHALL be a DECIMAL with 14 digits before the point. Its scale SHALL be the number of decimals of the property's `multipleOf` when the property declares one, and 6 otherwise. An existing column whose scale or digits before the point are smaller SHALL be widened in place without changing a stored value, and SHALL never be narrowed. Widening SHALL happen only on an explicit path: the upgrade repair step (every table to at least `DECIMAL(20,6)`, table by table, reporting each table and continuing past a failure), `openregister:tables:reconcile --apply`, and a table-structure update. A plain read or save SHALL NOT alter a table.

#### Scenario: a weight in kilograms keeps its grams
- **GIVEN** a schema property `weight` of `type: number` without `multipleOf`
- **WHEN** its column is mapped
- **THEN** the column SHALL be `DECIMAL(20,6)`, and 0.005 SHALL be stored and read back as 0.005
- @e2e exclude {column mapping has no screen of its own; covered by MagicNumberColumnTest and MagicMapperNumberColumnTest}

#### Scenario: multipleOf sets the scale
- **GIVEN** a schema property of `type: number` with `multipleOf: 0.001`
- **WHEN** its column is mapped
- **THEN** the column SHALL be `DECIMAL(17,3)`
- @e2e exclude {column mapping has no screen of its own; covered by MagicNumberColumnTest}

#### Scenario: an existing two-decimal column is widened
- **GIVEN** a magic table whose `weight` column is `DECIMAL(10,2)` and the property now maps to `DECIMAL(20,6)`
- **WHEN** the upgrade repair step or `openregister:tables:reconcile --apply` runs
- **THEN** the column SHALL become `DECIMAL(20,6)` and its stored values SHALL be unchanged
- @e2e exclude {DDL migration has no screen of its own; covered by MagicMapperNumberColumnTest and WidenMagicNumberColumnsTest}

#### Scenario: a plain use of a table does not alter it
- **GIVEN** a magic table whose `weight` column is still `DECIMAL(10,2)`
- **WHEN** the table is first used in a process, to read or to save
- **THEN** no ALTER SHALL run on it
- @e2e exclude {DDL timing has no screen of its own; covered by MagicTableHandlerTest and MagicMapperNumberColumnTest}

### Requirement: A number the column cannot hold is refused, not rounded (REQ-MTC-002)

A save SHALL be refused with a validation error naming the column when a `number` value has more decimals than its column's actual scale, read from the table, or more digits before the point than the column holds. This SHALL apply to the single-object and the bulk write path, and to a table not yet widened.

#### Scenario: a table not yet widened refuses what it would round
- **GIVEN** a magic table whose `weight` column is still `DECIMAL(10,2)`
- **WHEN** an object is saved with `weight` 0.005
- **THEN** the save SHALL be refused with a validation error naming `weight`, and nothing SHALL be stored
- @e2e exclude {write preparation has no screen of its own; covered by MagicMapperNumberColumnTest}

#### Scenario: too many decimals
- **GIVEN** a `number` property without `multipleOf` (scale 6)
- **WHEN** an object is saved with the value 0.0000001
- **THEN** the save SHALL be refused with a validation error naming the property, and nothing SHALL be stored
- @e2e exclude {write preparation has no screen of its own; covered by MagicNumberColumnTest and MagicMapperNumberColumnTest}

### Requirement: A JSON column does not slow every schema read of the instance (REQ-MTC-003)

On MariaDB a JSON column of a magic table SHALL be `MEDIUMTEXT` in the `utf8mb4_bin` collation with a `json_valid` check on the column, never `JSON` or `LONGTEXT`. MariaDB stores `JSON` as `LONGTEXT` with that check, and Doctrine tells the two apart with a query that scans the constraints of every table once per `longtext` column, on every read Nextcloud makes of the database schema: three per migration step of any app. Measured with 1,067 magic tables: about four minutes a read before, 16 seconds after (#10612). The check SHALL close the column definition, after `NOT NULL` and `DEFAULT`, and every statement that restates a JSON column SHALL restate the check. MySQL, PostgreSQL and SQLite SHALL keep their native type.

An existing `LONGTEXT` JSON column SHALL be narrowed by the upgrade, before its migrations run, one statement per table, in strict SQL mode so a value that does not fit refuses the statement instead of being cut short. The upgrade SHALL leave a table holding more than 256 MB of data, SHALL stop starting on new tables after five minutes, SHALL report what it left, and SHALL never fail because of this step; `occ openregister:tables:narrow-json` SHALL narrow whatever is left. A `LONGTEXT` column without the `json_valid` check SHALL NOT be touched.

#### Scenario: a new table on MariaDB
- **GIVEN** a schema with an `array` property on MariaDB
- **WHEN** its magic table is created
- **THEN** that column and the JSON metadata columns SHALL be `MEDIUMTEXT` with a `json_valid` check, and the table SHALL hold no `longtext` column
- @e2e exclude {DDL has no screen of its own; covered by MagicColumnSqlTypesTest and MagicMapperCreateTableGoldenTest}

#### Scenario: a column added to an existing table
- **GIVEN** an existing magic table on MariaDB
- **WHEN** a JSON column is added to it, or its nullability changes
- **THEN** the statement SHALL declare `MEDIUMTEXT` and end with the `json_valid` check
- @e2e exclude {DDL has no screen of its own; covered by MagicColumnAdderTest, MagicColumnNullabilityTest and MagicMapperAddJsonColumnTest}

#### Scenario: an upgrade narrows the existing tables
- **GIVEN** magic tables created with `JSON` columns on MariaDB
- **WHEN** the app is upgraded
- **THEN** each table under the size limit SHALL be narrowed in one statement before the migrations run, and a larger one SHALL be reported with the command that narrows it
- @e2e exclude {upgrade repair step has no screen of its own; covered by MagicJsonColumnNarrowerTest and NarrowMagicJsonColumnsTest}

#### Scenario: a value too large for the column
- **GIVEN** a `LONGTEXT` JSON column holding a value larger than `MEDIUMTEXT` can hold
- **WHEN** its table is narrowed
- **THEN** the statement SHALL be refused, the table SHALL be left as it was and reported, and the other tables SHALL still be narrowed
- @e2e exclude {DDL failure path has no screen of its own; covered by MagicJsonColumnNarrowerTest}

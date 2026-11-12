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

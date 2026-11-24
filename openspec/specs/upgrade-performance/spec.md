# upgrade-performance Specification

## Purpose
An upgrade of OpenRegister, or of an app built on it, has to finish within the time, memory and log space of an ordinary Nextcloud upgrade, on an instance that holds a thousand registers and schemas as well as on an empty one. This spec sets what the app's own upgrade code may cost. What the stored tables themselves cost every upgrade is set in `magic-table-columns` (REQ-MTC-003); what an import may write to the log is set in `data-import-export`.

Measured before these requirements, on Nextcloud 35 and MariaDB 10.6 with 1,067 magic tables: one read of the database schema took 235 to 290 seconds, Nextcloud core alone makes six of them in every `occ upgrade`, and each migration step of any app makes three (#10612). An upgrade from the last stable release ran 78 migration steps.

## Requirements

### Requirement: A migration step reads only the tables it changes

A migration step added after release 2.1.0 SHALL extend `TargetedMigrationStep` and implement `changeTargetedSchema()`, `beforeTargetedSchemaChange()` and `afterTargetedSchemaChange()` in place of `changeSchema()`, `preSchemaChange()` and `postSchemaChange()`.

When Nextcloud's migration service runs such a step on its own, which is how an upgrade runs every step, the step SHALL be handed a schema that reads a table from the database the first time the step names it and reads no other table, and the change the step made SHALL be applied from those tables alone: the tables as they were compared with the tables as the step left them, a string column longer than 4000 turned into text, and a dropped table dropped after the other statements, as Nextcloud's own migrator does. Nextcloud SHALL then be told there is nothing left to apply, so it reads the whole schema for neither the comparison nor its name checks. The resulting tables SHALL be the ones Nextcloud's own path produces.

The statements of a step SHALL run in one transaction on every database but MySQL and MariaDB, which commit on each DDL statement; that is the rule Nextcloud's own migrator applies. When the database refuses one of them, the transaction SHALL be rolled back before the refusal is passed on: the step is undone as a whole where DDL is transactional (PostgreSQL, SQLite), and the connection is left without an open transaction. Left open, PostgreSQL refuses every later statement of the process ("current transaction is aborted") and SQLite discards them at disconnect.

On a fresh install, where Nextcloud hands every step one shared schema and applies the sum once, and in a schema check, which replays the steps against a schema of its own, a step SHALL work on the schema it is given, unchanged. A hook called with a closure that is not Nextcloud's migration service's SHALL be given that closure. A step that returns a schema it did not get from the closure, or null, SHALL be left to Nextcloud.

#### Scenario: an upgrade runs a step that adds a column
- **GIVEN** an instance with a thousand magic tables and a pending step that adds a column to one table
- **WHEN** `occ upgrade` runs the step
- **THEN** only that table SHALL be read, the column SHALL be added, and Nextcloud SHALL make no read of the whole schema for the step
- @e2e exclude {database migration has no screen of its own; covered by TargetedMigrationStepTest and the targeted-migration database test}

#### Scenario: a fresh install
- **GIVEN** an instance without the app
- **WHEN** the app is installed
- **THEN** every step SHALL work on the one schema Nextcloud shares between them, and Nextcloud SHALL apply it once
- @e2e exclude {database migration has no screen of its own; covered by TargetedMigrationStepTest}

#### Scenario: a step drops a table
- **GIVEN** a pending step that drops a table
- **WHEN** an upgrade runs it
- **THEN** the table SHALL be dropped after the step's other statements, and no other table SHALL be touched
- @e2e exclude {database migration has no screen of its own; covered by the targeted-migration database test}

#### Scenario: the database refuses a statement of a step
- **GIVEN** a pending step that creates a table and adds a unique index the existing rows violate
- **WHEN** an upgrade runs it on PostgreSQL or SQLite
- **THEN** the refusal SHALL reach Nextcloud, the created table SHALL be gone again, and the connection SHALL have no open transaction
- @e2e exclude {database migration has no screen of its own; covered by the targeted-migration database test}

#### Scenario: a step asks for every table
- **GIVEN** a step that lists the tables of the schema
- **WHEN** an upgrade runs it
- **THEN** it SHALL be given every table of the database
- @e2e exclude {database migration has no screen of its own; covered by the targeted-migration database test}

### Requirement: A new magic table gets its standard indexes in one statement

On MariaDB the ten indexes every magic table carries (uuid, register and schema, organisation, owner, created, updated, name, slug, uri, last user activity) SHALL be added to a table created just now in one `ALTER TABLE` statement, with the names and columns they have always had. Each index statement is a DDL transaction of its own there: installing an app with 538 schemas took 270 seconds, 206 of them in those statements (#9945). A table that already existed SHALL keep one statement per index, so a column an older table lacks refuses that one index and not all ten. Other databases SHALL keep one statement per index.

#### Scenario: an app with many schemas is installed on MariaDB
- **GIVEN** an app configuration whose schemas have no table yet
- **WHEN** it is imported
- **THEN** each new table SHALL get its standard indexes in one statement
- @e2e exclude {DDL has no screen of its own; covered by MagicTableIndexerNewTableTest}

#### Scenario: an existing table is synchronised
- **GIVEN** a magic table that already exists
- **WHEN** its indexes are synchronised
- **THEN** each standard index SHALL be created by a statement of its own, as before
- @e2e exclude {DDL has no screen of its own; covered by MagicTableIndexerNewTableTest and MagicMapperCreateTableIndexesGoldenTest}

### Requirement: A catalogue query reads the instance's own database only

Every query on `information_schema` SHALL be limited to the schema the connection works in: `table_schema = DATABASE()` on MySQL and MariaDB, `table_schema = current_schema()` on PostgreSQL. The catalogue describes every database on the server. Without the condition a table listing returns the magic tables of a neighbouring Nextcloud as this instance's own, and a column lookup by table name returns the columns of every same-named table. Measured on a server holding two instances: 38 tables of the neighbour in every sweep of an upgrade, each failing, and 40,202 "Error searching table" log entries from one background job once the neighbour held a thousand. The only exception is code that reads a database other than the instance's own on purpose.

#### Scenario: a second Nextcloud shares the database server
- **GIVEN** two instances with OpenRegister in two databases on one server
- **WHEN** one of them lists its magic tables, or the columns of one
- **THEN** it SHALL get its own tables and columns only
- @e2e exclude {catalogue query has no screen of its own; covered by CatalogueQueryScopeTest}

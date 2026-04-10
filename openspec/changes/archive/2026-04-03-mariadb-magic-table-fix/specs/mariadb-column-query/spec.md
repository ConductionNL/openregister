## ADDED Requirements

### Requirement: Platform-aware table_schema filter in getExistingTableColumns
`MagicMapper::getExistingTableColumns()` SHALL detect the active database platform and use the appropriate `table_schema` value in its `information_schema.columns` query: `DATABASE()` for MySQL/MariaDB and `'public'` for PostgreSQL.

#### Scenario: MariaDB returns correct column list
- **WHEN** `getExistingTableColumns()` is called on a MariaDB/MySQL instance
- **THEN** the SQL query SHALL use `table_schema = DATABASE()`
- **AND** the method SHALL return all existing columns for the given table
- **AND** no `ADD COLUMN` SHALL be attempted for columns that already exist

#### Scenario: PostgreSQL behaviour is unchanged
- **WHEN** `getExistingTableColumns()` is called on a PostgreSQL instance
- **THEN** the SQL query SHALL use `table_schema = 'public'`
- **AND** the method SHALL return all existing columns for the given table
- **AND** no `ADD COLUMN` SHALL be attempted for columns that already exist

#### Scenario: No duplicate column error on MariaDB write
- **WHEN** an object is saved via MagicMapper on a MariaDB instance with an existing table
- **THEN** no `SQLSTATE[42S21]: Column already exists` error SHALL be raised
- **AND** the write SHALL succeed without attempting to re-add the `_id` primary key column

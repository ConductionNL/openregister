## Why

`MagicMapper::getExistingTableColumns()` uses `table_schema = 'public'` in its `information_schema.columns` query, which is PostgreSQL-specific syntax. On MariaDB/MySQL, `table_schema` holds the database name (e.g., `nextcloud`), so the query always returns zero rows, causing MagicMapper to attempt `ADD COLUMN` for every column — including the `_id` primary key — on every write, resulting in `SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name '_id'`.

## What Changes

- `MagicMapper::getExistingTableColumns()` in `lib/Db/MagicMapper.php` will detect the database platform and use `table_schema = DATABASE()` for MySQL/MariaDB and `table_schema = 'public'` for PostgreSQL.

## Capabilities

### New Capabilities

- none

### Modified Capabilities

- `mariadb-column-query`: The `getExistingTableColumns()` method SHALL use a platform-aware `table_schema` filter so that it correctly returns existing columns on both PostgreSQL and MariaDB/MySQL.

## Impact

- **File changed**: `lib/Db/MagicMapper.php` (single method, ~3 lines)
- **Platforms fixed**: MariaDB and MySQL (PostgreSQL behaviour unchanged)
- **No API or schema changes**
- **No migration needed**: the fix corrects a runtime query; no data or DDL changes are required

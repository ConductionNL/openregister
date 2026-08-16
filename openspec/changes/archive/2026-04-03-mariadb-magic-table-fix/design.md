## Context

`MagicMapper` manages per-schema SQL tables at runtime. Before altering a table it calls `getExistingTableColumns()` to discover which columns already exist. That method queries `information_schema.columns` with `table_schema = 'public'`, which is valid only on PostgreSQL (where `'public'` is the default schema). On MariaDB/MySQL `table_schema` is the database name, so the filter never matches, the method returns an empty array, and the caller issues `ADD COLUMN` for every column on every write — crashing with `Duplicate column name '_id'`.

The same class already contains a solved version of this problem: `tableExists()` (around line 1598) uses the same `getDatabasePlatform()` pattern and correctly switches between `DATABASE()` (MySQL/MariaDB) and `current_schema()` (PostgreSQL) for its `information_schema.tables` query. This fix applies the identical pattern to `getExistingTableColumns()`.

## Goals / Non-Goals

**Goals:**

- Make `getExistingTableColumns()` return correct results on MariaDB/MySQL
- Preserve existing PostgreSQL behaviour exactly
- Reuse the established `getDatabasePlatform()` / `stripos(..., 'PostgreSQL')` pattern already present in the file

**Non-Goals:**

- SQLite support (not used in production; not broken by this change)
- Refactoring the broader platform-detection logic in MagicMapper
- Any CI, schema, or API changes

## Decisions

### Decision: Use `DATABASE()` for MySQL/MariaDB, keep `'public'` for PostgreSQL

`DATABASE()` is the MySQL/MariaDB SQL function that returns the currently-selected database name. It is the correct, portable replacement for `'public'` on those platforms and exactly mirrors what `tableExists()` already does for `information_schema.tables`.

Alternative considered: `current_schema()` — this is ANSI SQL and works on PostgreSQL, but is **not** supported on MariaDB/MySQL. Rejected.

Alternative considered: Nextcloud `ISchemaWrapper` / DBAL Schema API — avoids raw SQL entirely, but `getExistingTableColumns()` was deliberately written with raw SQL to bypass Nextcloud's table-prefix handling for `information_schema`. Switching to DBAL schema introspection is a larger refactor out of scope for a targeted bug fix.

### Decision: Platform detection via `stripos($platform::class, 'PostgreSQL')`

This is the pattern already used in `tableExists()`, `createTable()`, and several other methods in the same file. Using `instanceof PostgreSQLPlatform` is equally valid (and used in some other methods) but `stripos` is more resilient to Doctrine platform subclass naming. Consistency with the majority of existing usages in `getExistingTableColumns()`'s neighbours wins.

## Risks / Trade-offs

- **Risk**: A future database platform (e.g., Oracle, MSSQL) may not support `DATABASE()`.  
  **Mitigation**: The `else` branch (MySQL/MariaDB) is already the non-PostgreSQL fallback; any new platform that needs different handling will require its own fix regardless.

- **Risk**: `'public'` is not guaranteed to be the correct PostgreSQL schema if a non-default `search_path` is configured.  
  **Mitigation**: This pre-existing limitation is out of scope; the fix does not change PostgreSQL behaviour.

## Migration Plan

No migration is required. The fix changes only the SQL query used at runtime to introspect the database schema. No data is altered. Rollback is trivially achieved by reverting the single changed line.

## Open Questions

None.

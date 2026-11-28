# A cross-table search reads the catalogue of its tables in one query

## What was wrong

A search across many magic tables (GET /api/objects?_search=..., the unified
search provider, a register-wide search) builds one UNION arm per
register+schema table. For every arm it asked `information_schema` twice:

- `existsTableForRegisterSchema()` probed `information_schema.tables` for the table;
- `columnExistsInTable()` read the table's columns from `information_schema.columns`.

Both answers are memoised per process, and under php-fpm a process memo
lasts one request, so every search paid both again. With the 26 bundled
registers (32 magic tables) one search sent 65 of its 212 queries to the
catalogue: ~15 ms of database time plus the PHP cost of 64 prepared statements,
on a request that is over the 100 ms API rule (ADR-124).

## What changes

- Before it builds the arms, the union search reads the columns of all its
  tables in ONE `information_schema.columns` query (`table_name IN (...)`,
  limited to the instance's own schema, in chunks of 500 tables).
- That read fills the same column-name and column-type caches the one-table
  lookup fills, and records each table it returns as existing: a table with
  columns exists.
- A table the read does not return is not recorded at all, so it keeps the
  one-table path (probe, create when magic mapping is enabled, read its
  columns). Nothing is ever cached as missing.
- Tables already read in this request are not read again; a single table and
  SQLite keep the one-table query; a failed read falls back to it.

The answer of every search is unchanged.

## Not in this change

- Caching the catalogue across requests (needs an invalidation signal at every
  DDL site; this change needs none).
- The separate total count of a cross-table search (one COUNT per table).

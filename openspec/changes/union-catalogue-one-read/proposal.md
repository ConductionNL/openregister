# A cross-table search reads its catalogue and its total in one query each

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

- The total of a global text search (`GET /api/objects?_search=...` without a
  register or schema) is counted with `countAcrossMultipleTables()`, the count
  `searchTables()` already uses: one `SUM` over `COUNT(*)` UNION arms built from
  the same WHERE as the search arms, instead of one count query per table (32
  queries for the bundled tables).
- When the search ran as one UNION statement (up to 50 tables) and returned
  rows, that statement carries the total itself: it is wrapped as
  `SELECT unioned.*, COUNT(*) OVER () ... FROM (<the arms>) unioned`, so the
  total is counted on the very WHERE the page is read with and no second
  statement runs. An empty page, or a search in several batches, keeps the
  separate one-statement count.

- The global text search loads every register and schema it is about to
  find by id (26 registers and 32 schemas: 58 queries) with one query each
  first: `RegisterMapper::preloadUnfiltered()` and
  `SchemaMapper::preloadUnfiltered()` remember each row under its id in the
  request's find cache for `find($id, _multitenancy: false)`, the same entity
  that find would have loaded. A uuid or slug lookup, and a lookup with the
  organisation filter, keep their own query.

The answer of every search is unchanged.

## Fixed on the way

- On PostgreSQL an ordered cross-table search failed: the ORDER BY of a bare
  UNION may name output columns only, and the order keys are expressions
  (`LOWER(CAST(...))` for a case-insensitive sort), so
  `GET /api/objects?_search=a&_order[title]=asc` answered 500 (SQLSTATE 0A000).
  Every UNION batch now reads its arms as one derived table
  (`SELECT unioned.* FROM (<arms>) unioned ORDER BY ...`).

## Not in this change

- Caching the catalogue across requests (needs an invalidation signal at every
  DDL site; this change needs none).

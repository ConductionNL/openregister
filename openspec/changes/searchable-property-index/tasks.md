## 1. Extension bootstrap

- [ ] 1.1 Add a migration that runs `CREATE EXTENSION IF NOT EXISTS pg_trgm` once (PostgreSQL-only, try/catch + skip on failure, matching the existing Postgres-guard precedent used elsewhere in `lib/Migration/`). Does not touch `hasPgTrgmExtension()` detection logic — that already exists and is unchanged.

## 2. Baseline `_name` index

- [ ] 2.1 In `MagicMapper::createTableForRegisterSchema()`'s index-creation block, add an unconditional `pg_trgm` GIN index on the `_name` metadata column (`CREATE INDEX IF NOT EXISTS {table}_name_trgm_idx ON {table} USING GIN (_name gin_trgm_ops)`), guarded by the existing `$isPostgres` check plus `hasPgTrgmExtension()`.

## 3. Opt-in `searchable` property flag

- [ ] 3.1 Extend the schema-property iteration loop in `MagicMapper::createTableForRegisterSchema()` (the same loop that already checks `facetable`) to also check `$propertyConfig['searchable'] ?? false`, creating `CREATE INDEX IF NOT EXISTS {table}_{col}_trgm_idx ON {table} USING GIN ({col} gin_trgm_ops)` when true, Postgres + `hasPgTrgmExtension()` gated, wrapped in the same try/catch-and-skip pattern as the neighbouring `facetable`/relation index creation.
- [ ] 3.2 Confirm whether `MagicTableHandler::syncTableForRegisterSchema()`'s existing-table update path already re-invokes the facetable/relation/baseline-`_name` index-creation routine; wire the retrofit call so both the baseline `_name` index and newly-`searchable`-flagged properties get indexed on an existing table when the schema changes (see design.md Decision 4 / Open Questions).

## 4. Documentation

- [ ] 4.1 Document the `searchable: true` schema-property flag alongside the existing `facetable` documentation (schema-authoring reference), including the guidance that it applies to string-typed properties and is a portable no-op on non-PostgreSQL platforms.

## 5. Tests

- [ ] 5.1 Unit tests: baseline `_name` trgm index is created on table creation when Postgres + `pg_trgm` are available, and skipped (no error) otherwise.
- [ ] 5.2 Unit tests: `searchable: true` property creates the expected GIN index at table-creation time; a non-string property marked `searchable` logs a warning and does not abort table creation.
- [ ] 5.3 Unit tests: retrofit path creates the trgm index on an existing table when a schema is updated to add `searchable: true` to a property (or when `pg_trgm` becomes available after table creation).
- [ ] 5.4 Integration test: measure (or assert query-plan use of the index via `EXPLAIN`) that `_search`/`_fuzzy=true` over `_name` on a magic table uses the new GIN index rather than a sequential scan, on a PostgreSQL test database with `pg_trgm` installed.

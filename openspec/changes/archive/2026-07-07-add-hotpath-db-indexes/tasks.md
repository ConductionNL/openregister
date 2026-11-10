## 1. Audit-trail index

- [ ] 1.1 Add a `lib/Migration/` step creating a composite index on `oc_openregister_audit_trails (register, schema)`; evaluate `(register, schema, created)` to also serve the date-range queries at `AuditTrailMapper.php:939-944`.
- [ ] 1.2 On Postgres use `CREATE INDEX CONCURRENTLY` where the migration framework allows; document the one-time build cost on large audit tables.

## 2. MySQL/MariaDB magic-table index

- [ ] 2.1 In `MagicMapper.php:3050-3062`, add a non-Postgres branch creating `CREATE INDEX {table}_deleted_owner_idx ON {fullTable} (_deleted, _owner)` (composite) for MySQL/MariaDB.

## 3. Catalog-query filter for findBySchema

- [ ] 3.1 In `MagicMapper::findBySchema()` (`:7351-7390`), filter candidate tables in the `information_schema` query (name pattern by schema id, or a schemaId→tables lookup) instead of listing all and discarding in PHP.

## 4. Verification

- [ ] 4.1 `EXPLAIN` an audit stats/history query filtered by register/schema before vs after — confirm index usage, no full scan.
- [ ] 4.2 On MySQL/MariaDB, `EXPLAIN` a `_deleted IS NULL AND _owner = ?` list query uses the new composite index.
- [ ] 4.3 Test: `findBySchema()` returns identical results; query log shows no full-catalog listing.
- [ ] 4.4 Migration is idempotent and re-runnable.
- [ ] 4.5 `composer check:strict` passes.

## Acceptance criteria

- Audit stats/history queries filtered by register/schema use an index.
- MySQL/MariaDB installs have a composite index for the `_deleted IS NULL, _owner`
  predicate.
- `findBySchema()` no longer lists the whole DB catalog to filter in PHP.

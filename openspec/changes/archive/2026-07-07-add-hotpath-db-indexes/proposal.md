---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

Two hot-path query patterns run against tables with no supporting index, and one
lookup lists the whole DB catalog to filter in PHP.

1. **Audit trail has no index on `register`/`schema` (HIGH).**
   `AuditTrailMapper::getStatistics()` (`lib/Db/AuditTrailMapper.php:805-826`)
   and the history/stats queries at `:939-958,1049-1056,1143-1150,1223-1230` all
   filter `WHERE register = ? [AND schema = ?]` (and date ranges on `created`).
   Reviewing every migration touching `openregister_audit_trails`
   (`Version1Date20241020231700`, `…20250410070338`, `…20260322100000`,
   `…20260502120000`, `…20260614000000`), the only indexes are on `user`, `uuid`,
   `hash`, `processing_activity_id`, and `import_job_id`. There is **no** index on
   `register`, `schema`, `created`, or `action`. This is the append-only,
   ever-growing audit table (ADR-003) — every register-detail view, dashboard
   stat, and audit history listing does a full table scan that worsens with every
   mutation ever recorded.

2. **Magic per-schema tables get a Postgres-only partial index; MySQL/MariaDB get
   nothing for the universal `_deleted IS NULL` filter (MED).**
   `MagicMapper.php:3050-3062` creates `{table}_live_owner_idx ON (_owner) WHERE
   _deleted IS NULL` guarded by `if ($isPostgres)`. MySQL/MariaDB (a supported
   target) get no equivalent, so the most common list predicate
   (`_deleted IS NULL AND _owner = ?`) has no supporting composite index there.

3. **`findBySchema()` lists every table in the DB then filters in PHP (MED).**
   `MagicMapper::findBySchema()` (`:7351-7390`) scans `information_schema` for all
   magic tables and `continue`s past non-matching schemas in PHP instead of
   filtering candidate table names in the catalog query.

## What Changes

- Add a migration creating a composite index on
  `oc_openregister_audit_trails (register, schema)` — and consider
  `(register, schema, created)` to also serve the date-range history queries.
- Add a MySQL/MariaDB branch in the magic-table index creation that builds a
  composite `(_deleted, _owner)` index as the best available approximation of the
  Postgres partial index.
- Filter candidate table names in the `information_schema` query itself (name
  pattern by schema id, or a lookup table mapping schema id → table names) so
  `findBySchema()` doesn't list-then-discard.

## Impact

- Affected: a new `lib/Migration/` step for the audit index; `lib/Db/MagicMapper.php`
  (MySQL index branch + catalog-query filter).
- Behavioural: read-only performance; index creation is online-safe on the sizes
  involved but the audit table may be large — create the index concurrently where
  the platform supports it, and note the one-time build cost.
- Risk: index DDL on a large audit table can be slow to build; document running it
  in a maintenance window / `CREATE INDEX CONCURRENTLY` on Postgres.

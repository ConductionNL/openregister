## Context

`MagicMapper::createTableForRegisterSchema()` already loops schema properties
at table-creation time and creates a btree index for any property with
`facetable: true` (verified, `lib/Db/MagicMapper.php` ~line 3112-3123):
`CREATE INDEX IF NOT EXISTS {table}_{col}_facet_idx ON {table} ({col})`,
guarded only by a try/catch (facetable indexes are plain btree, valid on
every platform — no Postgres guard needed). In the same method, a
Postgres-only GIN index is already created unconditionally for `_relations`
(line ~3039-3048), guarded by `$isPostgres = stripos($platform::class,
'PostgreSQL') !== false` — this is the exact precedent to extend for
`pg_trgm`.

`MagicMapper` and `MagicSearchHandler` (verified, both files) already have a
private `hasPgTrgmExtension(): bool` method that queries `pg_extension` and
caches the result for the request lifetime — already used to gate the
existing `_fuzzy=true` query-time `similarity()` calls. This change needs the
same check to gate index creation, not query execution.

`MagicSearchHandler::buildSearchConditionSql()` (verified, ~line 1531-1542)
already branches on `$isPostgres` and `$hasTrgm` to choose between a plain
`ILIKE` scoring expression and a `similarity()`-based one — this SQL is not
changed by this proposal; only an index is added underneath it.

## Goals / Non-Goals

**Goals:**
- Close the measured 268ms→1.5ms gap for every schema's `_name` fuzzy/substring
  search without requiring any schema authoring change (baseline, always-on
  when Postgres + pg_trgm are available).
- Give schema authors an explicit, `facetable`-shaped opt-in (`searchable:
  true`) to extend the same indexed-search benefit to specific non-metadata
  string properties.
- Retrofit both index kinds onto existing magic tables when the extension
  becomes available or a schema is updated to add the flag, mirroring however
  `facetable` retrofit already works.

**Non-Goals:**
- Changing `MagicSearchHandler`'s query SQL — the existing `ILIKE`/`similarity()`
  logic is correct; only the missing index is being added.
- File/chunk document search (the `hybrid-document-search` sibling spec) —
  different subsystem (typed object columns on magic tables, not extracted
  file text in `openregister_chunks`).
- A new query parameter or API surface — `_search`/`_fuzzy=true` behave
  identically to a caller; this is purely a performance/index change plus one
  new schema-authoring flag.
- Enforcing `searchable` only on `type: string` properties as a hard
  validation error — mirrored on `facetable`'s existing behaviour (no hard
  type gate found in `Schema.php`), the flag is honoured for string
  properties and silently ignored (logged) for non-string ones, consistent
  with how the codebase already treats schema-property boolean flags.

## Decisions

### 1. Baseline `_name` trgm index is unconditional, not opt-in

Every magic table gets a `pg_trgm` GIN index on its `_name` metadata column
whenever the platform is Postgres and the `pg_trgm` extension is available —
no schema change required. Rationale: `_name` is already unconditionally
searched by `MagicSearchHandler::buildSearchConditionSql()` for every schema
(it's a metadata column every schema has), so the exact scenario the spike
measured (rare-term search over `_name`/title-shaped content) is fixed for
every existing install the moment the extension is present, with zero
migration-authoring burden on app developers. This directly answers the
plan's "schema-level `searchable` flag" framing — the baseline behaviour is
schema-level (applies to every schema automatically) — while the opt-in flag
(Decision 2) is property-level, matching `facetable`'s existing granularity.

**Alternative considered**: make even the `_name` index opt-in via a new
per-schema (not per-property) flag. Rejected — `_name` is already
unconditionally part of every full-text search query
(`buildSearchConditionSql()` has no opt-out for `_name`), so gating its index
behind a flag would leave the exact scenario the spike measured unindexed by
default on every fresh install, reproducing the same regression this change
exists to fix.

### 2. Per-property `searchable: true` flag, structurally identical to `facetable`

Extend the existing property-iteration loop in
`MagicMapper::createTableForRegisterSchema()` (the same loop that already
checks `$propertyConfig['facetable'] ?? false`) with a parallel check:
`if (($propertyConfig['searchable'] ?? false) === true && $isPostgres === true
&& $this->hasPgTrgmExtension() === true)`, creating `CREATE INDEX IF NOT
EXISTS {table}_{col}_trgm_idx ON {table} USING GIN ({col} gin_trgm_ops)`.
Wrapped in the same try/catch-and-skip pattern already used for the
neighbouring `facetable` and relation indexes in that loop (index-already-
exists or column-type-incompatible errors are tolerated, not fatal).

**Alternative considered**: a separate configuration object (like
`faceting-configuration`'s `facetable` config-object support) with
`aggregated`/`title`/etc. sub-fields. Rejected as over-engineering for this
change — `searchable` has one job (create a trgm index); a boolean is
sufficient and matches `facetable`'s original (pre-config-object) simplicity.
Can be extended to a config object later following the same precedent
`faceting-configuration` set, if a concrete need arises.

### 3. `CREATE EXTENSION IF NOT EXISTS pg_trgm` runs once, in a migration

Rather than attempting `CREATE EXTENSION` inline inside `MagicMapper`'s
per-table index-creation code path (which runs frequently, on every table
sync), add one migration that runs `CREATE EXTENSION IF NOT EXISTS pg_trgm`
once at `occ upgrade` time (Postgres-only, try/catch + skip, matching every
other Postgres-only migration precedent in this codebase). `hasPgTrgmExtension
()` then continues to gate index creation at the `MagicMapper`/
`MagicSearchHandler` call sites exactly as it already does — this change adds
the extension bootstrap, not a new detection mechanism (both handlers'
`hasPgTrgmExtension()` are unchanged).

### 4. Retrofit on `syncTableForRegisterSchema()`, mirroring `facetable`

When an existing table is updated (schema changed, property added/modified),
the same trgm-index-creation call must run for both the baseline `_name`
index (idempotent — `CREATE INDEX IF NOT EXISTS` is a no-op if already
present) and any newly-`searchable`-flagged property. The apply implementer
must first confirm the concrete call site: whether
`MagicTableHandler::syncTableForRegisterSchema()`'s "table exists, update
structure" branch already re-invokes the full index-creation routine
(including the existing `facetable` loop) on every sync, or whether index
creation currently only runs on first creation. If the latter, this change
must add the missing re-invocation for both `facetable` (pre-existing gap)
and `searchable` (new) — flagged as an open question, not assumed.

## Risks / Trade-offs

- **[Risk] `CREATE EXTENSION pg_trgm` requires appropriate Postgres
  privileges** → Mitigation: try/catch + skip + log, exactly as
  `hasPgTrgmExtension()` already tolerates the extension being absent
  entirely; a failed `CREATE EXTENSION` simply means indexes are never
  created and the existing (slower, but already-shipping) query path
  continues to work unchanged.
- **[Risk] GIN trgm index write-amplification on high-churn tables** →
  Mitigation: `_name` is a single column per table (not the whole row); this
  is the same trade-off already accepted for the existing `_relations` GIN
  index and the `facetable` btree indexes on the same tables — no new class
  of risk, same category as existing indexes.
- **[Risk] `searchable: true` applied to a non-string property produces a
  useless or failing index** → Mitigation: `gin_trgm_ops` requires a
  text-castable column; the existing try/catch around index creation (same
  pattern as `facetable`'s "column type incompatible" tolerance) absorbs
  this without failing the whole table sync; a warning log entry surfaces the
  mismatch for the schema author to fix.

## Migration Plan

1. Migration: `CREATE EXTENSION IF NOT EXISTS pg_trgm` (Postgres-only,
   try/catch + skip).
2. Code change: `MagicMapper::createTableForRegisterSchema()` — add the
   unconditional `_name` GIN trgm index (Decision 1) and the `searchable`-flag
   opt-in per-property GIN trgm index (Decision 2), both gated by
   `hasPgTrgmExtension()`.
3. Code change: confirm and wire the retrofit path (Decision 4).
4. No backfill needed — this is index-only; existing data is unaffected,
   indexes simply accelerate already-correct queries the moment they exist.
5. Rollback: dropping the new indexes (if ever needed) has zero data-loss
   risk — they are derived, not source-of-truth, structures.

## Open Questions

- Does `MagicTableHandler::syncTableForRegisterSchema()`'s update path
  already re-run the facetable/relation index-creation loop on existing
  tables, or only on first creation? Confirm before implementing the
  retrofit task (Decision 4) — this determines whether the retrofit is "call
  the existing routine again" or "add a previously-missing call".
- Should `searchable: true` eventually grow into a config object (title,
  weight, language-config for the trgm similarity threshold) the way
  `facetable` did? Deferred — out of scope for this change; boolean-only for
  now (Decision 2).

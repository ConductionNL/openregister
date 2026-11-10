---
kind: code
depends_on: [hybrid-document-search]
chain:
  - hybrid-document-search
  - searchable-property-index
---

## Why

OpenRegister's fuzzy/full-text search over object magic tables (`_search`,
`_fuzzy=true`) already runs `similarity()` and `ILIKE` against `_name` /
`_description` / `_summary` and typed string columns (`MagicSearchHandler
::buildSearchConditionSql()` / `applyFullTextSearch()`), but no index backs
any of it — every fuzzy search computes `similarity()` per row on a full
sequential scan. The 2026-07-05 spectr scale spike measured this class of gap
directly: a rare-term `_search` forced an ILIKE seq-scan at 268ms; adding a
`pg_trgm` GIN index on the same column cut it to 1.5ms (~180x), on an 81,950-row
table representative of a real government-tender dataset. The system already
has the query logic (`MagicMapper::hasPgTrgmExtension()` /
`MagicSearchHandler::hasPgTrgmExtension()` already detect the extension); it is
missing the index that makes that logic fast instead of merely correct.

OpenRegister already has a precedent for schema-driven, auto-created indexes:
a property marked `facetable: true` gets a btree index created automatically
at magic-table creation time (`MagicMapper::createTableForRegisterSchema()`).
No equivalent exists for text search — a schema author has no way to say
"index this property for fast fuzzy/substring search" the way they can already
say "index this property for fast facet counts."

## What Changes

- Create an unconditional `pg_trgm` GIN index on every magic table's `_name`
  metadata column (PostgreSQL only) — this alone reproduces the measured
  268ms→1.5ms improvement for every schema, with no schema-authoring change
  required, closing the gap between what `MagicSearchHandler` already executes
  (`similarity()` / `ILIKE` against `_name`) and what it can execute quickly.
- Add a new, opt-in `searchable: true` boolean flag recognised on schema
  string properties (mirroring the existing `facetable` flag exactly), which
  creates an additional `pg_trgm` GIN index on that specific property's column
  at magic-table creation time — for schemas that want fast fuzzy/substring
  search on fields beyond `_name` (e.g. a tender's full `title` or
  `description` body).
- Retrofit both index kinds when an existing magic table's schema changes
  (mirroring however `facetable`'s retrofit-on-sync behaviour already works).
- All of the above is PostgreSQL-only and gracefully absent (no index, no
  error) on MariaDB/SQLite, exactly like the existing `facetable` GIN/btree
  index code already behaves for platform-specific index types.

## Capabilities

### Modified Capabilities
- `zoeken-filteren`: the existing "Fuzzy search with pg_trgm integration"
  requirement gains index-backing (GIN trgm on `_name`, always-on) instead of
  an unindexed `similarity()` scan; a new opt-in `searchable` schema-property
  flag extends indexed fuzzy/substring search coverage to specific
  non-metadata string properties, following the same shape as the existing
  `facetable` flag.

## Impact

- **Affected code**: `lib/Db/MagicMapper.php` (table-creation index-creation
  loop — the same code path that creates `facetable` btree indexes),
  `lib/Db/MagicMapper/MagicTableHandler.php` (`syncTableForRegisterSchema()`
  retrofit path), a new migration (or `postSchemaChange` bootstrap) to run
  `CREATE EXTENSION IF NOT EXISTS pg_trgm` once, `lib/Db/Schema.php` (schema
  property validation, if `searchable` needs a type constraint — only string
  properties should accept it).
- **Database**: additive index-only changes; no new columns, no new tables.
  Zero impact on MariaDB/SQLite installs (index creation is skipped, matching
  every other Postgres-only index in `MagicMapper.php`).
- **Query logic**: unchanged. `MagicSearchHandler`'s `similarity()`/`ILIKE`
  SQL is not modified — the index makes the existing query plan fast; no new
  query paths are introduced. `_search`/`_fuzzy=true` behaviour is identical
  from the API consumer's point of view, only faster.
- **Chain**: this is spec 2 of 2 in the `hybrid-document-search` /
  `searchable-property-index` chain (see that spec's proposal.md). The two
  specs touch independent subsystems (object magic-table property search here
  vs. file/chunk semantic+keyword search there) with no functional
  dependency between them — `depends_on: [hybrid-document-search]` is a
  sequencing choice (land the higher-evidence, larger fix first; avoid two
  large Postgres-index-adding code PRs landing in the same review window),
  not a technical requirement. **Flagged as a deferred question**: confirm
  whether this sequencing dependency should be kept, or whether the two specs
  should be declared fully independent siblings so Hydra can build them in
  parallel.

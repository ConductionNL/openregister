# Design — unified search over magic-table storage

## Context

- Storage modes: **central** (`oc_openregister_objects`, one JSONB `object`
  column) vs **magic** (`oc_openregister_table_{reg}_{schema}`, each property a
  real column, ~40+ columns/table). The affected fleet uses magic storage; the
  central table is empty.
- `ObjectsProvider::search()` builds `{_search, @self.schema: [searchableIds]}`
  with no register and calls `objectService->searchObjectsPaginated(_rbac:true,
  _multitenancy:true)`, which delegates to `MagicMapper::searchObjectsPaginated`.
- `MagicMapper::searchObjectsPaginated` enters `searchObjectsPaginatedMultiSchema`
  only when `schemaIds` AND (`registerId` || `registerIds`) are set; otherwise it
  resolves to single-schema/central paths.
- `searchAcrossMultipleTables` → `searchAcrossMultipleTablesWithUnion` when
  `count(pairs) > 1 && shouldUseUnionQuery`; else `…Sequential`.
  `collectAllPropertyColumns()` computes the superset width every branch aligns to.

## Decisions

### 1. Register resolution (correctness, small)
In `MagicMapper::searchObjectsPaginated`, when `schemaIds` is non-empty and no
register scope is present, derive registers via `registerMapper->findAll(_rbac:
false, _multitenancy:false)` and feed `searchObjectsPaginatedMultiSchema`. The
existing per-schema→register pairing (`register->getSchemas()`) maps each schema
to its owning register; unmatched schemas are skipped. Keeps single-schema and
explicitly-scoped callers unchanged.

### 2. UNION column-cap fallback (correctness, small)
In `searchAcrossMultipleTablesWithUnion`, after `collectAllPropertyColumns`,
compute `estimatedColumns = base-metadata-columns + count(allPropertyColumns)`.
When `estimatedColumns > UNION_MAX_COLUMNS` (const, e.g. 1500 — margin under
Postgres' 1664), `return $this->searchAcrossMultipleTablesSequential(...)` and
`log()` the fallback (no silent degradation). Sequential already merges, sorts
by `_search` score, and paginates in PHP, so results are correct.

### 3. Index-backed provider (performance, the real fix)
Sequential scanning of N magic tables per keystroke is O(tables). Use the
existing **`search-index`** capability as the provider's primary source:
- One index row per object: `object_uuid, register_id, schema_id, title,
  excerpt, search_text (tsvector), organisation, owner, published`.
- Write path: magic-table insert/update/delete upserts/removes the index row
  (hook in the save pipeline). A backfill `BackgroundJob` seeds existing objects.
- Read path: `ObjectsProvider` queries the single index table with the searchable-
  schema allow-list, RBAC/tenant/published predicates applied as WHERE clauses;
  no per-table UNION. Falls back to the magic-table path (with §1+§2) when the
  index is empty/disabled (feature flag), so behaviour is safe during rollout.

## Declarative-vs-imperative decision (ADR-031)
Search indexing is an imperative cross-cutting concern (write-path hook + FTS
query + backfill job), not a derived field on a single schema — implemented in
the search/index services, not as `x-openregister-*` schema config. Justified
exception: scheduled bulk work (backfill) + storage-engine integration.

## Risks
- Index drift if a write path bypasses the hook → backfill job + a periodic
  reconcile mitigate.
- Rollout: ship §1+§2 first (correct, slow at scale); enable §3 behind a flag
  once the index is backfilled, then make it the default.

## Seed Data
No new schemas. The `search-index` rows are derived from existing objects via
the backfill job; no manual seed entries required.

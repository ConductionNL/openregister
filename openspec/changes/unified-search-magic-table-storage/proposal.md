---
kind: code
---

## Why

In installs that use **magic-table storage** (objects live in per-(register,schema)
tables `oc_openregister_table_{reg}_{schema}`, the central `oc_openregister_objects`
table is empty), the Nextcloud top-bar unified search returns **zero results for
every object, fleet-wide**. Two defects compound:

1. **No register scope reaches the magic mapper.** `ObjectsProvider::search()`
   constrains the query to the allow-list of searchable schema IDs
   (`@self.schema = findSearchableIds()`) but supplies **no register**.
   `MagicMapper::searchObjectsPaginated()` only enters its multi-schema magic
   path when schema IDs **and** at least one register are present; with no
   register it falls through to the (empty) central-table query and returns
   nothing.

2. **The multi-schema UNION overflows Postgres' column cap.** Even when registers
   are supplied, `searchAcrossMultipleTablesWithUnion()` aligns every branch to
   the **superset of all property columns across all schemas in the union**
   (magic tables materialise each property as a real column — ~40+ columns each).
   A search spanning many diverse schemas (the unified-search case spans every
   searchable schema in the instance) produces a target list wider than
   Postgres' hard limit and fails with
   `SQLSTATE[54011]: target lists can have at most 1664 entries`. The provider
   swallows the exception and returns an empty result.

This was found building the OpenBuild "Pet Store" demo: searching "Rex" returned
nothing even though `GET /api/objects/{reg}/pet?_search=Rex` finds it directly.

## What Changes

- **Register resolution for schema-only searches.** When a caller passes a
  non-empty schema-ID set with no register scope, `MagicMapper` resolves the
  owning register(s) for those schemas (internal lookup, rbac/multitenancy off)
  so the multi-schema magic path is taken instead of the empty central table.
- **UNION column-cap fallback.** Before building the UNION, estimate the
  superset column width; when it would exceed a safe threshold under Postgres'
  1664-column cap, fall back to the existing `searchAcrossMultipleTablesSequential()`
  path (per-table queries merged + sorted + paginated in PHP — no combined-column
  limit). This turns a fatal query into a correct (if slower) result.
- **Index-backed unified search for scale (performance).** A sequential scan of
  every searchable magic table per keystroke does not scale (1000+ tables in a
  large fleet). Populate/consult the existing **`search-index`** capability (a
  lightweight per-object FTS row: object id, register, schema, title, excerpt,
  searchable text) so `ObjectsProvider` queries one index table instead of
  N magic tables. Magic-table writes upsert the index row; a backfill job seeds
  existing objects. The UNION/sequential magic-table path remains the fallback
  when the index is absent/disabled.

## Capabilities

### Modified Capabilities
- **unified-search-provider** — must work over magic-table storage (register
  resolution + column-cap fallback) and use the search index for scale.

### Referenced Capabilities
- **search-index** — the per-object FTS index the provider consults.

## Impact

- Fixes fleet-wide "unified search returns nothing" on magic-table installs.
- No behaviour change for installs already returning results via the central
  table or correctly-scoped multi-schema searches.
- Unblocks Pet Store tutorial feature **F** (search for pets).

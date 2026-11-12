---
kind: code
---

## Why

OpenRegister objects never appear in Nextcloud unified search (the top-bar
magnifier). The `ObjectsProvider` searches across **all** searchable schemas
by delegating to `ObjectService::searchObjectsPaginated`, which for the
cross-schema case routes into `MagicMapper::searchObjectsPaginatedMultiSchema`
and builds a `UNION` across the per-(register, schema) magic tables
(`oc_openregister_table_{reg}_{schema}`). On a real instance this fails and the
provider fails soft to an empty result, so only files/calendar from other NC
providers show up. Verified failure modes:

1. Each UNION arm projected the superset of every property column across all
   schemas → Postgres `SQLSTATE 54011: target lists can have at most 1664
   entries`. (Fixed by PR #233: metadata-only projection above a column
   budget.)
2. The per-table count re-applied the full ~1100-id `@self.schema` as one
   `IN(...)` → `More than 1000 expressions in a list`. (Fixed by PR #233:
   per-schema scoping of the count.)
3. Each UNION arm's WHERE re-applied the same >1000-id `IN(...)`. (Fixed by
   PR #233: per-schema scoping of each arm.)
4. The multi-schema path mis-resolves a schema's owning register — when no
   register filter is supplied it falls back to `reset($registers)` and targets
   a non-existent table → `Register+schema table does not exist` → empty even
   at small scale. **Still broken.**

PR #233 fixed crashes 1–3. This change finishes the job: it makes cross-schema
unified search work correctly and scalably **over the magic tables
themselves** — no secondary index, no Solr.

## What Changes

- **Fix register resolution (failure #4).** Pair each searchable schema with
  its OWN owning register by building a `schema_id → register` map across ALL
  registers (the register whose `getSchemas()` contains that schema id), rather
  than falling back to `reset($registers)`. This targets the correct
  `oc_openregister_table_{reg}_{schema}` for every schema. Schemas whose magic
  table does not exist are skipped.
- **Bounded, batched fan-out (scale).** Instead of one UNION over ALL
  searchable tables, batch the resolved (register, schema) pairs into groups
  small enough to stay safely under Postgres limits (UNION-arm count /
  statement size; target-list columns already mitigated by PR #233's
  metadata-only projection), run each batch's UNION, then merge + sort (by
  relevance/score, then a stable tiebreaker) + paginate (offset/limit) in PHP
  across batches.
- **No Solr/Elasticsearch.** Solr/Elasticsearch are deprecated; the
  unified-search provider relies on the magic tables only. The existing
  external `search-index` (Solr) capability/code is NOT modified or removed
  here — that cleanup is a separate change, out of scope; unified search simply
  stops depending on it.
- **Repoint/confirm `ObjectsProvider`** so the cross-schema search reaches the
  fixed, batched magic-table path (passing the searchable-schema set, with no
  register filter required to trigger the multi-schema branch).
- **Supersedes** the earlier denormalised-index idea: per the no-secondary-store
  directive there is no `oc_openregister_search_index` table, no lifecycle
  listener, and no backfill — the magic tables are the sole source.

## Capabilities

### New Capabilities
<!-- none: this fixes the data path behind an existing capability; no new capability is introduced. -->

### Modified Capabilities
- `unified-search-provider`: the requirement that the provider returns matching
  objects across all searchable schemas is unchanged in intent, but the
  cross-schema execution is corrected and bounded — register-resolved,
  batched UNION over the magic tables with PHP merge/sort/paginate, no
  secondary index and no Solr. RBAC / tenant-isolation / published /
  `searchable`-flag guarantees are restated against this path.

## Impact

- **PHP**: `lib/Db/MagicMapper.php` — `searchObjectsPaginatedMultiSchema` and
  its pairing/fan-out helpers (`searchAcrossMultipleTables` /
  `searchAcrossMultipleTablesWithUnion`); the multi-schema trigger so it fires
  on a schema-only (no-register) query; and `lib/Search/ObjectsProvider.php`
  (confirm it reaches the fixed path).
- **DB**: no schema change — no migration, no new table. Postgres-first; a
  MariaDB/MySQL note covers the equivalent UNION/statement limits.
- **Security**: RBAC, tenant isolation (active organisation), the `searchable`
  flag, and the published predicate stay enforced inside the existing OR search
  pipeline the magic query already delegates to.
- **No new OpenRegister schemas/registers** and **no `_registers.json` seed
  data** — there is not even an infra table.
- **Scale trade-off**: fan-out cost grows with the searchable-schema count;
  acceptable because realistic instances have far fewer searchable schemas
  (the ~1100 here is test pollution). An optional cache / early-exit is a
  future, out-of-scope optimisation.

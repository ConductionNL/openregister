# Tasks — unified search over magic-table storage

## 1. Register resolution for schema-only searches
- [ ] 1.1 In `MagicMapper::searchObjectsPaginated`, when `schemaIds` is non-empty and no `registerId`/`registerIds` is present, derive all register IDs via `registerMapper->findAll(_rbac: false, _multitenancy: false)` and route to `searchObjectsPaginatedMultiSchema`.
- [ ] 1.2 Unit test: schema-only query (no register) resolves to the multi-schema path and returns objects from the correct magic tables.

## 2. UNION column-cap fallback
- [ ] 2.1 Add `UNION_MAX_COLUMNS` constant (≈1500) and compute `estimatedColumns` from `collectAllPropertyColumns()` + base metadata columns in `searchAcrossMultipleTablesWithUnion`.
- [ ] 2.2 When `estimatedColumns > UNION_MAX_COLUMNS`, fall back to `searchAcrossMultipleTablesSequential` and log the fallback (no silent degradation).
- [ ] 2.3 Unit test: a wide superset (mock `collectAllPropertyColumns` > cap) routes to the sequential path; a narrow one stays on UNION.

## 3. Index-backed unified search (performance)
- [ ] 3.1 Confirm/extend the `search-index` schema row shape (object_uuid, register_id, schema_id, title, excerpt, search_text tsvector, organisation, owner, published).
- [ ] 3.2 Write-path hook: upsert/remove the index row on magic-table object save/delete.
- [ ] 3.3 Backfill `BackgroundJob` to seed index rows for existing objects; chunked + resumable.
- [ ] 3.4 `ObjectsProvider` reads from the index (single query, allow-list + RBAC/tenant/published predicates) behind a feature flag; falls back to the magic-table path (§1+§2) when the index is empty/disabled.
- [ ] 3.5 Newman: OCS search endpoint returns seeded objects across multiple registers (e.g. pet-store + pipelinq) for an admin.
- [ ] 3.6 PHPUnit: `ObjectsProviderTest` covers index hit, fallback path, and RBAC/tenant filtering.

## 4. Verification
- [ ] 4.1 Live: searching a pet name in the NC top bar returns the pet (Pet Store feature F).
- [ ] 4.2 `composer check:strict` clean; no new PHPCS errors.

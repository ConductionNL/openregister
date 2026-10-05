# Tasks — Optimize delete-path performance

Scope note: this change introduces **no OpenRegister schemas or registers** (no
seed-data task) and no lifecycle/aggregation/notification/widget behaviour
(ADR-031 declarative-vs-imperative guidance does not apply). It is a pure
backend performance change with preserved contracts.

## 1. Audit trail — single write, bulk insert

- [x] 1.1 Extract `AuditTrailMapper::buildAuditTrail()` (full row shape, no persist)
      from `createAuditTrail()`; `createAuditTrail()` = build + `insertHashChained()`.
- [x] 1.2 Add optional `cascadeContext` parameter to both; fold
      `changed.triggeredBy` / `changed.cascadeContext` (exact legacy key shape:
      triggerObject, triggerSchema, action_type, property) into the row **before**
      the INSERT.
- [x] 1.3 Add `AuditTrailMapper::insertAuditTrails(array $rows): array` — ONE
      multi-row `INSERT INTO ... VALUES (...), (...)` (union-of-fields column list,
      platform-quoted identifiers, per-field-type value encoding), id resolution by
      row uuid, then fail-soft `AuditHashService::sealRow()` per row in ascending id
      order (chain contiguity).
- [x] 1.4 Remove the INSERT-then-UPDATE pair in `DeleteObject::delete()`; pass
      `cascadeContext` to `createAuditTrail()` instead.

## 2. Bulk delete — batch scope resolution, no redundant re-finds

- [x] 2.1 `ObjectService::deleteObjects()`: batch-resolve all filtered UUIDs with one
      `findMultipleAcrossAllMagicTables(uuids, includeDeleted: true)` call
      (`batchResolveDeleteScopes()`); keep the legacy per-uuid find as fallback for
      batch misses (BUG-OBJ-5 / BUG-OBJ-14 behaviour preserved).
- [x] 2.2 Materialise Register/Schema entities once per distinct pair
      (`loadDeleteScopeEntities()` with per-operation caches) and call the handler
      with `preResolved:` + concrete scope; legacy call shape kept when scope
      entities cannot be materialised.
- [x] 2.3 `DeleteObject::deleteObject()` accepts `?ObjectEntity $preResolved`;
      `resolveDeletionContext()` short-circuits on a uuid-matching pre-resolved
      entity with concrete Register+Schema (defensive mismatch guard).
- [x] 2.4 `DeleteObject::delete()` accepts optional Register/Schema context and skips
      its `findAcrossAllSources` re-scan; both internal delete call sites pass the
      resolved context (`contextRegisterEntity()` / `contextSchemaEntity()`).
- [x] 2.5 `DeleteObject::delete()` clones the pre-delete state and passes it as
      `MagicMapper::update(oldEntity:)` — mapper's internal pre-update re-find
      eliminated, event old-object contract preserved.

## 3. Legacy cascade — level batching

- [x] 3.1 `collectCascadeTargetIds()`: gather all `cascade: true` referenced ids
      (scalar + array shapes, de-duplicated) before any delete.
- [x] 3.2 Batch-resolve targets with one `findMultipleAcrossAllMagicTables()` call;
      unresolved ids (numeric ids, slugs, URIs) fall back to the legacy per-id
      pipeline unchanged.
- [x] 3.3 Add `MagicMapper::softDeleteMultipleObjectEntities()`: one parameterised
      `UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)` per magic
      table; `ObjectUpdatingEvent` per entity before the write (hook-stop skips the
      entity; hook-modified payloads route through the full-row save),
      `ObjectUpdatedEvent` per entity after.
- [x] 3.4 `batchCascadeSoftDelete()`: per-child deletion metadata (same shape as
      `delete()`), ONE multi-row audit insert via `buildAuditTrail()` +
      `insertAuditTrails()`, per-object cache invalidation; total batch failure hands
      every id back to the per-id pipeline.

## 4. Tests

- [x] 4.1 `tests/Unit/Db/AuditTrailMapperBulkTest.php` — cascade-context folding in
      `buildAuditTrail()`, multi-row INSERT shape, id attach, ascending seal order,
      fail-soft sealing, input validation.
- [x] 4.2 `tests/Unit/Db/MagicMapper/MagicMapperBatchSoftDeleteTest.php` — one UPDATE
      per table with CASE expression, per-entity event parity, hook-stop skipping,
      scope validation.
- [x] 4.3 `tests/Unit/Service/Object/DeleteObjectBatchCascadeTest.php` — batched
      cascade pipeline, per-id fallback (batch miss + batch failure), pre-resolved
      context short-circuits, single-INSERT cascade audit, mismatch guard.
- [x] 4.4 `tests/Unit/Service/ObjectServiceTest.php` — bulk delete batch resolution,
      per-uuid fallback, RESTRICT skip behaviour.
- [x] 4.5 Existing delete/cascade/integrity suites stay green unchanged (they assert
      behaviour, not the removed redundant queries; mocked batch lookups return empty
      by default, exercising the preserved legacy fallback).

## 5. Verification

- [x] 5.1 `composer check:strict` clean for all touched files (php:8.3 container).
- [x] 5.2 Full PHPUnit unit suite in php:8.3-cli container; no new failures.
- [x] 5.3 `openspec validate optimize-delete-path-performance --type change --strict`.

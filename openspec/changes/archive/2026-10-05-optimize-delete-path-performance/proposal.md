---
kind: code
---

# Optimize delete-path performance

## Why

Deleting objects is one of the most query-heavy paths in OpenRegister. Two verified
hot spots (measured on `development` @ `200d60659`):

1. **Bulk delete runs the full per-object pipeline per UUID.**
   `ObjectService::deleteObjects()` performs a *scope-resolution* `find()` per UUID
   (a cross-magic-table scan when no register/schema context is set), then the delete
   handler **re-finds the same object** (`DeleteObject::resolveDeletionContext()` →
   `findAcrossAllSources`), then `DeleteObject::delete()` re-finds it **again**, and
   the mapper's `update()` re-finds the pre-update row a **fourth** time. On top of
   that, every cascade-tagged delete writes its audit row **twice**: a
   `createAuditTrail()` INSERT immediately followed by an `AuditTrailMapper::update()`
   just to attach cascade context. Deleting N objects costs ≈ 5–7N queries before the
   row UPDATE itself — and the post-seal audit UPDATE also **invalidates the ADR-003
   hash-chain seal** computed over the freshly-inserted row (verifyChain re-derives a
   different hash after the row is mutated).

2. **Legacy `cascade: true` deletion recurses per referenced id.**
   `DeleteObject::cascadeDeleteObjects()` feeds every referenced id individually
   through the full delete pipeline (cross-table scan + context re-find + full-row
   rewrite + per-row audit INSERT + seal) — inside one transaction when referential
   integrity is involved, so M cascade children hold a long magic-table transaction
   that blocks concurrent writers for the duration of ~5M queries.

## What Changes

### Bulk delete (`ObjectService::deleteObjects()`)

- Resolve the scope AND entity of **all** UUIDs up front with ONE batched cross-table
  lookup (`MagicMapper::findMultipleAcrossAllMagicTables`, soft-deleted rows included).
- Materialise each distinct (register, schema) pair as entities once per bulk operation
  (request-local cache on top of the mappers' own request caches) and pass the
  pre-resolved entity + concrete scope into the delete handler
  (`deleteObject(..., preResolved: $entity)`), which then skips its own lookup.
- UUIDs the uuid-only batch cannot resolve (numeric ids, slugs, URIs, vanished rows)
  keep the exact legacy per-uuid path — behaviour preserved, including BUG-OBJ-5
  cache-invalidation pair collection and BUG-OBJ-14 logging.

### Delete handler (`DeleteObject`)

- `delete()` accepts optional pre-resolved `Register`/`Schema` context and skips the
  redundant `findAcrossAllSources` re-scan when given an ObjectEntity plus context;
  `deleteObject()` passes its already-resolved context down at both delete call sites.
- `delete()` snapshots the pre-delete state once (`clone`) and hands it to
  `MagicMapper::update(oldEntity:)`, eliminating the mapper's internal pre-update
  re-find while keeping the `ObjectUpdatingEvent`/`ObjectUpdatedEvent` old-object
  contract intact.
- Cascade context is folded into the **initial audit INSERT**
  (`createAuditTrail(..., cascadeContext:)`) — the INSERT-then-UPDATE pair is gone,
  and the hash-chain seal now covers the row's final content.

### Legacy cascade (`DeleteObject::cascadeDeleteObjects()`)

- Collect all `cascade: true` referenced ids first, resolve them with ONE batched
  cross-table lookup, and soft-delete them via the new
  `MagicMapper::softDeleteMultipleObjectEntities()` — one
  `UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)` per magic table.
- Per-object event contract preserved: `ObjectUpdatingEvent` before the write (a hook
  that stops propagation skips that child, exactly like the per-id pipeline; a hook
  that modifies the payload routes that child through the full-row save so the
  modification persists) and `ObjectUpdatedEvent` after.
- Audit rows are written with ONE multi-row INSERT via the new
  `AuditTrailMapper::insertAuditTrails(array $rows)` (rows built by the extracted
  `buildAuditTrail()`, identical shape to the per-object path), then sealed into the
  ADR-003 hash chain in ascending id order.
- Ids the batch lookup cannot resolve fall back to the legacy per-id pipeline.
  RESTRICT/referential-integrity analysis, soft-delete-only behaviour, recursion
  semantics (children are sub-deletions and never cascade further) and the
  schema-delete-cascade behaviour are unchanged.

### Deliberate, documented behaviour deltas

- The `size` column of cascade-tagged audit rows is now computed once from the object
  snapshot (like every other audit row). Previously the post-insert UPDATE recomputed
  it from the audit entity's own serialisation — an artifact of the double write.
- The hash-chain seal of cascade-tagged audit rows now verifies (previously broken by
  the post-seal UPDATE).

## Impact

- Affected code: `lib/Service/ObjectService.php` (`deleteObjects` + 2 helpers),
  `lib/Service/Object/DeleteObject.php` (`delete`, `deleteObject`,
  `resolveDeletionContext`, `cascadeDeleteObjects` + 5 helpers),
  `lib/Db/MagicMapper.php` (`softDeleteMultipleObjectEntities` + 2 helpers),
  `lib/Db/AuditTrailMapper.php` (`createAuditTrail` split into build+insert,
  `insertAuditTrails` + 2 helpers).
- Affected specs: `object-lifecycle` (batched resolution requirements),
  `deletion-audit-trail` (single-INSERT cascade tagging, bulk hash-chained insert).
- Query cost, N-object bulk delete (no cascade): ~7N+4 → ~4N+3 (all cross-table UNION
  scans collapse into one). M-child legacy cascade: ~10M → ~3M+3 (per-table row
  UPDATEs and the audit INSERT collapse to one statement each; the per-row hash-chain
  seal remains, as today).
- No API surface change; all new parameters are optional with legacy defaults.

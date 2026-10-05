---
kind: code
---

# Batch referential-integrity CASCADE deletions

## Why

`optimize-delete-path-performance` (PR #405) batched the legacy `cascade: true`
delete path: one batched cross-table target resolution, one
`UPDATE ... SET _deleted = CASE _uuid ... WHERE _uuid IN (...)` per magic table
(`MagicMapper::softDeleteMultipleObjectEntities()`), and one multi-row audit
INSERT (`AuditTrailMapper::insertAuditTrails()` with rows built through
`buildAuditTrail()`).

The referential-integrity CASCADE path did not get the same treatment.
`ReferentialIntegrityService::applyBatchCascadeDelete()` still resolves and
audits per object: `MagicMapper::deleteObjects()` runs a full
`findAcrossAllSources()` cross-magic-table scan **per UUID** just to regroup
targets whose register/schema the `DeletionAnalysis` already carries, and every
cascade target gets its own single-row `AuditTrailMapper::insert()`. For N
CASCADE targets that is ~N cross-table UNION scans + N audit INSERTs — inside
the delete transaction opened by `DeleteObject::executeIntegrityTransaction()`,
so concurrent writers block for the duration (issue #409).

## What Changes

### `ReferentialIntegrityService::applyBatchCascadeDelete()` (CASCADE targets only)

- Resolve **all** CASCADE targets with ONE batched cross-table lookup
  (`MagicMapper::findMultipleAcrossAllMagicTables(uuids, includeDeleted: false)`),
  exactly like the legacy-cascade batch path.
- Soft-delete the resolved targets via
  `MagicMapper::softDeleteMultipleObjectEntities()` — one
  `UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)` per magic
  table, with per-target deletion metadata
  (`deletedBy`/`deletedAt`/`objectId`/`organisation`, the same attribution
  shape `DeleteObject::delete()` and the legacy-cascade batch write). The
  previously unused `$organisationId` parameter of `applyDeletionActions()` now
  feeds the `organisation` attribution field.
- Write ONE multi-row audit INSERT via
  `AuditTrailMapper::insertAuditTrails()` with rows pre-built through
  `buildAuditTrail(old: $entity, new: null, action:
  'referential_integrity.cascade_delete', cascadeContext: ...)`. The cascade
  context carries the exact key shape `createAuditTrail()`'s folding uses:
  `triggerObject` (root UUID), `triggerSchema` (root schema slug),
  `action_type` (`referential_integrity.cascade_delete`) and `property` (the
  referencing property from the analysis target). One audit row per analysis
  target is preserved (a target referenced through two properties still gets
  two rows).
- Targets the uuid-based batch lookup cannot resolve — and every target when
  the batch resolve or the batched soft-delete write fails — fall back to the
  unchanged legacy per-object pipeline
  (`MagicMapper::deleteObjects()` + per-target single-row audit insert),
  preserving prior behaviour byte-for-byte on the fallback path (fail-soft,
  like #405).

### Preserved exactly

- RESTRICT, SET_NULL and SET_DEFAULT handling in `applyDeletionActions()` is
  untouched (per-target `findAcrossAllSources` + `update` + single audit row).
- Execution order: SET_NULL → SET_DEFAULT → CASCADE (deepest first — the
  cascade target list stays reversed), unchanged recursion/analysis semantics
  (`canDelete()` / `walkDeletionGraph()` untouched).
- Audit writes stay fail-soft: a failed audit build/insert logs a warning and
  never aborts the cascade.
- Cache invalidation: the integrity cascade path performed no per-target cache
  invalidation before and performs none now (the root object's invalidation in
  `DeleteObject` is untouched).

### Deliberate, documented behaviour deltas (batch path only)

- Per-target `ObjectUpdatingEvent`/`ObjectUpdatedEvent` pairs are now
  dispatched by `softDeleteMultipleObjectEntities()` (a hook stopping
  propagation skips that target's write and it is NOT retried per-object; a
  payload-modifying hook routes that target through the full-row save). The
  previous `deleteObjectsByUuids()` write dispatched no events at all — the
  batch path now matches the event contract PR #405 established for the
  legacy cascade.
- Batch-handled audit rows are built by the shared `buildAuditTrail()` row
  builder: full row shape (session/request/ip/size/object id), hash-chain
  sealed via the batched `insertAuditTrails()` pass, and the cascade details
  live in `changed.cascadeContext` (the shipped single-INSERT cascade-context
  fold) instead of the ad-hoc
  `{deletedBecause, triggerObject, triggerSchema, property}` map. Action stays
  `referential_integrity.cascade_delete`. Fallback-path rows keep the old
  shape unchanged.
- Batch-handled targets get `_deleted` attribution metadata
  (`deletedBy`/`deletedAt`/`objectId`/`organisation`) instead of
  `deleteObjectsByUuids()`'s generic
  `{time, user, reason: 'Bulk soft delete...', retention}` marker — matching
  the root delete and the legacy-cascade batch.

## Impact

- Affected code: `lib/Service/Object/ReferentialIntegrityService.php`
  (`applyDeletionActions`, `applyBatchCascadeDelete` + 3 helpers; the old
  per-object body survives verbatim as `applyPerObjectCascadeDelete()`).
- Affected specs: `object-lifecycle` (batched integrity-cascade requirement),
  `deletion-audit-trail` (single multi-row INSERT for integrity cascade rows).
- Query cost for N CASCADE targets spread over T magic tables:
  ~N cross-table UNION scans + T `UPDATE`s + N single-row audit INSERTs
  → 1 UNION lookup + T row fetches + T `UPDATE`s + 1 multi-row audit INSERT
  (+ the unchanged batched hash-chain seal).
- No API surface change; `applyDeletionActions()` signature is unchanged.

# Tasks — Batch referential-integrity CASCADE deletions

Scope note: pure backend performance change with preserved contracts — no new
schemas/registers (no seed-data task), no lifecycle/aggregation/notification/
widget behaviour (ADR-031 declarative-vs-imperative guidance does not apply).

## 1. Batch the CASCADE path in ReferentialIntegrityService

- [x] 1.1 `applyBatchCascadeDelete()`: resolve all CASCADE target UUIDs with ONE
      `MagicMapper::findMultipleAcrossAllMagicTables(uuids, includeDeleted: false)`
      call; a resolve failure logs a warning and routes every target to the
      per-object fallback.
- [x] 1.2 Soft-delete resolved targets via
      `MagicMapper::softDeleteMultipleObjectEntities()` (one CASE-UPDATE per magic
      table, per-object updating/updated event pairs) with
      `deletedBy`/`deletedAt`/`objectId`/`organisation` attribution metadata;
      thread `applyDeletionActions()`'s `$organisationId` into the attribution.
- [x] 1.3 Write ONE multi-row audit INSERT via
      `AuditTrailMapper::insertAuditTrails()` with rows pre-built through
      `buildAuditTrail(old, null, 'referential_integrity.cascade_delete',
      cascadeContext: {triggerObject, triggerSchema, action_type, property})` —
      one row per analysis target, fail-soft on build and insert failures.
- [x] 1.4 Keep the previous per-object pipeline verbatim as
      `applyPerObjectCascadeDelete()` and route batch misses, resolve failures and
      total batch-write failures through it.
- [x] 1.5 Leave RESTRICT / SET_NULL / SET_DEFAULT handling, execution order
      (SET_NULL → SET_DEFAULT → CASCADE deepest-first) and the analysis walk
      untouched.

## 2. Tests

- [x] 2.1 Batched CASCADE path: N targets → 1 batched resolve + 1
      `softDeleteMultipleObjectEntities` call + 1 `insertAuditTrails` call; no
      `deleteObjects()` / per-row `insert()` calls.
- [x] 2.2 Audit-row shape: `buildAuditTrail()` receives the deleted entity,
      `new: null`, action `referential_integrity.cascade_delete` and the exact
      cascade-context key shape; duplicate targets (same uuid, two properties)
      produce two rows but one soft-delete.
- [x] 2.3 Fallbacks: batch-resolve miss → that target goes through
      `deleteObjects()` + per-target audit insert (old changed-shape asserted);
      resolve failure and batched-write failure → all targets fall back; audit
      insert failure is fail-soft; hook-skipped targets are not force-retried.
- [x] 2.4 Existing RESTRICT / SET_NULL / SET_DEFAULT and integration tests stay
      green unchanged.

## 3. Verification

- [x] 3.1 phpcs / phpmd / phpstan / psalm clean on touched files.
- [x] 3.2 Run the referential-integrity, delete and audit unit test families.
- [x] 3.3 `openspec validate batch-integrity-cascade-deletes --strict`.

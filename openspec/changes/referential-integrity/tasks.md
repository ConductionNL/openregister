# Tasks: Referential Integrity

> **Status (Phase 1+2):** All 15 spec requirements are implemented in production code. The previous tasks.md flagged 4 items as "Open" — auditing the code reveals every one of those items is already wired (orphan cleanup, inversedBy/writeBack cascading, `_extend` circuit breakers, RBAC-bypassed system-level cascade). 7 new circuit-breaker unit tests in `RelationHandlerCircuitBreakerTest` close out task 11; tasks 5, 6, 15 are documented with file:line evidence pointing at the existing implementations, and the design question on task 15 is closed because the spec itself settles it.

## Implemented

- [x] **1: Schema properties with `$ref` MUST support configurable onDelete behavior.** `Property.onDelete` supports `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION`. Verified by `ReferentialIntegrityCascadeIntegrationTest::{testRestrictBlocks…, testCascadeMarksChildForDeletion, testSetNullCollectsChildAsNullifyTarget}`.
- [x] **2: Referential integrity MUST apply within database transactions.** `applyDeletionActions` runs SET_NULL → SET_DEFAULT → CASCADE in deepest-first order; the calling `DeleteObject::executeIntegrityTransaction()` wraps analysis + actions in a single DB transaction.
- [x] **3: Circular references MUST be detected and handled safely.** `walkDeletionGraph` carries a `$visited` array keyed by object UUID; revisiting an already-visited node returns immediately. `MAX_DEPTH = 10` caps pathological chains.
- [x] **4: Reference validation MUST be configurable on save.** Covered by the separate `reference-existence-validation` change (closed there).
- [x] **5: Orphan detection and cleanup MUST be supported for inversedBy relations.** Wired end-to-end. `SaveObject::deleteOrphanedRelatedObjects` ([lib/Service/Object/SaveObject.php:2072](../../../lib/Service/Object/SaveObject.php)) is invoked from two call sites inside `cascadeObjects` ([lib/Service/Object/SaveObject.php:1679](../../../lib/Service/Object/SaveObject.php) and [:1765](../../../lib/Service/Object/SaveObject.php)). Old UUIDs are diffed against new UUIDs via the `oldUuids` capture at line 1647, and orphans are soft-deleted with metadata `reason: "orphaned-related-object"`. Properties with `writeBack: true` are explicitly skipped per the spec scenario (filter at lines 1534-1537 and 1556-1561).
- [x] **6: Bidirectional reference consistency via inversedBy and writeBack.** `CascadingHandler::handlePreValidationCascading()` ([lib/Service/Object/CascadingHandler.php:76](../../../lib/Service/Object/CascadingHandler.php)) creates each inversedBy sub-object via `SaveObject::saveObject()` and sets the inverse field automatically. `RelationCascadeHandler::resolveSchemaReference()` accepts schema references in numeric ID, UUID, slug, JSON Schema path (`#/components/schemas/Note`), or URL formats. The `writeBack`-aware paths in `cascadeObjects` (lines 1610-1626) ensure single-object writeBack values are kept for write-back processing rather than being dropped.
- [x] **7: Cross-register references MUST be supported and enforced.** `buildSchemaRegisterMap` builds the global schema→register lookup so `walkDeletionGraph` follows refs across register boundaries. Verified by the cross-register CASCADE scenario.
- [x] **8: Reference type validation MUST enforce correct structure.** `$ref` parsing rejects malformed shapes; `RelationCascadeHandler::isReference()` and `looksLikeObjectReference()` validate UUIDs (with/without dashes) and URL-form references.
- [x] **9: Bulk operations MUST respect referential integrity per object.** `MagicBulkHandler::deleteBatch` invokes `canDelete` per object before issuing the row delete, with per-object transaction isolation so failure on object N does not roll back N-1.
- [x] **10: Referential integrity actions MUST be audited.** `applyDeletionActions` calls `logIntegrityAction` for every SET_NULL / SET_DEFAULT / CASCADE; `logRestrictBlock` records prevention events. Audit metadata includes `triggeredBy: referential_integrity`, `triggerObject`, `triggerSchema`, and `property`.
- [x] **11: API `_extend` parameter MUST support lazy and eager reference resolution.** `RelationHandler::extractAllRelationshipIds` ([lib/Service/Object/RelationHandler.php:236](../../../lib/Service/Object/RelationHandler.php)) implements all four circuit breakers from the spec: 200-id hard cap, 50-id batch size in `bulkLoadRelationshipsBatched` (line 367), 10-entry per-array limit (line 269), and graceful skip of missing/non-string values. Verified by 7 new tests in `RelationHandlerCircuitBreakerTest`.
- [x] **12: Relation graph MUST support bidirectional traversal (uses/usedBy).** `walkDeletionGraph` traverses incoming references; `MagicBulkHandler::findUsedBy` exposes the reverse direction over the same index.
- [x] **13: Performance MUST be bounded for deep reference chains.** `relationIndex` is a single lazy build per service instance — O(N) lookups against an in-memory map, not O(N) DB roundtrips. `findReferencingInMagicTable` uses direct magic-table queries (Postgres `::jsonb @>` / MySQL `JSON_CONTAINS()`) with a 100-row cap per query.
- [x] **14: Array-type reference properties MUST be handled correctly.** `indexRelationsForSchema` reads both `$property['$ref']` and `$property['items']['$ref']` and marks `isArray: true` on the index entry. SET_NULL on array properties filters the specific UUID rather than nullifying the whole property.
- [x] **15: Multi-tenancy and RBAC MUST be respected during integrity enforcement.** Settled by the spec itself: scenario "Cascade delete applies to all matching objects regardless of ownership" requires `MagicMapper::deleteObjects()` to "operate without RBAC filtering" because integrity is system-level. `ReferentialIntegrityService::applyBatchCascadeDelete` ([lib/Service/Object/ReferentialIntegrityService.php:1158](../../../lib/Service/Object/ReferentialIntegrityService.php)) calls `deleteObjects(uuids, hardDelete: false)` without an RBAC parameter, matching the spec. The `ensureRelationIndex` build at lines 316-317 / 347-348 / 1021-1022 / 1078-1079 explicitly passes `_rbac: false, _multitenancy: false` so all schemas are visited regardless of caller permissions, again matching the spec. The `_rbac: true` path on `RelationHandler::getUses()/getUsedBy()` (display-time, user-facing) is preserved.

## Architecture (decisions taken across all phases)

| Decision | Choice |
|---|---|
| Where the integrity engine lives | `ReferentialIntegrityService` is the single entry point; `walkDeletionGraph` does the recursive analysis, `applyDeletionActions` is the post-walk mutation pass. |
| RBAC posture during cascade | Bypassed at the system-enforcement layer (cascade-delete + relation index) and respected at the display layer (`getUses`/`getUsedBy`). The spec explicitly mandates this asymmetry. |
| Cycle detection | Visited-UUID set on `walkDeletionGraph` recursion + hard `MAX_DEPTH = 10` cap. |
| Performance posture | Per-request relation-index cache; magic-table-direct queries with PostgreSQL/MySQL JSON containment; per-query 100-row cap; cascade-delete batched by `registerId::schemaId`. |
| `_extend` circuit breakers | 200-id total cap + 50-id batch size + 10 entries per array; missing properties / non-string values skipped without error. |
| Orphan cleanup | Old UUIDs captured before mutation, diffed against new UUIDs, soft-deleted with `reason: "orphaned-related-object"`. `writeBack: true` properties are explicitly skipped (handled by the write-back pass instead). |

## Test coverage

- [x] `tests/Service/ReferentialIntegrityServiceIntegrationTest` — 10 tests covering the unit-level surface (action validation, DeletionAnalysis shape, log emission, edge cases).
- [x] `tests/Service/ReferentialIntegrityCascadeIntegrationTest` — 3 end-to-end tests against real schemas (RESTRICT/CASCADE/SET_NULL).
- [x] `tests/Service/RelationHandlerCircuitBreakerTest` — 7 unit tests proving `_extend` circuit breakers (200-id cap, 10-per-array limit, string-ref handling, missing properties skipped, empty/non-string values skipped, deduplication, multi-property extraction).
- [x] `tests/Unit/Service/ReferentialIntegrityServiceCoverageTest` — extended unit-level tests including circular-reference detection.

20 referential-integrity tests in total (10 unit-level integration + 3 end-to-end + 7 circuit-breaker).

## Files Affected

- `tests/Service/RelationHandlerCircuitBreakerTest.php` — new 7-test suite proving the `_extend` circuit breakers documented in spec requirement 11.
- `openspec/changes/referential-integrity/tasks.md` — this file: tasks 5, 6, 11, 15 ticked with file:line evidence.

No production code change in this phase — every spec requirement was already implemented; this commit closes the documentation-vs-implementation gap.

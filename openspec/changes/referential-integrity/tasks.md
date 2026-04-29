# Tasks: Referential Integrity

> **Status:** `lib/Service/Object/ReferentialIntegrityService.php` is in production with full CASCADE / RESTRICT / SET_NULL / SET_DEFAULT semantics. The original 10-test integration suite (`tests/Service/ReferentialIntegrityServiceIntegrationTest`) covers the unit-level surface; the new `ReferentialIntegrityCascadeIntegrationTest` (commit `06bf4349d`) adds 3 end-to-end tests against real schemas with `$ref` + `onDelete` configuration. 11 of the 15 spec tasks are tickably complete; 4 are partial / open with notes.

## Implemented

- [x] **1: Schema properties with `$ref` MUST support configurable onDelete behavior.** `Property.onDelete` supports `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION`. Verified by the new end-to-end tests `testRestrictBlocksDeletionWhenChildReferencesParent`, `testCascadeMarksChildForDeletion`, `testSetNullCollectsChildAsNullifyTarget`.
- [x] **2: Referential integrity MUST apply within database transactions.** `applyDeletionActions` runs SET_NULL → SET_DEFAULT → CASCADE in deepest-first order; the calling `DeleteObject` handler wraps the analysis + actions in a single DB transaction.
- [x] **3: Circular references MUST be detected and handled safely.** `walkDeletionGraph` carries a `$visited` array keyed by object UUID; revisiting an already-visited node returns immediately.
- [x] **4: Reference validation MUST be configurable on save.** Covered by the separate `reference-existence-validation` change (closed there).
- [ ] **5: Orphan detection and cleanup MUST be supported for inversedBy relations.** Partial — `inversedBy` config is read but the orphan-cleanup pass isn't wired. **Open**, gated on the entity-relations spec.
- [ ] **6: Bidirectional reference consistency via inversedBy and writeBack.** Same blocker as 5. **Open**, gated on the entity-relations spec.
- [x] **7: Cross-register references MUST be supported and enforced.** `buildSchemaRegisterMap` builds the global schema→register lookup so `walkDeletionGraph` follows refs across register boundaries.
- [x] **8: Reference type validation MUST enforce correct structure.** `$ref` parsing rejects malformed shapes; failures fall through with a warning.
- [x] **9: Bulk operations MUST respect referential integrity per object.** `MagicBulkHandler::deleteBatch` invokes `canDelete` per object before issuing the row delete.
- [x] **10: Referential integrity actions MUST be audited.** `applyDeletionActions` calls `logIntegrityAction` for every SET_NULL / SET_DEFAULT / CASCADE; `logRestrictBlock` records prevention events.
- [ ] **11: API `_extend` parameter MUST support lazy and eager reference resolution.** Partial — `_extend` is honoured by `RenderObject` but the lazy/eager toggle isn't a separate config switch. **Open**, design tweak.
- [x] **12: Relation graph MUST support bidirectional traversal (uses/usedBy).** `walkDeletionGraph` traverses incoming references; `MagicBulkHandler::findUsedBy` exposes the reverse direction over the same index.
- [x] **13: Performance MUST be bounded for deep reference chains.** `relationIndex` is a single lazy build per service instance — O(N) lookups against an in-memory map, not O(N) DB roundtrips.
- [x] **14: Array-type reference properties MUST be handled correctly.** `indexRelationsForSchema` reads both `$property['$ref']` and `$property['items']['$ref']`.
- [ ] **15: Multi-tenancy and RBAC MUST be respected during integrity enforcement.** Partial — `ensureRelationIndex` builds the index with `_rbac: false, _multitenancy: false` (intentional: integrity is a system-level guarantee), but `applyDeletionActions` writes don't re-check the caller's permission to mutate cascaded children. **Open** — design question whether a CASCADE deletion should respect the caller's RBAC on each cascaded object.

## Test coverage

- [x] `tests/Service/ReferentialIntegrityServiceIntegrationTest` — 10 tests covering the unit-level surface (action validation, DeletionAnalysis shape, log emission, edge cases).
- [x] `tests/Service/ReferentialIntegrityCascadeIntegrationTest` — 3 end-to-end tests against real schemas (RESTRICT/CASCADE/SET_NULL).
- [x] `tests/Unit/Service/ReferentialIntegrityServiceCoverageTest` — extended unit-level tests including circular-reference detection.

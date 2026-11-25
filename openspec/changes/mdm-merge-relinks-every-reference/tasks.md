# Tasks: mdm-merge-relinks-every-reference

## 1. Relink and reverse

- [x] 1.1 `ReferenceRelinker::plan()` and `apply()` over the relation index, scalar, array and relation-row moves, under the actor's rights, 5,000 cap. Verify: `tests/Unit/Service/Merge/ReferenceRelinkerTest.php` with a module referenced from a suite array, a connection scalar and one object the actor may not update.
- [x] 1.2 `executeMerge()` runs the relinker after the configured relink and records moves in the snapshot; `reverseMerge()` restores unchanged moves. Verify: `MergeServiceTest` merge-then-reverse leaves every reference as before, and a reference edited in between keeps the edit.
  - Done: concrete `RelationIndexReferenceFinder` (relation index via `MagicRelationReaderInterface::findByRelation()`, never the record itself) and `SavePathReferenceWriter` (read and save with RBAC and multitenancy; any refusal leaves the object). Both are bound in `ContractBindings`. `MergeService::merge()` refuses a merge over the cap before anything is written, then runs the relinker after the configured relink and leaves out the survivor's own fields, which the merge saves from its recomputed payload. The applied moves go into `preMergeSnapshot.referenceMoves`; `references` (by schema and count) and `skippedReferences` come back in the result. `reverseMerge()` restores each move whose field still holds the merged value, and reports the rest as `keptReferenceEdits`. Also fixed `ReferenceRelinker::rewriteList()`: with the loser before the survivor, the survivor was listed twice. Tests: `MergeServiceReferenceRelinkTest` (6, over an in-memory store), `ReferenceFinderWriterTest` (6), and a new `ReferenceRelinkerTest` case.
- [x] 1.3 Preview lists references by schema and count. Verify: `MergeControllerTest` for the preview shape.
  - Done: `previewMerge()` returns `references: [{schema, count}]` from the same plan, without writing. Tests: `MergeServiceReferenceRelinkTest::testThePreviewListsTheReferencesBySchemaWithoutWriting` and `MergeControllerTest::testPreviewListsTheReferencesBySchemaAndCount`.

## 2. Page

- [x] 2.1 `/duplicates?register=&schema=` preselects the pair, slugs or ids, notice on unknown values. Verify: component test for both, and a Playwright step in 3.1.
  - Done: the shared `RegisterSchemaSelector` resolves each query value by id, uuid or slug against the loaded registers and that register's schemas, and selects their ids, so `DuplicatesIndex` (and the other data quality pages on the picker) load that pair with no clicks. An unknown register clears the selection and an unknown schema keeps only the register; either shows a warning notice naming the value (existing keys `Unknown Register` / `Unknown Schema`, `Choose a register` / `Choose a schema`) and nothing is loaded or guessed. The merge wizard lists the preview's `references` by schema (title where the store knows it) and count under `Referenced by` (existing keys; design D-3). Tests: `DuplicatesIndex.spec.js` (slug, id, unknown register, unknown schema), `RegisterSchemaSelector.spec.js` (route cases reworked and extended), `MdmMergeWizardModal.spec.js` (reference list). The Playwright step is part of 3.1, still open.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/merge-relinks-references.spec.ts`: open `/duplicates` with query parameters, merge two modules referenced from a suite, reverse the merge, and assert the suite's list each time.
- [x] 3.2 Document relink, reversal and the deep link in `docs/`.
  - Done: `docs/Features/master-data-management.md` gains "Merging duplicates": the endpoints, the relink of every reference (scalar, list, nested; as the steward; `skippedReferences`), the 5,000 cap, the preview's `references`, reversal with `keptReferenceEdits`, and the `/duplicates?register=&schema=` deep link. `docs/user/finding-and-merging-objects.md` points to it.

Acceptance:
- After a merge, no object the actor may update still points at the losing record.

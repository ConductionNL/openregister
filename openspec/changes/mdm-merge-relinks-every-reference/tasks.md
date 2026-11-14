# Tasks: mdm-merge-relinks-every-reference

## 1. Relink and reverse

- [x] 1.1 `ReferenceRelinker::plan()` and `apply()` over the relation index, scalar, array and relation-row moves, under the actor's rights, 5,000 cap. Verify: `tests/Unit/Service/Merge/ReferenceRelinkerTest.php` with a module referenced from a suite array, a connection scalar and one object the actor may not update.
- [x] 1.2 `executeMerge()` runs the relinker after the configured relink and records moves in the snapshot; `reverseMerge()` restores unchanged moves. Verify: `MergeServiceTest` merge-then-reverse leaves every reference as before, and a reference edited in between keeps the edit.
  - Done: concrete `RelationIndexReferenceFinder` (relation index via `MagicRelationReaderInterface::findByRelation()`, never the record itself) and `SavePathReferenceWriter` (read and save with RBAC and multitenancy; any refusal leaves the object). Both are bound in `ContractBindings`. `MergeService::merge()` refuses a merge over the cap before anything is written, then runs the relinker after the configured relink and leaves out the survivor's own fields, which the merge saves from its recomputed payload. The applied moves go into `preMergeSnapshot.referenceMoves`; `references` (by schema and count) and `skippedReferences` come back in the result. `reverseMerge()` restores each move whose field still holds the merged value, and reports the rest as `keptReferenceEdits`. Also fixed `ReferenceRelinker::rewriteList()`: with the loser before the survivor, the survivor was listed twice. Tests: `MergeServiceReferenceRelinkTest` (6, over an in-memory store), `ReferenceFinderWriterTest` (6), and a new `ReferenceRelinkerTest` case.
- [x] 1.3 Preview lists references by schema and count. Verify: `MergeControllerTest` for the preview shape.
  - Done: `previewMerge()` returns `references: [{schema, count}]` from the same plan, without writing. Tests: `MergeServiceReferenceRelinkTest::testThePreviewListsTheReferencesBySchemaWithoutWriting` and `MergeControllerTest::testPreviewListsTheReferencesBySchemaAndCount`.

## 2. Page

- [ ] 2.1 `/duplicates?register=&schema=` preselects the pair, slugs or ids, notice on unknown values. Verify: component test for both, and a Playwright step in 3.1.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/merge-relinks-references.spec.ts`: open `/duplicates` with query parameters, merge two modules referenced from a suite, reverse the merge, and assert the suite's list each time.
- [ ] 3.2 Document relink, reversal and the deep link in `docs/`.

Acceptance:
- After a merge, no object the actor may update still points at the losing record.

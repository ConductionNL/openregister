# Tasks: mdm-merge-relinks-every-reference

## 1. Relink and reverse

- [ ] 1.1 `ReferenceRelinker::plan()` and `apply()` over the relation index, scalar, array and relation-row moves, under the actor's rights, 5,000 cap. Verify: `tests/Unit/Service/Merge/ReferenceRelinkerTest.php` with a module referenced from a suite array, a connection scalar and one object the actor may not update.
- [ ] 1.2 `executeMerge()` runs the relinker after the configured relink and records moves in the snapshot; `reverseMerge()` restores unchanged moves. Verify: `MergeServiceTest` merge-then-reverse leaves every reference as before, and a reference edited in between keeps the edit.
- [ ] 1.3 Preview lists references by schema and count. Verify: `MergeControllerTest` for the preview shape.

## 2. Page

- [ ] 2.1 `/duplicates?register=&schema=` preselects the pair, slugs or ids, notice on unknown values. Verify: component test for both, and a Playwright step in 3.1.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/merge-relinks-references.spec.ts`: open `/duplicates` with query parameters, merge two modules referenced from a suite, reverse the merge, and assert the suite's list each time.
- [ ] 3.2 Document relink, reversal and the deep link in `docs/`.

Acceptance:
- After a merge, no object the actor may update still points at the losing record.

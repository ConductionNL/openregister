# Tasks: retention-linked-destruction-conflict

## 1. Finder

- [ ] 1.1 Add `lib/Service/Archival/LinkedRetentionConflictFinder.php`: outgoing links from `relations`, incoming links with one `findByRelationBatchInSchema()` per referencing schema, 50 links each way per entry, and the five conflict kinds in design D-2 with their direction. Verify: `tests/Unit/Service/Archival/LinkedRetentionConflictFinderTest.php` covers each kind, a linked record on the same list ignored, the truncation flag at 51 links, and one query per referencing schema asserted on the mapper double.

## 2. On the list

- [ ] 2.1 Call the finder in `RetentionService::createDestructionList()` and store `linkConflicts`, `linkConflictsTruncated` per entry and `linkConflictCount` per list. Verify: `tests/Unit/Service/RetentionServiceLinkConflictsTest.php`; a list with no conflicts carries empty arrays and a count of 0.
- [ ] 2.2 Recompute conflicts for the returned entries in `GET /api/archival/destruction-lists/{id}` and `GET /api/archival/reviews/pending`, with `linkConflictsCheckedAt`, without saving. Verify: `tests/Unit/Controller/ArchivalControllerLinkConflictsTest.php` asserts a conflict that appeared after creation is shown and the stored list is unchanged.

## 3. Decision and approval

- [ ] 3.1 Add `DestructionReviewService::assertConflictsAcknowledged()` and `LinkConflictsNotAcknowledgedException`, called at the top of `ArchivalController::recordDecision()` before `outcomes->apply()`; record `overriddenConflicts` in the decision and its count in the `archival.review_decided` audit row. Verify: `tests/Unit/Service/Archival/DestructionReviewConflictTest.php` asserts 422 with the conflicts, that `apply()` is never called on a refusal, and that an acknowledged destroy records the conflicts.
- [ ] 3.2 Add `destroyedOverConflict` to the approval `DestructionService::approveList()` records. Verify: `tests/Unit/Service/Archival/DestructionServiceApproveTest.php` counts two overridden entries.

## 4. Docs and end-to-end test

- [ ] 4.1 Document the conflict kinds, the refresh at review, the acknowledgement and the approval count in `docs/features/archival-destruction.md`, including the JSON shape consumers render. Verify: `npm run build` in `docs/` succeeds.
- [ ] 4.2 Add `tests/e2e/ci/destruction-linked-conflict.spec.ts`: seed a case with a linked decision nominated `bewaren`, run the destruction check, read the list and see the conflict, get 422 on an unacknowledged destroy, then destroy with an acknowledgement and read `overriddenConflicts` in the history. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- No destroy answer on an entry with a conflict is recorded without `acknowledgeConflicts: true` and a reason.
- A refused answer changes nothing about the record.

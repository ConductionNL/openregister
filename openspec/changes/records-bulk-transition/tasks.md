# Tasks: records-bulk-transition

## 1. Engine and action

- [ ] 1.1 Optional `?IUser $actor` on `TransitionEngine::transition()` used for the permission check and attribution. Verify: `TransitionEngineTest` with a session user and a different explicit actor, asserting the actor's rights decide.
- [ ] 1.2 `TransitionAction` (`openregister:transition`) with preview, commit, outcome mapping and the homogeneity guard; registered in `BulkActionRegistrationListener`. Verify: `tests/Unit/BulkAction/TransitionActionTest.php` for applied, skipped (already there), failed (not available, missing input, stopped by a listener).

## 2. Proof and docs

- [ ] 2.1 Newman: `POST /api/bulk-jobs` with `openregister:transition` over three objects, one already in the target state and one lacking a required input; read the per-object outcomes.
- [ ] 2.2 Add `tests/e2e/ci/bulk-transition.spec.ts`: select three records on an index page and move them through the bulk dialog.
- [ ] 2.3 Document the action in `docs/` beside the bulk jobs.

Acceptance:
- A bulk move and a single move of the same object produce the same audit entry and the same lifecycle actions.

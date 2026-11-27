# Tasks: records-bulk-transition

## 1. Engine and action

- [x] 1.1 Optional `?IUser $actor` on `TransitionEngine::transition()` used for the permission check and attribution. Verify: `TransitionEngineTest` with a session user and a different explicit actor, asserting the actor's rights decide.
- [x] 1.2 `TransitionAction` (`openregister:transition`) with preview, commit, outcome mapping and the homogeneity guard; registered in `BulkActionRegistrationListener`. Verify: `tests/Unit/BulkAction/TransitionActionTest.php` for applied, skipped (already there), failed (not available, missing input, stopped by a listener).

## 2. Proof and docs

- [x] 2.1 Newman: `POST /api/bulk-jobs` with `openregister:transition` over three objects, one already in the target state and one lacking a required input; read the per-object outcomes.
  - [x] Written: `tests/integration/openregister-bulk-transition.postman_collection.json`. Inputs are checked per action, not per object, and the homogeneity guard keeps a job on one schema, so the three-object job covers applied, skipped and a move not available from the object's state, and a second job covers the missing required input.
  - [x] First live Newman pass: the commit needs a worker for `BulkJobRunner`, which `tests/integration/ci/seed.sh` does not start; the collection carries `@newman exclude` until then.
    - 2026-11-25: 37 requests and 38 assertions green on a sandbox Nextcloud 34 on MariaDB with a background-job loop beside Newman. The collection had never run: its request URLs were objects with only `raw`, which Newman reads as an empty URL (now plain strings), and the anonymous refusal ran on the session cookie the earlier requests left in Newman's jar, so it answered 201 (now `disableCookies` on that request; a cookieless call answers 401). The `@newman exclude` marker stays until the CI stack runs a `BulkJobRunner` worker.
- [ ] 2.2 Add `tests/e2e/ci/bulk-transition.spec.ts`: select three records on an index page and move them through the bulk dialog.
- [x] 2.3 Document the action in `docs/` beside the bulk jobs.
  - `docs/api/bulk-operations.md` (a Bulk Jobs section with `openregister:transition`) and a pointer from the lifecycle section of `docs/features/workflow-automation.md`.

Acceptance:
- A bulk move and a single move of the same object produce the same audit entry and the same lifecycle actions.

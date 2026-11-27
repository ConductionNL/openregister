# Tasks: lifecycle-over-reference-field

## 1. Declaration

- [x] 1.1 Validate `form`, `sideMoves` and `reopen` in the lifecycle
      annotation at schema save.

## 2. Engine

- [x] 2.1 Transition endpoint accepts form values, validates and saves them
      with the move; audit entry lists them.
- [x] 2.2 Side moves offered by the second field's state; graph moves
      refused while suspended.
- [x] 2.3 Reopen from a terminal state, guarded, audited.
- [x] 2.4 `availableActions()` carries each action's form.

## 3. Tests

- [x] 3.1 Unit tests for validation, the suspended freeze, reopen guard and
      the single-save form write.
- [x] 3.2 `tests/e2e/api-direct/lifecycle-graph-side-moves.spec.ts`: seed a
      case and status types, suspend, see graph moves gone, resume, close
      with a result, reopen as manager.
      - Built as the Newman collection
        `tests/integration/openregister-lifecycle-side-moves.postman_collection.json` (domain
        `lifecycle-side-moves` in `tests/newman/run-all.sh`), not under `tests/e2e/api-direct/`:
        `playwright.config.ts` excludes api-direct from every project, so a spec there never runs
        (gate-19). It adds the controls: the graph move refused with 422 while suspended, closing
        without `result` refused with 422 naming it, one audit entry with status and result, a
        handler without `manage` offered nothing on the closed case, and the reopen audited as a reopen.
      - 2026-11-25, sandbox Nextcloud 34 on PostgreSQL 16: 35 requests, 54 assertions, 0 failed.
        The first run found that a graph offered no move at all on a real instance: the sibling read
        named a schema but no register, so `MagicMapper` had no table to read ("findAllListed() called
        without register/schema context"). `TransitionEngine::siblingQuery()` now scopes the read to the
        object's register (`testTheSiblingsAreReadInTheCasesRegister`); without that fix the collection
        fails from its first assertion.

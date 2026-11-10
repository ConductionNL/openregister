# Tasks: lifecycle-over-reference-field

## 1. Declaration

- [ ] 1.1 Validate `form`, `sideMoves` and `reopen` in the lifecycle
      annotation at schema save.

## 2. Engine

- [ ] 2.1 Transition endpoint accepts form values, validates and saves them
      with the move; audit entry lists them.
- [ ] 2.2 Side moves offered by the second field's state; graph moves
      refused while suspended.
- [ ] 2.3 Reopen from a terminal state, guarded, audited.
- [ ] 2.4 `availableActions()` carries each action's form.

## 3. Tests

- [ ] 3.1 Unit tests for validation, the suspended freeze, reopen guard and
      the single-save form write.
- [ ] 3.2 `tests/e2e/api-direct/lifecycle-graph-side-moves.spec.ts`: seed a
      case and status types, suspend, see graph moves gone, resume, close
      with a result, reopen as manager.

# Tasks: shared-decision-table-evaluator

## Implementation Tasks

### Task 1: Move the grammar
- **spec_ref**: `openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md#requirement-req-sdt-003-the-unary-test-grammar-is-preserved-intact`
- **files**: `lib/Service/Dmn/UnaryTestEvaluator.php`, `lib/Service/Dmn/DecisionEvaluationException.php`
- **acceptance_criteria**:
  - GIVEN the ported grammar THEN ranges, sets, comparisons and the quoted-literal escape behave as they did in dossiq
  - GIVEN the move THEN it is a MOVE: the file is dossiq's, renamed and re-namespaced, not retyped
- [x] Implement
- [x] Test

### Task 2: The consolidated table evaluator
- **spec_ref**: `.../spec.md#requirement-req-sdt-001-one-evaluator-the-union-of-both-dialects` (+ REQ-SDT-002)
- **files**: `lib/Service/Dmn/DecisionTableEvaluator.php`
- **acceptance_criteria**:
  - GIVEN the hit policies THEN the list is the UNION of both apps', including openbuild's PRIORITY
  - GIVEN PRIORITY THEN the highest wins, ties break by declaration order, and an absent priority is zero
  - GIVEN ANY THEN disagreeing rules are refused and agreeing ones return the shared output
  - GIVEN an unimplemented policy THEN it is refused, never treated as FIRST
- [x] Implement
- [x] Test — mutation-checked: dropping PRIORITY, degrading ANY to FIRST, and making the tie non-deterministic each turn the suite red

### Task 3: The consumers adopt it
- **spec_ref**: deferred
- **acceptance_criteria**:
  - Deliberately out of scope. openbuild and dossiq each adopt this in their own change, with parity tests over their own shipped tables — openbuild's `any` tables in particular change shape. Deleting a working evaluator on the strength of a new one that has not yet run its data would be the same mistake in the other direction.
- [ ] Implement
- [ ] Test

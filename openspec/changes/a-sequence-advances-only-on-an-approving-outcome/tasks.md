# Tasks: a-sequence-advances-only-on-an-approving-outcome

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Regression tests first

- [x] 1.1 `tests/Unit/Service/Task/TaskSequenceServiceTest.php`: an empty outcome, an unknown outcome and `resolved` do not enable the next position or complete the sequence; `done` and `approved` still advance; a declared set overrides the default; a rejecting outcome in a declared set still rejects. Fails on development.
- [x] 1.2 `tests/Unit/Service/Task/TaskStateTest.php` and `tests/Unit/Listener/ApprovalChainGateListenerTest.php`: the approving vocabulary, the compiled `approvingOutcomes`, and a new attempt after an undecided sequence.

## 2. Code

- [x] 2.1 `TaskState::APPROVING_OUTCOMES` and `isApprovingOutcome()` (D-1, D-3); `ApprovingOutcomeSet` normalises a declared set for the compiler and the sequence (D-2).
- [x] 2.2 `TaskSequence::STATUS_UNDECIDED`, `TaskSequenceUndecidedEvent`, and `TaskSequenceService::onTaskTerminal()` closing a sequence as undecided (D-4).
- [x] 2.3 `ApprovalChainAnnotationInstaller::compile()` carries `approvingOutcomes` (D-2).

## 3. Close

- [x] 3.1 `docs/development/approval-events-migration.md`: what approves, and the undecided end. Tell filinq to declare `approvingOutcomes: ["approved"]` on its signing chain.

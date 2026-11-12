# Tasks: consume-follows-the-approving-rule

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Regression tests first

- [x] 1.1 `tests/Unit/Service/Task/TaskSequenceLifecycleVerbsTest.php`: `consume` refuses null, empty, `resolved` and unknown outcomes as undecided; `done` consumes under the default set; a declared set on the task replaces the default; a rejection is not reported as undecided. Fails on the base branch.
- [x] 1.2 `tests/Unit/Service/Task/TaskSequenceServiceTest.php`: a chain's declared set is copied onto every position task; a position without one is unchanged.

## 2. Code

- [x] 2.1 `TaskService::consume()` through `assertApprovingDecision()` (D-2), `TaskState::approvingSetOrDefault()` (D-3), and `TaskSequenceService` copying the set onto each position (D-1).

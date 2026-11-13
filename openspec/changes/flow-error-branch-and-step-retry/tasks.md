# Tasks: flow-error-branch-and-step-retry

## 1. Engine

- [ ] 1.1 `FlowErrorPolicy::for()` used by both walks and `FlowRunWorker`; behaviour unchanged for stop, continue and dead_letter. Verify: existing `FlowEngineTest` cases pass unchanged, plus one per policy through the helper.
- [ ] 1.2 Step retry through `FlowSuspension` with the attempt on the resume state, fixed and exponential delay, caps refused at save. Verify: `tests/Unit/Service/Flow/FlowEngineRetryTest.php` fails twice then succeeds, and a fourth failure with `attempts: 3` counts as failed.
- [ ] 1.3 `ON_ERROR_BRANCH` with `errorTo`, failed items carrying `json.error`, preflight refusal without an `error` edge. Verify: `tests/Unit/Service/Flow/FlowEngineErrorBranchTest.php` with a per-item node where one of three items fails.

## 2. Trace, proof and docs

- [ ] 2.1 Trace entries per attempt and the `branched` outcome. Verify: unit test reads the step report.
- [ ] 2.2 Add `tests/e2e/ci/flow-error-branch.spec.ts`: a flow whose HTTP step calls an unreachable host, with `retry` 2 and an error branch that writes a note; assert the note and three attempts in the trace. (live pass, decision 139)
- [ ] 2.3 Document retry and the error branch in `docs/`, with the idempotency warning.
- [ ] 2.4 Open a nextcloud-vue issue for drawing the `error` output on `CnFlowCanvas`, and link it here.

Acceptance:
- A retry never blocks a worker.
- A branch with no edge is refused at publish, not discovered at run time.

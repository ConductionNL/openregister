# Tasks: flow-code-step-in-a-sidecar

## 1. Runner

- [ ] 1.1 Runner ExApp `flow-code-runner`: Node 22, `isolated-vm`, `POST /run` and `GET /health`, limits from the request, no file system mounts. Verify: the runner's own test suite runs a per-item and an all-items script, a timeout, a memory overrun and a refused `fetch` to an undeclared host.
- [ ] 1.2 Pinned image built in CI with a published digest. Verify: the workflow run shows the digest and the install docs name it.

## 2. Node

- [ ] 2.1 `CodeRunnerClient` over AppAPI with a cached health probe, in the shape of `AnonymisationBackendService`. Verify: unit test with a fake `PublicFunctions` for up, down and error answers.
- [ ] 2.2 `CodeNode` (`openregister.code`) with `validateConfig()` for language, mode, limits against the instance ceiling and egress hosts; registered in `FlowNodeRegistrationListener`. Verify: `tests/Unit/Service/Flow/Nodes/CodeNodeTest.php`.
- [ ] 2.3 Preflight refusal without the runner, and the step report with source, hash, items and logs. Verify: unit tests on `FlowNodePreflight` and on the report.
- [ ] 2.4 `flow.code` right seeded for administrators, checked on flow save. Verify: `FlowControllerTest` saves a code node without the right and reads 403.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/flow-code-step.spec.ts` on a stack with the runner: a flow with a code step that upper-cases a field, run it, and read the trace. (live pass, decision 139)
- [ ] 3.2 Document the node, the runner install and the limits in `docs/`.

Acceptance:
- No authored code runs in the Nextcloud process.
- Without the runner the node is hidden and a flow using it refuses to run.

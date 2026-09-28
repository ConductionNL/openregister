# flow-engine

## ADDED Requirements

### Requirement: A failed step can retry itself before it counts as failed

A step MAY declare `retry` with `attempts` (at most 10), `delaySeconds` (at
most 3,600) and `backoff` (`fixed` or `exponential`). The engine SHALL run a
failed step again after the delay, up to `attempts` extra times, by suspending
the run until the wake time rather than holding a worker. Only the failure of
the last try SHALL reach the step's `onError` policy.

#### Scenario: a flaky service answers on the third try

- **GIVEN** a published flow whose HTTP step declares `retry: { attempts: 3, delaySeconds: 60, backoff: "fixed" }`
- **WHEN** the called service fails twice and answers the third time
- **THEN** the run completes normally
- **AND** the run trace for the step shows three attempts, two failed and one succeeded
- @e2e exclude {specified only; covered by FlowEngineRetryTest in task 1.2}

#### Scenario: limits above the cap are refused

- **GIVEN** a maker saving a step with `retry: { attempts: 50 }`
- **WHEN** the flow is saved through `PUT /api/flows/{id}`
- **THEN** the save is refused naming `retry.attempts` and the cap of 10
- @e2e exclude {API contract; covered by the preflight unit test}

### Requirement: A failed step can send its items down an error branch

A step MAY declare `onError: "branch"`, which SHALL route the items that
failed, each carrying `json.error` with the message, the step and the attempt,
to the edge leaving the node's `error` output, while the items that succeeded
continue on the normal edge. Publishing a flow with `branch` and no `error`
edge SHALL be refused, naming the node.

#### Scenario: a functional administrator is told about a failed record

- **GIVEN** a published flow for integriq where a mapping step has `onError: "branch"` and its `error` edge leads to a notification node
- **WHEN** the flow runs on three items and the mapping fails for one
- **THEN** two items continue on the normal edge and one reaches the notification node with `json.error.message` set
- **AND** the run ends `completed`, and the trace marks the step `branched` with one item
- @e2e exclude {specified only; task 2.2 adds tests/e2e/ci/flow-error-branch.spec.ts}

#### Scenario: a branch to nowhere is refused

- **GIVEN** a flow with a step set to `onError: "branch"` and no edge from its `error` output
- **WHEN** a maker publishes it through `POST /api/flows/{id}/publish`
- **THEN** the publish is refused with a message naming the step and the missing `error` edge
- @e2e exclude {API contract; covered by FlowEngineErrorBranchTest in task 1.3}

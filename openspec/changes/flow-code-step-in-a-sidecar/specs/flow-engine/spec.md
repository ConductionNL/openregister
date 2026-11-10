# flow-engine

## ADDED Requirements

### Requirement: A code step runs authored JavaScript outside Nextcloud

The engine SHALL offer a node `openregister.code` that runs authored
JavaScript on the step's items in the `flow-code-runner` ExApp and never in
the Nextcloud process. The node SHALL enforce its `timeoutMs` and `memoryMb`
limits, capped by the instance ceiling, and SHALL give the code network access
only to the hosts listed in `egress`.

#### Scenario: a developer reshapes items with a code step

- **GIVEN** an administrator with `flow.code` and an instance with `flow-code-runner` installed
- **WHEN** the administrator publishes a flow with an `openregister.code` step in `perItem` mode whose source upper-cases `json.naam`, and runs it on two items
- **THEN** the step returns two items with `naam` upper-cased
- **AND** the run trace for the step records the source, its hash, the items in and the items out
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/flow-code-step.spec.ts}

#### Scenario: code that runs too long is stopped

- **GIVEN** the same flow with `timeoutMs: 1000` and a source that loops forever
- **WHEN** the flow runs
- **THEN** the step fails with a timeout after about one second and the edge's `onError` policy decides what happens next
- @e2e exclude {specified only; covered by the runner test in task 1.1 and the node test in task 2.2}

#### Scenario: code cannot reach an undeclared host

- **GIVEN** a code step with an empty `egress` list whose source calls `fetch("https://example.org")`
- **WHEN** the flow runs
- **THEN** the step fails with a message that network access is not declared, and no request leaves the runner
- @e2e exclude {specified only; covered by the runner test in task 1.1}

### Requirement: Without the runner the code step is hidden and refused

The node catalogue SHALL NOT offer `openregister.code` when the runner does
not answer its health check. Publishing or running a flow that contains the
node without the runner SHALL be refused with a message naming
`flow-code-runner`. Adding or changing a code step SHALL require the
`flow.code` right.

#### Scenario: an instance without the runner

- **GIVEN** an instance without `flow-code-runner`
- **WHEN** a maker opens the node catalogue through `GET /api/flow/node-catalog`
- **THEN** `openregister.code` is not listed
- **AND** publishing an imported flow that contains it is refused naming `flow-code-runner`
- @e2e exclude {API contract; covered by the preflight unit test in task 2.3}

#### Scenario: a maker without the right cannot add code

- **GIVEN** a maker with `flow.edit` but without `flow.code`
- **WHEN** the maker saves a flow that adds an `openregister.code` step through `PUT /api/flows/{id}`
- **THEN** the save is refused with 403 naming `flow.code`
- @e2e exclude {API contract; covered by FlowControllerTest in task 2.4}

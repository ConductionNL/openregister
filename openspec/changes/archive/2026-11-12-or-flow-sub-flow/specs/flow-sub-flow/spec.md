## ADDED Requirements

### Requirement: A flow can run another flow as a step (REQ-SF-001)

OpenRegister SHALL provide a built-in `openregister.sub-flow` node that runs a
named flow as one step. With `wait` (the default) it SHALL run the named flow
seeded with the step's items and return the sub-flow's output items. Without
`wait` it SHALL queue the named flow against the run's subject and pass the
step's items through unchanged. A sub-flow step naming no flow SHALL be refused
at save time.

#### Scenario: A waited sub-flow returns its result

- **GIVEN** a flow whose step runs sub-flow "child" with wait on
- **WHEN** the step executes
- **THEN** "child" runs seeded with the step's items
- **AND** its output items become the step's output

#### Scenario: A fired sub-flow does not feed back

- **GIVEN** a sub-flow step with wait off
- **WHEN** the step executes
- **THEN** the named flow is queued
- **AND** the step's input items pass through unchanged

### Requirement: A waited sub-flow's failure reaches the parent (REQ-SF-002)

A waited sub-run that does not complete cleanly SHALL raise, so the parent
step's `onError` policy decides the outcome — a sub-flow failure is handled
exactly as an inline step's failure.

#### Scenario: A failed sub-run raises

- **GIVEN** a waited sub-flow that ends failed
- **WHEN** its result is read
- **THEN** the sub-flow step raises

### Requirement: A flow cannot recurse without bound (REQ-SF-003)

A sub-flow already among the flow ids the run is inside SHALL be refused, and
sub-flow nesting SHALL be capped at a fixed depth. Both are step failures, not
process failures.

#### Scenario: A flow cannot call itself round a cycle

- **GIVEN** a run already inside flow "A"
- **WHEN** a step tries to run sub-flow "A"
- **THEN** the step is refused

#### Scenario: Nesting past the ceiling is refused

- **GIVEN** sub-flows nested to the depth ceiling
- **WHEN** one more sub-flow is entered
- **THEN** the step is refused

@e2e exclude engine-level node — covered by SubFlowNodeTest and live-verified on
8080 (palette registration + DI graph); no user-facing page of its own yet

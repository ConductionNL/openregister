## ADDED Requirements

### Requirement: A run can pin a step's output (REQ-PIN-001)

The flow engine SHALL accept a `pins` map (step name → item list) and, for a
step whose name is in the map, use the pinned items as the step's output without
dispatching the step. A pinned step SHALL be traced as `pinned`. Pins SHALL be
read from the run context first and the flow document second, so a run's pins
override the flow's.

#### Scenario: A pinned step is not executed

- **GIVEN** a run that pins step "first"
- **WHEN** the flow runs
- **THEN** "first" is not dispatched
- **AND** the next step receives the pinned items
- **AND** the trace marks "first" as pinned

#### Scenario: A run pin overrides a flow pin

- **GIVEN** a flow that pins "first" and a run that also pins "first"
- **WHEN** the flow runs
- **THEN** the run's pinned items are used

### Requirement: A pinned step cannot fail (REQ-PIN-002)

Because a pinned step is not dispatched, it SHALL neither stop, suspend nor fail
— a pin skips the step that would otherwise have done so.

#### Scenario: Pinning past a failing step

- **GIVEN** a step that would throw, pinned
- **WHEN** the flow runs
- **THEN** the run completes using the pinned output

@e2e exclude engine-level — covered by FlowEngineTest and live-verified on 8080;
the authoring UI that captures pins is a separate change

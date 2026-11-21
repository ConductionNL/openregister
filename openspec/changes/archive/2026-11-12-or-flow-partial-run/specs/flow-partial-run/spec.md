## ADDED Requirements

### Requirement: A flow can run from a chosen node (REQ-PR-001)

The flow engine SHALL accept an optional start node. When given, the run SHALL
begin at that node with the supplied seed items, and the steps before it SHALL
NOT run. An empty or absent start node SHALL leave the flow's own start
unchanged. An unknown start node SHALL fail the run.

#### Scenario: Running from a mid-graph node skips what is before it

- **GIVEN** a flow start -> middle -> end
- **WHEN** it is run starting at "middle" with seed items
- **THEN** only the middle -> end step runs
- **AND** it receives the seed items

#### Scenario: An unknown start node fails the run

- **WHEN** a flow is run starting at a node it does not declare
- **THEN** the run fails

### Requirement: Run-from-here does not disturb resume (REQ-PR-002)

A resumed run's marking already holds where it left off, so a start node SHALL be
ignored when resuming.

#### Scenario: A resume ignores a start node

- **GIVEN** a suspended run being resumed
- **WHEN** execution continues
- **THEN** the run resumes from its stored marking, not from any start node

@e2e exclude engine-level — covered by FlowEngineTest and live-verified on 8080;
the interactive run-from-here surface is a separate change

## ADDED Requirements

### Requirement: A flow can be test-run synchronously (REQ-TR-001)

OpenRegister SHALL expose `POST /api/flow-runs/test` that runs a named flow
synchronously and returns the finished run. It SHALL accept an optional start
node (run-from-here), optional pins (step name → items, applied via the run
context) and optional seed items. A missing flow id SHALL be a 400; an unknown
flow SHALL be a 404. The run SHALL be persisted with trigger `test`.

#### Scenario: A test run returns the trace

- **GIVEN** a resolvable flow
- **WHEN** it is test-run
- **THEN** the response is the finished run with its per-step log and items

#### Scenario: A test run carries startAt and pins

- **GIVEN** a test run with a start node and pins
- **WHEN** it runs
- **THEN** it starts at that node
- **AND** the pinned steps are not executed

#### Scenario: An unknown flow is rejected

- **WHEN** a test run names a flow no resolver owns
- **THEN** the response is 404

@e2e exclude backend endpoint — covered by FlowRunControllerTest and
live-verified on 8080 (DI + real queue/execute/persist through the service);
the builder button that calls it is a separate change

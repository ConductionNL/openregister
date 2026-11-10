## ADDED Requirements

### Requirement: Every non-exit node continues somewhere (REQ-FS-010)

A node is an **exit node** iff its step type is registered terminal (declared by
implementing `IFlowTerminalNode`, as `openregister.stop` does) or it carries
`exit: true`.

Every node that is not an exit node SHALL have at least one outgoing edge. A
non-exit node with no outgoing edge is a **dead end**.

A flow SHALL always either exit deliberately or report an error. Walking into a
dead end and stopping — which today ends the run with no failure recorded — is
neither, and is indistinguishable from completing successfully.

Terminality SHALL be resolved through the node registry rather than a hardcoded
list of type ids, so an app contributing its own terminal step is terminal
without an OpenRegister change.

#### Scenario: A dead end is a warning when saving

- **GIVEN** a flow with node `review` that has no outgoing edge and is not an exit node
- **WHEN** the flow is saved
- **THEN** the flow IS stored
- **AND** the response carries a warning naming `review`

#### Scenario: A dead end refuses to run

- **GIVEN** that same stored flow
- **WHEN** a run is requested
- **THEN** no run is created
- **AND** the flow's `status` is `error`
- **AND** `status_message` names `review`

#### Scenario: A stop node is a legitimate ending

- **GIVEN** a flow whose last node has type `openregister.stop`
- **WHEN** the flow is saved and run
- **THEN** no warning is raised and the run proceeds

#### Scenario: An explicitly declared exit is a legitimate ending

- **GIVEN** a flow whose last node carries `exit: true`
- **WHEN** the flow is saved and run
- **THEN** no warning is raised and the run proceeds

#### Scenario: A scheduled flow is refused on the same terms

- **GIVEN** a dead-ended flow whose trigger is a cron schedule
- **WHEN** the schedule fires
- **THEN** no run is created, and the flow's `status` is `error`
- **AND** the refusal is not limited to the manual-run endpoint

### Requirement: A flow carries its own status (REQ-FS-011)

A flow SHALL carry `status` (`ok` or `error`) and `status_message` describing
whether the flow can execute at all. This is distinct from any run's outcome.

`status` SHALL be set to `error` when a run is refused, and cleared when a run
is accepted. It SHALL NOT reuse the run lifecycle vocabulary
(`running | completed | stopped | dead_letter | suspended | failed`).

#### Scenario: A refused flow is distinguishable from a never-run flow

- **GIVEN** a flow refused for a dead end, which therefore has no runs at all
- **WHEN** it is listed
- **THEN** its `status` is `error` with a message
- **AND** a client reading only run history cannot mistake it for healthy

### Requirement: A flow carries its last run (REQ-FS-012)

A flow SHALL carry `lastRunUuid`, `lastRunStatus`, `lastRunMessage` and
`lastRunAt`, written when a run reaches a terminal state, so a list or an editor
can show the last outcome without querying the runs table.

These fields SHALL be nullable with no backfill: a flow that has never run
correctly has no last run.

#### Scenario: A finished run is recorded on the flow

- **WHEN** a run of a flow reaches a terminal state
- **THEN** the flow's `lastRunUuid`, `lastRunStatus`, `lastRunMessage` and
  `lastRunAt` describe that run

#### Scenario: A queued run is not yet the last run

- **WHEN** a run is created but has not reached a terminal state
- **THEN** the flow's last-run fields still describe the previous run, if any

#### Scenario: A flow that has never run reports no last run

- **GIVEN** a newly created flow
- **WHEN** it is read
- **THEN** its last-run fields are null

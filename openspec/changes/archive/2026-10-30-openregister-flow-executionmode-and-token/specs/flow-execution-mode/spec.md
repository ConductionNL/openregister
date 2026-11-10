# flow-execution-mode

Whether a triggered flow runs inline with the call that triggered it, or is
queued and drained by the worker.

## ADDED Requirements

### Requirement: Flows declare an execution mode

A flow SHALL declare an `executionMode` of either `async` or `sync`, and a flow
that does not declare one MUST be treated as `async`.

The property is part of the flow document exposed by the resolver contract, so
every flow store — the OR-native store and any app-contributed `IFlowResolver` —
carries it without a second mechanism.

#### Scenario: A flow without an execution mode keeps today's behaviour

- **WHEN** a flow with no `executionMode` property is triggered
- **THEN** the run is queued
- **AND** the triggering call returns without executing it

#### Scenario: An invalid execution mode falls back to async

- **WHEN** a flow declares an `executionMode` that is neither `async` nor `sync`
- **THEN** the run is queued rather than refused

### Requirement: An async flow queues

An `async` flow SHALL be queued by the trigger and left for the run worker to
drain, which is the default and the behaviour of every flow before this change.

#### Scenario: An async flow is not executed inline

- **WHEN** an `async` flow is triggered
- **THEN** a run is persisted with status `queued`
- **AND** the flow's steps have not been dispatched when the trigger returns

### Requirement: A sync flow executes inline

A `sync` flow SHALL be executed within the triggering call, so that its effects
are complete before the call that triggered it returns.

The run is still persisted first and then executed, so a synchronously-executed
run is indistinguishable from a drained one afterwards and the existing history,
retry and resume tooling applies unchanged.

#### Scenario: A sync flow completes before the trigger returns

- **WHEN** a `sync` flow is triggered
- **THEN** its steps are dispatched during the triggering call
- **AND** the persisted run has reached a terminal status when the trigger returns

#### Scenario: A failing sync flow does not unwind the caller

- **WHEN** a `sync` flow is triggered and one of its steps throws
- **THEN** the trigger records the failure on the run
- **AND** the triggering call is not interrupted

#### Scenario: A suspended sync flow defers to the worker

- **WHEN** a `sync` flow suspends part-way through
- **THEN** the triggering call returns without waiting for the suspension to elapse
- **AND** the run is resumed later by the run worker

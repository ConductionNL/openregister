## ADDED Requirements

### Requirement: The dispatcher establishes and unconditionally clears the run context around every step @e2e exclude engine-internal execution context; asserted by dispatcher unit tests including the leak-direction test

Before dispatching a step, the engine SHALL establish an ambient context naming the executing run, the node being dispatched, and the step's sequence number. After the step ends the engine SHALL clear that context, and SHALL do so whether the step completed, stopped, suspended, or threw.

A context left in place after a step attributes later, unrelated writes to a run that is no longer executing. This failure is silent — the resulting rows are well-formed and look correct — so the clear SHALL be structurally unconditional rather than placed on the success path.

Nested dispatch SHALL restore the enclosing context rather than clearing it: a sub-flow's steps are attributed to the sub-flow's run, and the parent run's remaining steps are attributed to the parent.

#### Scenario: The context names the step being dispatched
- **WHEN** the engine dispatches a node
- **THEN** the ambient context names that run, that node id, and that step's sequence

#### Scenario: A throwing step still clears the context
- **WHEN** a dispatched node throws
- **THEN** the context is cleared before control leaves the dispatcher
- **AND** a write performed afterwards outside any run is unattributed

#### Scenario: A suspended step clears the context
- **WHEN** a node suspends the run to await a signal or a time
- **THEN** the context is cleared
- **AND** the run's later resumption establishes a fresh context for the step it resumes into

#### Scenario: A sub-flow does not leak into its parent
- **WHEN** a node dispatches a sub-flow run and that sub-flow completes
- **THEN** writes during the sub-flow are attributed to the sub-flow's run and nodes
- **AND** the parent run's subsequent steps are attributed to the parent run

### Requirement: A run's history names what each step touched @e2e exclude read model asserted by controller and store tests

The objects a run touched SHALL be readable alongside the run's step history, so that a reader following a run can see, per node, both what the node did and what it did it to. The step history SHALL remain the record of execution; the attribution SHALL be joined to it by run, node and step rather than duplicated into it.

#### Scenario: A step's entry can be expanded to the objects it touched
- **WHEN** a reader views a run's step history
- **THEN** each step can be related to the objects attributed to that run and node
- **AND** a step that touched nothing is shown as such rather than omitted

#### Scenario: The step history remains authoritative for execution
- **WHEN** attribution for a step is absent because no write occurred
- **THEN** the step is still present in the history with its own status and timing

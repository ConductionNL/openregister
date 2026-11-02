# example-sets Delta

## ADDED Requirements

### Requirement: A fragment's task may wait in a flow run and carry its claim

A fragment's flow MAY declare a waiting run parked on a human-task node, and a fragment's task MAY name that run with `{{flowRun:<flow>.<run>}}` as `runUuid`, with the node as `nodeId`. A task MAY declare `claimedBy` and `claimedAt`. Install SHALL write the run so the flow worker does not resume it on its own, link the task to it, and write a `claim` audit entry by `claimedBy` at `claimedAt`.

#### Scenario: A hearing task waits in the bezwaar flow
- **GIVEN** a fragment declares the bezwaar flow with a waiting run on node "hoorzitting" and task "Hoorzitting inplannen" with that run and node, claimed by Pieter
- **WHEN** the set is installed
- **THEN** the task page SHALL show the waiting run under "Wat deze taak tegenhoudt" and the history SHALL read "Taak opgepakt door Pieter Jansen"

#### Scenario: A task names a finished run
- **GIVEN** a fragment's task names a run whose status is completed
- **WHEN** the set is checked
- **THEN** the check SHALL refuse the task and write nothing

# bulk-action-jobs

## ADDED Requirements

### Requirement: A bulk action declares whether it can be undone, and for how long (REQ-UBA-001)

A bulk action SHALL declare `reversible` and, when reversible, a reversal
window. The preview SHALL report both before the job commits. An action that
destroys data, dispatches a message or transfers to an e-depot SHALL NOT be
declarable as reversible, and a schema declaring it so SHALL be refused at
save with HTTP 422 naming the action.

#### Scenario: the preview says the job can be undone

- **GIVEN** a status transition action declared reversible with a window of seven days
- **WHEN** a job for it is previewed
- **THEN** the preview reports the job as reversible and names the window

#### Scenario: a destruction is not declarable as reversible

- **GIVEN** a schema declaring a destruction action as reversible
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the action
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: A reversible job records the prior value of every property it changes (REQ-UBA-002)

For a reversible action, each per-member outcome SHALL record the properties
the job changed together with their values before the change. An instance
ceiling SHALL bound how much a single job may store this way, and a job
whose selection and action would exceed it SHALL be refused at creation
naming the ceiling.

#### Scenario: the prior status is on the member outcome

- **GIVEN** a reversible job moving forty objects from `in behandeling` to `afgehandeld`
- **WHEN** the job completes
- **THEN** each member outcome records `status` with the value `in behandeling`

#### Scenario: a job above the storage ceiling is refused at creation

- **GIVEN** an instance ceiling and a job whose recorded prior values would exceed it
- **WHEN** the job is created
- **THEN** creation fails naming the ceiling
- @e2e exclude {validator, covered by unit tests}

### Requirement: A reversal is a new job that names the job it undoes (REQ-UBA-003)

Reversing a job SHALL create a new bulk job whose selection is the members
of the original, whose writes are the recorded prior values, and which names
the original job as its cause. The reversal SHALL run under the same
ceiling, preview, progress reporting and authorization as any other job, and
SHALL be refused outside the reversal window or for an action not declared
reversible, naming the reason. Every member's audit trail SHALL show the
original write and the reversal as two acts with their own actors.

#### Scenario: a hundred cases go back in one act

- **GIVEN** a completed reversible job over a hundred objects
- **WHEN** it is reversed inside its window
- **THEN** a new job runs restoring the recorded prior value on each member
- **AND** the new job names the original as its cause

#### Scenario: the reversal is authorised for the person doing it

- **GIVEN** a completed job created by an administrator
- **WHEN** a caller without write access to the members requests the reversal
- **THEN** the reversal is refused and nothing is written

#### Scenario: a reversal outside the window is refused

- **GIVEN** a job whose reversal window has passed
- **WHEN** a reversal is requested
- **THEN** it is refused naming the window
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

### Requirement: A member changed since the job is not silently overwritten (REQ-UBA-004)

A reversal SHALL skip any member whose recorded properties changed after the
original job wrote them, SHALL report those members by name with the reason,
and SHALL restore the rest. A reversal SHALL never write a prior value over
a later change.

#### Scenario: somebody's later correction survives the undo

- **GIVEN** a reversible job over ten objects, one of which a colleague edited afterwards
- **WHEN** the job is reversed
- **THEN** nine objects are restored
- **AND** the tenth is reported as not reversible, naming the later change

#### Scenario: the report is readable after the reversal

- **GIVEN** a completed reversal with skipped members
- **WHEN** the reversal job is read
- **THEN** each skipped member is listed with its reason
- @e2e exclude {job read, covered by unit tests}

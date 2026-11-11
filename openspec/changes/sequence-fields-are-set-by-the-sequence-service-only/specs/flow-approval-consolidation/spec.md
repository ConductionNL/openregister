# flow-approval-consolidation

## ADDED Requirements

### Requirement: Only the task sequence sets a task's sequence fields

A task's `sequenceUuid`, `sequencePosition` and `legacyStepId`, and the
`approvingOutcomes` in its `templateSnapshot`, SHALL be written only by the
task sequence when it provisions a position, or by the approval migration.

Creating a task through the task routes SHALL refuse any of these fields that
carries a value, naming the field, before anything is written. The refusal
SHALL apply to administrators too. A field sent as `null` SHALL set nothing and
SHALL NOT be refused. A `templateSnapshot` without `approvingOutcomes` SHALL
still be accepted.

#### Scenario: A forged sequence step is refused

- **GIVEN** a running sequence on an object a user may read
- **WHEN** the user creates a task on that object with `sequenceUuid` set to the sequence, `sequencePosition` 2 and state `enabled`
- **THEN** the creation MUST be refused naming `sequenceUuid`
- **AND** no task MUST be written
- @e2e exclude backend refusal, covered by TaskService unit tests

#### Scenario: An administrator cannot forge a position either

- **GIVEN** an administrator
- **WHEN** they create a task with `sequencePosition` 0
- **THEN** the creation MUST be refused naming `sequencePosition`
- @e2e exclude backend refusal, covered by TaskService unit tests

#### Scenario: A caller cannot choose what approves their task

- **GIVEN** a user creating a task
- **WHEN** the payload's `templateSnapshot` carries `approvingOutcomes`
- **THEN** the creation MUST be refused naming `templateSnapshot.approvingOutcomes`
- @e2e exclude backend refusal, covered by TaskService unit tests

#### Scenario: The sequence still provisions its positions

- **GIVEN** a chain declaring two positions
- **WHEN** the task sequence provisions them
- **THEN** each task MUST carry its `sequenceUuid`, `sequencePosition` and position snapshot
- @e2e exclude trusted path, covered by TaskService and TaskSequenceService unit tests

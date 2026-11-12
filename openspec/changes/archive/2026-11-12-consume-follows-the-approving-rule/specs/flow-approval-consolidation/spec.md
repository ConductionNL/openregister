# flow-approval-consolidation

## MODIFIED Requirements

### Requirement: A human-in-the-loop semantic is not retired until it has a named home

Before the openconnector approval runner is retired, every semantic its
`approval_request` carries SHALL have a named home in this fleet's task,
sequence or timer capability, and that mapping SHALL be published as a
retirement inventory.

The inventory SHALL cover at minimum: the approver group; the requester
identity; the approver comment; the enforcing expiry and its timeout outcome;
the rejection outcome; and the consumed marker that prevents an approved but
unconsumed request from silently re-authorizing a later run.

A semantic with no home SHALL block the retirement. It SHALL NOT be dropped,
and it SHALL NOT be recorded as "handled by the task entity" without naming
the field or verb that handles it.

The rejection outcome vocabulary SHALL be preserved with the meaning its
declaration always promised, including the outcome that skips rather than
errors. The retired implementation collapsed two of its three declared
outcomes onto one behaviour, and the migration SHALL NOT carry that defect
forward as if it were the contract.

An approved decision that has been consumed SHALL NOT authorize a second
consumption. Consumption SHALL be recorded on the decided work, and a second
attempt to rely on the same approval SHALL be refused.

Only an approving decision SHALL be consumed. A decision approves only when
the task is completed with an outcome in its approving set: the task's own
`templateSnapshot.approvingOutcomes`, or `approved` and `done` when it
declares none, compared trimmed and case-insensitive. A completed task whose
outcome neither approves nor rejects SHALL be refused as undecided, naming the
outcome. A rejecting outcome SHALL be refused as not an approving decision,
also when the approving set names it. A task sequence SHALL copy its chain's
declared approving set onto every position task it provisions.

#### Scenario: The retirement inventory is complete before the runner moves

- **GIVEN** the retirement inventory for the openconnector approval runner
- **WHEN** it is checked against the `approval_request` declaration
- **THEN** every declared property MUST name either a target field or verb, or an explicit decision not to carry it with a reason
- @e2e exclude documentation contract, covered by a fixture test over the inventory

#### Scenario: The skip outcome skips

- **GIVEN** a rejected or expired approval declaring the skipping outcome
- **WHEN** the outcome is applied
- **THEN** the subject MUST be skipped
- **AND** it MUST NOT be recorded as an error
- @e2e exclude harvested semantic, covered by outcome unit tests

#### Scenario: A consumed approval cannot authorize twice

- **GIVEN** an approving decision that has already been consumed by the work it authorized
- **WHEN** a later run attempts to rely on the same approval
- **THEN** the attempt MUST be refused
- @e2e exclude idempotency of authorization, covered by consumption unit tests

#### Scenario: An undecided completion cannot be consumed

- **GIVEN** a task completed with an empty, unknown or `resolved` outcome
- **WHEN** a run attempts to consume it
- **THEN** the attempt MUST be refused as undecided, naming the outcome
- **AND** no consumption MUST be recorded
- @e2e exclude backend verb, covered by consumption unit tests

#### Scenario: A task's declared set replaces the default

- **GIVEN** a task completed with outcome `done` whose snapshot declares `approvingOutcomes: ["approved"]`
- **WHEN** a run attempts to consume it
- **THEN** the attempt MUST be refused as undecided
- @e2e exclude backend verb, covered by consumption unit tests

#### Scenario: A sequence task carries its chain's set

- **GIVEN** a chain declaring `approvingOutcomes: ["approved"]`
- **WHEN** a sequence is provisioned from it
- **THEN** every position task's snapshot MUST carry `approvingOutcomes: ["approved"]`
- @e2e exclude provisioning, covered by TaskSequenceService unit tests

# flow-approval-consolidation

## MODIFIED Requirements

### Requirement: An approval is an ordered task sequence with one position enabled at a time

The system SHALL provide a **task sequence**: an ordered set of positions,
each holding at most one task, with a recorded requester, a recorded outcome
and an anchor naming the object the approval is about.

A sequence SHALL enable exactly ONE position at a time. Provisioning SHALL
create every position and enable only the first; completing the enabled
position with an approving outcome SHALL enable the next, in the same request
as the completing decision. A sequence whose last position completes with an
approving outcome SHALL itself complete with an approving outcome.

An outcome SHALL be approving only when it is in the sequence's approving set,
compared trimmed and case-insensitive. The approving set SHALL be the list the
frozen template declares under `approvingOutcomes`, or `approved` and `done`
when the template declares none. A completed position whose outcome is not in
the approving set SHALL NOT enable the next position and SHALL NOT complete the
sequence. A rejecting outcome SHALL reject, also when the approving set names it.

A sequence SHALL NOT require a flow run. `run_uuid` and `node_id` SHALL be
optional provenance on a sequence exactly as they are on a task: a sequence
opened by a schema-declared gate has neither, and is first-class.

Position order SHALL be stable and SHALL NOT be inferred from creation
timestamps, task ids or inbox ordering. Two positions SHALL NOT share an
ordinal within one sequence.

A sequence SHALL be terminal in exactly one way per outcome: `completed` with
an approving outcome, `rejected`, `undecided` or `terminated`. It SHALL NOT be
re-opened. A further attempt at the same approval SHALL open a NEW sequence.

#### Scenario: Provisioning enables only the first position

- **GIVEN** a template with three ordered positions
- **WHEN** a sequence is provisioned from it
- **THEN** three tasks MUST exist
- **AND** exactly one of them, at the first position, MUST be enabled
- **AND** the other two MUST be non-terminal and not enabled
- @e2e exclude sequence provisioning contract, covered by TaskSequenceService unit tests

#### Scenario: An approving decision enables the next position in the same request

- **GIVEN** a running sequence whose first of three positions is enabled
- **WHEN** that task is completed with an approving outcome
- **THEN** the second position MUST be enabled before the completing request returns
- **AND** the third position MUST still not be enabled
- @e2e exclude in-request advance, covered by TaskSequenceService unit tests

#### Scenario: The last approving decision completes the sequence

- **GIVEN** a running sequence on its final position
- **WHEN** that task is completed with an approving outcome
- **THEN** the sequence MUST become terminal with an approving outcome
- **AND** no further position MUST be enabled
- @e2e exclude terminality, covered by TaskSequenceService unit tests

#### Scenario: A sequence with no run is first-class

- **GIVEN** a sequence provisioned by a schema-declared gate, with no run uuid and no node id
- **WHEN** it is provisioned, advanced and completed
- **THEN** every step MUST behave identically to a sequence created from a flow
- @e2e exclude fleet-generic requirement, covered by unit tests without a run

#### Scenario: Done from the task inbox approves a chain that declares no set

- **GIVEN** a running sequence whose template declares no `approvingOutcomes`
- **WHEN** the enabled task is completed with outcome `done`
- **THEN** the next position MUST be enabled
- @e2e exclude default vocabulary, covered by TaskSequenceService unit tests

#### Scenario: An empty outcome does not advance

- **GIVEN** a running sequence whose first of three positions is enabled
- **WHEN** that task is completed with an empty outcome
- **THEN** the second position MUST NOT be enabled
- **AND** the sequence MUST NOT complete
- @e2e exclude backend rule, covered by TaskSequenceService unit tests

#### Scenario: A declared set replaces the default

- **GIVEN** a running sequence whose template declares `approvingOutcomes: ["approved"]`
- **WHEN** the enabled task is completed with outcome `done`
- **THEN** the next position MUST NOT be enabled
- **AND** a completion with outcome ` Approved ` on such a sequence MUST enable the next position
- @e2e exclude backend rule, covered by TaskSequenceService unit tests

#### Scenario: A rejecting outcome in a declared set still rejects

- **GIVEN** a running sequence whose template declares `approvingOutcomes: ["approved", "rejected"]`
- **WHEN** the enabled task is completed with outcome `rejected` and a comment
- **THEN** the sequence MUST become terminal as rejected
- @e2e exclude backend rule, covered by TaskSequenceService unit tests

## ADDED Requirements

### Requirement: A completion that neither approves nor rejects ends the sequence as undecided

When the enabled position completes with an outcome that is neither approving
nor rejecting, the system SHALL close the sequence with status `undecided` and
outcome `undecided`. It SHALL terminate every non-terminal task the sequence
still owns, with a reason naming the position and the outcome. It SHALL
dispatch `TaskSequenceUndecidedEvent` carrying the sequence and the completed
task. It SHALL NOT dispatch `TaskSequenceCompletedEvent`.

An undecided sequence, its tasks and its audit SHALL remain readable. A gated
transition SHALL treat an undecided sequence as not approved: the next attempt
SHALL open a new sequence beside it.

A chain in `x-openregister-approval-chains` MAY declare `approvingOutcomes`, a
list of outcomes. The compiled template SHALL carry the list trimmed and
lowercased, without empty entries, and SHALL omit the key when the chain
declares no usable entry.

#### Scenario: An unknown outcome on the last position ends the sequence undecided

- **GIVEN** a running sequence on its final position
- **WHEN** that task is completed with outcome `resolved`
- **THEN** the sequence MUST become terminal as undecided
- **AND** `TaskSequenceUndecidedEvent` MUST be dispatched
- **AND** `TaskSequenceCompletedEvent` MUST NOT be dispatched
- @e2e exclude backend rule, covered by TaskSequenceService unit tests

#### Scenario: The remaining positions are terminated with a reason

- **GIVEN** a running sequence with three positions and the first enabled
- **WHEN** the first task is completed with an empty outcome
- **THEN** the second and third tasks MUST be terminated with a reason naming the first position
- **AND** none of them MUST be enabled
- @e2e exclude propagation, covered by TaskSequenceService unit tests

#### Scenario: A new attempt after an undecided sequence opens a new one

- **GIVEN** a gated transition whose newest sequence is undecided
- **WHEN** the transition is attempted again
- **THEN** a new sequence MUST be provisioned
- **AND** the transition MUST be refused as pending approval
- @e2e exclude gate rule, covered by ApprovalChainGateListener unit tests

#### Scenario: A declared approving set is compiled into the template

- **GIVEN** a chain declaring `approvingOutcomes: [" Approved ", ""]`
- **WHEN** the chain is compiled
- **THEN** the template MUST carry `approvingOutcomes: ["approved"]`
- **AND** a chain without the key MUST compile to a template without it
- @e2e exclude compiler rule, covered by ApprovalChainGateListener unit tests

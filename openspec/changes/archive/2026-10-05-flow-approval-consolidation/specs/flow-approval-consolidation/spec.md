## Purpose

Retires OpenRegister's own approval-chain engine onto the task service by
giving tasks an ordered sequence, migrating every existing chain and
in-flight step onto it without losing or double-deciding an approval, and
writing down the contract every leaf app and the retiring openconnector
runner migrate against.

## ADDED Requirements

### Requirement: An approval is an ordered task sequence with one position enabled at a time

The system SHALL provide a **task sequence**: an ordered set of positions,
each holding at most one task, with a recorded requester, a recorded outcome
and an anchor naming the object the approval is about.

A sequence SHALL enable exactly ONE position at a time. Provisioning SHALL
create every position and enable only the first; completing the enabled
position with an approving outcome SHALL enable the next, in the same request
as the completing decision. A sequence whose last position completes with an
approving outcome SHALL itself complete with an approving outcome.

A sequence SHALL NOT require a flow run. `run_uuid` and `node_id` SHALL be
optional provenance on a sequence exactly as they are on a task: a sequence
opened by a schema-declared gate has neither, and is first-class.

Position order SHALL be stable and SHALL NOT be inferred from creation
timestamps, task ids or inbox ordering. Two positions SHALL NOT share an
ordinal within one sequence.

A sequence SHALL be terminal in exactly one way per outcome — `completed` with
an approving outcome, `rejected`, or `terminated` — and SHALL NOT be
re-opened. A further attempt at the same approval SHALL open a NEW sequence.

#### Scenario: Provisioning enables only the first position

- **GIVEN** a template with three ordered positions
- **WHEN** a sequence is provisioned from it
- **THEN** three tasks MUST exist
- **AND** exactly one of them, at the first position, MUST be enabled
- **AND** the other two MUST be non-terminal and not enabled
- @e2e exclude sequence provisioning contract — covered by TaskSequenceService unit tests

#### Scenario: An approving decision enables the next position in the same request

- **GIVEN** a running sequence whose first of three positions is enabled
- **WHEN** that task is completed with an approving outcome
- **THEN** the second position MUST be enabled before the completing request returns
- **AND** the third position MUST still not be enabled
- @e2e exclude in-request advance — covered by TaskSequenceService unit tests

#### Scenario: The last approving decision completes the sequence

- **GIVEN** a running sequence on its final position
- **WHEN** that task is completed with an approving outcome
- **THEN** the sequence MUST become terminal with an approving outcome
- **AND** no further position MUST be enabled
- @e2e exclude terminality — covered by TaskSequenceService unit tests

#### Scenario: A sequence with no run is first-class

- **GIVEN** a sequence provisioned by a schema-declared gate, with no run uuid and no node id
- **WHEN** it is provisioned, advanced and completed
- **THEN** every step MUST behave identically to a sequence created from a flow
- @e2e exclude fleet-generic requirement — covered by unit tests without a run

### Requirement: A rejection terminates the sequence and every task it still owns

A rejecting outcome at any position SHALL terminate the sequence, and SHALL
terminate every non-terminal task the sequence owns — the enabled one and
every later position — with a reason naming the rejecting position. Those
tasks SHALL disappear from every inbox as actionable work in the same request.

A rejecting outcome SHALL require a comment. A rejection submitted without one
SHALL be REFUSED, and the sequence SHALL remain running.

A terminated sequence, its tasks, its decisions, its comments and its audit
SHALL remain readable indefinitely. The system SHALL NOT delete them when a
new attempt opens, and SHALL NOT overwrite them.

A rejection SHALL NOT be an error condition. It is a recorded outcome of a
process that worked, and SHALL be reported as such to every caller.

#### Scenario: Rejection at the first position terminates the later ones

- **GIVEN** a running sequence with three positions and the first enabled
- **WHEN** the first task is completed with a rejecting outcome and a comment
- **THEN** the sequence MUST become terminal as rejected
- **AND** the second and third tasks MUST be terminated with a reason naming the first position
- **AND** none of the three MUST appear as actionable in any inbox
- @e2e exclude propagation — covered by TaskSequenceService unit tests

#### Scenario: A rejection without a comment is refused

- **GIVEN** a running sequence with an enabled task
- **WHEN** a rejecting outcome is submitted with an empty comment
- **THEN** the decision MUST be refused
- **AND** the task MUST remain enabled and non-terminal
- @e2e exclude mandatory-comment rule — covered by TaskSequenceService unit tests

#### Scenario: A new attempt does not erase the rejected one

- **GIVEN** a rejected sequence for an object
- **WHEN** a new sequence is opened for the same object and template
- **THEN** the rejected sequence and its decisions MUST still be readable
- **AND** the two sequences MUST be distinguishable by their open time
- @e2e exclude history preservation — covered by TaskSequenceService unit tests

### Requirement: The role performer survives as a group performer with single-role routing

A position declared by a role name SHALL become a task with performer type
`group`, the role as its candidate group, no assignee until someone claims it,
and the `single-role` routing strategy. The set of people who may decide a
migrated approval SHALL be exactly the set who could decide it before —
membership of that Nextcloud group, evaluated at decision time, not at
provisioning time.

Role resolution SHALL happen when the decision is attempted, never frozen at
provisioning. A person added to the role group after a sequence opened SHALL
be able to decide its enabled position; a person removed SHALL NOT.

A role that resolves to nobody SHALL leave the position unassigned in the
pool. It SHALL NOT be assigned to the requester, to an administrator, or to a
system identity, and the sequence SHALL NOT auto-advance past it.

#### Scenario: A role position is a group task with no assignee

- **GIVEN** a template position declaring the role `finance-clerks`
- **WHEN** a sequence is provisioned
- **THEN** its task MUST carry performer type `group` with candidate group `finance-clerks`
- **AND** it MUST have no assignee
- **AND** it MUST appear in the unclaimed inbox of every member of that group
- @e2e exclude performer mapping — covered by provisioning unit tests

#### Scenario: Group membership is evaluated at decision time

- **GIVEN** a running sequence whose enabled position names a role group
- **WHEN** a user is added to that group after the sequence was provisioned
- **THEN** that user MUST be able to decide the enabled task
- @e2e exclude late-membership rule — covered by authorization unit tests

#### Scenario: An empty role assigns nobody and advances nothing

- **GIVEN** an enabled position whose role group has no members
- **WHEN** the sequence is inspected
- **THEN** the task MUST be unassigned in the pool
- **AND** the sequence MUST NOT advance past that position
- @e2e exclude empty-pool rule — covered by routing unit tests

### Requirement: The chain and step runtime is removed, and a migrated approval cannot be decided twice

`ApprovalChain`, `ApprovalStep`, their mappers, the approval service, the
approval controller, the `/api/approval-chains` and `/api/approval-steps`
routes and the four `ApprovalStep*` events SHALL be REMOVED. No facade,
adapter, alias or deprecation shim SHALL be left behind for any of them.

After the migration there SHALL be exactly ONE decision surface for a migrated
approval. The system SHALL NOT expose a code path, route, console command or
event handler that can decide a migrated step through the retired engine, and
SHALL NOT accept a decision on a migrated step row.

A decision recorded on a migrated task SHALL NOT be recordable a second time
through any surface. Deciding an already-terminal task SHALL be REFUSED with a
reason naming its terminal state.

Removal SHALL leave no orphan: no route entry pointing at a removed
controller method, no service registration for a removed class, no event
listener registration for a removed event, and no Vue import of a removed
component.

#### Scenario: The retired routes are gone

- **GIVEN** a deployed instance after this change
- **WHEN** any of the nine retired approval routes is requested
- **THEN** the request MUST NOT reach a controller
- **AND** no route entry MUST name a removed controller method
- @e2e exclude route-reachability contract — covered by the route-reachability gate and Newman

#### Scenario: A migrated approval decided once cannot be decided again

- **GIVEN** a migrated task that was completed with an approving outcome
- **WHEN** any surface attempts to decide it again
- **THEN** the attempt MUST be refused with a reason naming the terminal state
- **AND** the recorded decision, actor and time MUST be unchanged
- @e2e exclude double-decision guard — covered by TaskService unit tests

#### Scenario: No shim survives the removal

- **GIVEN** the source tree after this change
- **WHEN** it is searched for the removed class names and route paths
- **THEN** the only matches MUST be in migration and repair code that reads the legacy tables
- @e2e exclude static assertion — covered by a repository-wide grep test

### Requirement: Every in-flight approval survives the migration at the same position

The migration SHALL convert every approval chain into a task template and
every approval step into a task, and SHALL be idempotent: running it twice
SHALL produce the same tasks, the same sequences and no duplicates.

For every chain and object with at least one non-terminal step, the migration
SHALL open ONE sequence whose enabled position is the step that was pending,
at the same ordinal, with the same role, the same requester and the same
creation time. A step that was waiting SHALL become a non-enabled position at
its own ordinal. No in-flight approval SHALL be dropped, completed,
re-started, re-notified or re-assigned by the migration.

A chain and object whose steps are all terminal SHALL migrate as a terminal
sequence, and SHALL NOT reappear as work in anyone's inbox.

Every migrated step SHALL record the task it became, and every migrated task
SHALL record the step it came from, so the two sets can be reconciled by
count and by identity rather than by inspection.

The legacy tables SHALL NOT be dropped by this change. They SHALL be left in
place, marked migrated, and unreachable by any decision path.

The migration SHALL verify itself and SHALL FAIL LOUDLY rather than report a
partial success: every non-terminal step has exactly one non-terminal task,
every chain has exactly one template, no object has two running sequences for
one template, and no migrated task is enabled at an ordinal other than the one
its step held.

#### Scenario: A pending step becomes the enabled position

- **GIVEN** a chain of three steps for an object where step 2 is pending and step 3 is waiting
- **WHEN** the migration runs
- **THEN** one running sequence MUST exist with three positions
- **AND** position 2 MUST be enabled with the same role, requester and creation time as the step
- **AND** position 3 MUST exist and MUST NOT be enabled
- @e2e exclude data migration — covered by migration tests over a seeded database

#### Scenario: Running the migration twice changes nothing

- **GIVEN** a database already migrated
- **WHEN** the migration runs again
- **THEN** the task, sequence and template counts MUST be unchanged
- **AND** no task MUST change its lifecycle state
- @e2e exclude idempotency — covered by migration tests

#### Scenario: A fully decided chain does not come back as work

- **GIVEN** a chain whose steps for an object are all approved
- **WHEN** the migration runs
- **THEN** the resulting sequence MUST be terminal
- **AND** none of its tasks MUST appear as actionable in any inbox
- @e2e exclude terminal migration — covered by migration tests

#### Scenario: A migration that cannot reconcile fails loudly

- **GIVEN** a database where a non-terminal step cannot be mapped to exactly one task
- **WHEN** the migration's verification runs
- **THEN** it MUST fail with a message naming the chain, the object and the step
- **AND** it MUST NOT report success
- @e2e exclude verification behaviour — covered by migration tests with corrupted fixtures

### Requirement: A decided approval keeps its decision, its actor and its comment

Every already-decided step SHALL migrate its decision into the task audit: the
outcome, the deciding identity, the comment and the decision time, recorded as
an audit entry attributed to the original decider and marked as migrated.

The migration SHALL NOT attribute a historical decision to the migrating
administrator, to a system identity, or to the current session. A decision
whose decider is no longer a known user SHALL keep the recorded identity
string; it SHALL NOT be blanked and SHALL NOT block the migration.

The task audit SHALL be the decision history after this change, and it SHALL
carry what the retired `workflow_executions` row did not: the performer type,
the on-behalf-of identity where one applies, and the mandate relied on.

#### Scenario: A historical decision keeps its decider

- **GIVEN** a step approved by `alice` with a comment on a past date
- **WHEN** the migration runs
- **THEN** the task audit MUST carry an entry attributed to `alice` with that comment and that date
- **AND** it MUST be marked as migrated
- @e2e exclude provenance migration — covered by migration tests

#### Scenario: A decision by a departed user still migrates

- **GIVEN** a decided step whose decider no longer exists as a user
- **WHEN** the migration runs
- **THEN** the audit entry MUST keep the recorded identity string
- **AND** the migration MUST NOT fail on that row
- @e2e exclude departed-user case — covered by migration tests

### Requirement: The four approval events are replaced by a named, complete mapping

The system SHALL dispatch `TaskSequenceCompletedEvent` when a sequence
completes with an approving outcome, carrying the sequence, the final task,
the deciding identity and the resolved approving status. It SHALL be
dispatched at exactly the moment the retired completion event was.

The remaining three retired events SHALL be replaced by task lifecycle events
from the task capability, filtered by sequence: a position becoming enabled
replaces the initiated event; a task completing with an approving outcome
replaces the approved event; a task completing with a rejecting outcome
replaces the rejected event.

The mapping SHALL be published as migration documentation naming, for each
retired event, the replacement event, the replacement for every field the
retired event carried, and the ordering guarantee between them. A field with
no replacement SHALL be named as such rather than omitted.

No retired event SHALL be re-emitted, aliased or wrapped. A consumer that is
not migrated SHALL stop receiving events at deploy, visibly, rather than
receive events it can no longer answer.

#### Scenario: Sequence completion is observable

- **GIVEN** a running sequence on its final position
- **WHEN** that task is completed with an approving outcome
- **THEN** `TaskSequenceCompletedEvent` MUST be dispatched with the sequence, the final task, the decider and the approving status
- @e2e exclude event contract — covered by sequence event unit tests

#### Scenario: An unmigrated consumer fails visibly, not silently

- **GIVEN** an app registering a listener for a retired approval event
- **WHEN** the app is loaded after this change
- **THEN** the registration MUST fail visibly at load rather than register a listener that never fires
- @e2e exclude deliberate breakage — covered by an integration test with a stale registration

### Requirement: The signal node keeps machine-to-machine work and gains a correlation key

`openregister.await-signal` SHALL remain available for systems that call back,
and SHALL keep its heartbeat, its nudge-is-not-an-answer rule and its opt-in
fail-on-reject. Human and agent work belongs on the user-task node; the two
SHALL NOT answer each other.

An await-signal step SHALL be able to declare a `correlationKey` — a business
key resolved from the run's items or context at suspension time — and the
system SHALL accept a signal addressed by that key instead of by run uuid. A
caller that knows "the vote on proposal X closed" SHALL NOT need to know a run
uuid to say so.

Correlation resolution SHALL be fail-closed. A key matching NO suspended run
SHALL be refused as not found, and SHALL NOT be queued, buffered or replayed
against a run that suspends later. A key matching MORE THAN ONE suspended run
SHALL be refused as ambiguous, and SHALL NOT wake any of them; the system
SHALL NOT pick one.

A correlation-addressed signal SHALL NOT be able to complete a task, claim a
task, or advance a run suspended on a user-task node. It carries the same
authority as the existing run-uuid signal and no more.

A correlation key SHALL be recorded on the run so an operator can see what a
suspended run is waiting to be told, without reading a JSON column by hand.

#### Scenario: A signal addressed by business key wakes the right run

- **GIVEN** a run suspended on an await-signal step with correlation key `vote:proposal-42`
- **WHEN** a signal is delivered for that key
- **THEN** that run MUST be signalled
- **AND** no other suspended run MUST be affected
- @e2e exclude signal addressing — covered by signal-resolution unit tests

#### Scenario: An ambiguous key wakes nothing

- **GIVEN** two suspended runs carrying the same correlation key
- **WHEN** a signal is delivered for that key
- **THEN** the delivery MUST be refused as ambiguous
- **AND** both runs MUST remain suspended
- @e2e exclude ambiguity guard — covered by signal-resolution unit tests

#### Scenario: An unmatched key is refused, not held

- **GIVEN** no suspended run carrying the key `vote:proposal-99`
- **WHEN** a signal is delivered for that key
- **THEN** it MUST be refused as not found
- **AND** a run that later suspends with that key MUST NOT receive it
- @e2e exclude no-replay rule — covered by signal-resolution unit tests

#### Scenario: A correlated signal cannot decide a human task

- **GIVEN** a run suspended on a user-task node, and a correlation key resolving to it
- **WHEN** a signal is delivered for that key
- **THEN** the task MUST remain non-terminal and the run MUST remain suspended
- @e2e exclude authority boundary — covered by mixed-flow unit tests

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
errors — the retired implementation collapsed two of its three declared
outcomes onto one behaviour, and the migration SHALL NOT carry that defect
forward as if it were the contract.

An approved decision that has been consumed SHALL NOT authorize a second
consumption. Consumption SHALL be recorded on the decided work, and a second
attempt to rely on the same approval SHALL be refused.

#### Scenario: The retirement inventory is complete before the runner moves

- **GIVEN** the retirement inventory for the openconnector approval runner
- **WHEN** it is checked against the `approval_request` declaration
- **THEN** every declared property MUST name either a target field or verb, or an explicit decision not to carry it with a reason
- @e2e exclude documentation contract — covered by a fixture test over the inventory

#### Scenario: The skip outcome skips

- **GIVEN** a rejected or expired approval declaring the skipping outcome
- **WHEN** the outcome is applied
- **THEN** the subject MUST be skipped
- **AND** it MUST NOT be recorded as an error
- @e2e exclude harvested semantic — covered by outcome unit tests

#### Scenario: A consumed approval cannot authorize twice

- **GIVEN** an approving decision that has already been consumed by the work it authorized
- **WHEN** a later run attempts to rely on the same approval
- **THEN** the attempt MUST be refused
- @e2e exclude idempotency of authorization — covered by consumption unit tests

### Requirement: A leaf app consumes the task service and ships no approval engine of its own

An app operating on OpenRegister-owned objects SHALL NOT ship its own
step-routing engine. Specifically it SHALL NOT implement: ordered approval
steps with its own advance-on-approval logic; its own approver-group or role
resolution; its own pending/approved/rejected state machine over an approval
object; or its own deadline sweep over approval rows.

It SHALL instead provision a task template, open a sequence, and read its own
work through the task inbox.

An app SHALL NOT store a derived overdue flag as a status value or as a
column. Overdue is computed from the clock against the advisory and enforcing
dates, and a stored copy is only as correct as the last job that remembered to
write it.

An app SHALL NOT declare a schema that mirrors the fields of a flow
definition or a task. A mirrored store drifts from the store the engine
actually reads, and the drift is silent — the list shows one thing while the
engine runs another.

These rules SHALL be mechanically enforceable, and the enforcement SHALL name
the retired OpenRegister classes and routes as well as the shapes, so an app
still calling the removed approval API fails the check rather than failing at
runtime.

#### Scenario: A home-grown step engine is detected

- **GIVEN** an app shipping an ordered-approval service with its own advance-on-approval logic
- **WHEN** the anti-pattern check runs against it
- **THEN** the check MUST report the finding with the file and the rule it broke
- @e2e exclude gate behaviour — covered by the gate's own fixture suite

#### Scenario: A stored overdue flag is detected

- **GIVEN** an app schema whose status enum contains an overdue value, or which declares an overdue property
- **WHEN** the anti-pattern check runs
- **THEN** the check MUST report it
- @e2e exclude gate behaviour — covered by the gate's own fixture suite

#### Scenario: A call to a removed approval route is detected

- **GIVEN** an app calling a retired approval chain or step route, or registering a listener for a retired approval event
- **WHEN** the anti-pattern check runs
- **THEN** the check MUST report it as a broken integration, not as a style finding
- @e2e exclude gate behaviour — covered by the gate's own fixture suite

### Requirement: An app contributes node types and resolves flow definitions from the flow entity

An app that extends the flow engine SHALL contribute node types through the
engine's node-registration event, and SHALL resolve flow definitions from
OpenRegister's flow entity. It SHALL NOT keep its own object-schema copy of a
flow definition's nodes, edges, limits, trigger or schedule.

Where such a mirror exists, every surface that lists, edits, seeds or reads a
definition SHALL read the flow entity, and the mirror SHALL be retired in the
owning app rather than left declared-but-unused. A declared mirror is a mirror
that will be written to again.

A definition resolved for a run SHALL be the pinned published version, so an
app's contributed nodes cannot change the graph a suspended run resumes on.

#### Scenario: An app's flow list reads the flow entity

- **GIVEN** an app that contributes node types and authors flows
- **WHEN** its flow list is loaded
- **THEN** the rows MUST come from the flow entity
- **AND** no object-schema mirror MUST be read
- @e2e exclude cross-app contract — covered by the contributing app's own tests

#### Scenario: A declared definition mirror is a finding

- **GIVEN** an app schema declaring nodes and edges alongside a trigger and a schedule
- **WHEN** the anti-pattern check runs
- **THEN** the check MUST report it as a flow-definition mirror
- @e2e exclude gate behaviour — covered by the gate's own fixture suite

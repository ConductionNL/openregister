## Purpose

One step type that puts a performer into a flow graph: it creates a task,
parks the run until that task reaches a terminal state, hands the outcome to
the nodes downstream so they can route on it, and terminates its task when
the run or the branch that owns it dies.

## ADDED Requirements

### Requirement: A user-task step creates exactly one task and suspends the run

The system SHALL provide a step node type `openregister.user-task`. On its
first firing with items, the node SHALL create ONE task through the
`flow-tasks` task service and SHALL then suspend the run.

The created task SHALL carry the run's uuid and this node's id as
provenance, so the task can be traced back to the step that asked for it and
so cancellation propagation can find it.

The node SHALL NOT create a second task on a later firing of the same node
in the same run. Whether a task already exists SHALL be determined from THIS
node's own resume slot, not from run-level state — a heartbeat wake, a lost
delivery, or a duplicated worker pass MUST NOT produce a second task in
somebody's inbox.

A firing that carries NO items SHALL create no task and SHALL NOT suspend
the run: suspension is a run-level act, and an empty branch reaching this
node is the normal case in a priority-ordered graph.

Task creation SHALL be delegated in full. The node SHALL NOT define a task
field, a lifecycle state, a routing strategy or an authorization rule of its
own; every one of those belongs to `flow-tasks`.

#### Scenario: The first firing produces a task and a suspended run

- **GIVEN** a flow whose graph contains one `openregister.user-task` node
- **WHEN** the run reaches that node carrying one item
- **THEN** exactly one task MUST exist carrying the run's uuid and the node's
  id
- **AND** the run MUST be suspended
- @e2e a flow with a user task suspends and the task appears in the inbox

#### Scenario: A heartbeat wake does not create a second task

- **GIVEN** a run suspended on a user-task node whose task is still open
- **WHEN** the worker wakes it on its heartbeat and the node fires again
- **THEN** the task count for that run and node MUST still be one
- **AND** the run MUST suspend again
- @e2e exclude covered by UserTaskNode unit tests over a resumed context

#### Scenario: An empty branch creates nothing

- **GIVEN** a routing node that sent every item down a sibling branch
- **WHEN** the user-task node on the empty branch fires with no items
- **THEN** no task MUST be created
- **AND** the run MUST NOT suspend
- @e2e exclude engine-internal suspend rule — covered by UserTaskNode unit
  tests

### Requirement: The run continues on task TERMINALITY, never on a nudge

A suspended user-task node SHALL continue the run only when its task has
reached a terminal state. While the task is non-terminal the node SHALL
suspend again.

The node SHALL read the TASK to decide this. It SHALL NOT accept the run
context's signal slot as an answer: a signal is one slot per run, consumed
by the walk it wakes, and a flow with two user-task nodes would otherwise
have the second read an answer given to the first.

Completing the task SHALL make the run due. A completion that is delivered
while the run is still mid-walk, or whose delivery fails, SHALL NOT strand
the run: the node SHALL suspend with a heartbeat `resumeAt` so a lost wake
costs one heartbeat interval rather than the flow. The node SHALL NOT
suspend with a null `resumeAt`.

`POST /api/flow-runs/{uuid}/resume` SHALL NOT be able to complete a user
task. That endpoint authorizes only that the caller may run the FLOW; a user
task is completed through the task service's authorized verbs or not at all.
A resume posted against a run suspended on a user-task node SHALL leave the
task and the run's suspension unchanged.

#### Scenario: Completing the task advances the run

- **GIVEN** a run suspended on a user-task node
- **WHEN** the assignee completes the task
- **THEN** the run MUST become due
- **AND** on the next advance the node MUST NOT suspend again
- @e2e completing a task from the inbox advances its flow run

#### Scenario: A claim is not a completion

- **GIVEN** a run suspended on a user-task node whose task is unclaimed
- **WHEN** a pool member claims it
- **THEN** the run MUST remain suspended
- @e2e exclude covered by UserTaskNode unit tests over a claimed task

#### Scenario: The resume endpoint cannot answer for a performer

- **GIVEN** a run suspended on a user-task node, and an authenticated user
  who may run the flow but is not the task's performer
- **WHEN** that user posts a decision to the run's resume endpoint
- **THEN** the task MUST remain non-terminal
- **AND** the run MUST remain suspended
- @e2e a flow-runner who is not the performer cannot answer a user task

#### Scenario: A suspended user task is reachable by the clock

- **GIVEN** a run suspended on a user-task node
- **WHEN** its persisted resume time is read
- **THEN** it MUST NOT be null
- @e2e exclude covered by a unit test asserting the thrown suspension

### Requirement: The outcome is written onto every item, not only onto the run

When the node continues, it SHALL write the task's completion result onto
EVERY item it passes on, under a configurable key defaulting to `task`.

The written value SHALL carry at minimum the outcome, the comment where one
was given, the completing identity, the performer type, and the
`on_behalf_of` identity where the completion was delegated.

Writing it onto the items rather than into run-level context is normative,
not incidental: the steps that follow route PER ITEM, and a switch cannot
branch on something only the run holds.

The node SHALL leave any item that is not a value bag untouched rather than
failing the run.

#### Scenario: A downstream switch branches on the outcome

- **GIVEN** a user-task node followed by a switch keyed on the outcome
- **WHEN** the task is completed with a rejecting outcome
- **THEN** every item leaving the node MUST carry that outcome under the
  configured key
- **AND** the switch MUST take the rejection edge
- @e2e a rejected task routes the flow down its rejection branch

#### Scenario: A delegated completion names both identities on the item

- **GIVEN** a task completed by a delegate acting on behalf of the assignee
- **WHEN** the run continues
- **THEN** the item payload MUST name the delegate as the completing
  identity and the assignee as the on-behalf-of identity
- @e2e exclude covered by UserTaskNode unit tests over a delegated
  completion

### Requirement: A rejecting outcome is a branch, not a failure

A task completed with a rejecting or returning outcome SHALL by default
continue the run so the author can route on it. It SHALL NOT fail the run,
and SHALL NOT be recorded as an error.

The node SHALL offer an opt-in setting that turns a rejection into a
deliberate stop for the flows where a no really is a fault. When that
setting is off — the default — the run's status after a rejection SHALL be
indistinguishable from the run's status after an approval.

A task that reached a terminal state WITHOUT a completion — terminated,
expired or cancelled — SHALL be distinguishable downstream from one that was
completed with a rejecting outcome. Both are terminal; only one of them is a
person's decision.

#### Scenario: A rejection carries on by default

- **GIVEN** a user-task node with the fail-on-reject setting off
- **WHEN** its task is completed with a rejecting outcome
- **THEN** the run MUST continue past the node
- **AND** the run MUST NOT be marked failed
- @e2e exclude covered by UserTaskNode unit tests

#### Scenario: A flow that opts in stops on a rejection

- **GIVEN** a user-task node with the fail-on-reject setting on
- **WHEN** its task is completed with a rejecting outcome
- **THEN** the run MUST end with a reason naming the rejection
- @e2e exclude covered by UserTaskNode unit tests

#### Scenario: A terminated task is not a rejection

- **GIVEN** a task terminated by expiry rather than completed
- **WHEN** the run continues past the node
- **THEN** the item payload MUST distinguish it from a rejecting completion
- @e2e exclude covered by UserTaskNode unit tests over a terminated task

### Requirement: Several user-task nodes in one flow keep independent state

A flow containing more than one `openregister.user-task` node SHALL keep
each node's task reference, question and progress in that node's own resume
slot.

One node's task SHALL NOT be readable or overwritable by another node, and
completing one node's task SHALL NOT continue a different node. A flow with
two sequential approvals SHALL require two completions.

The record of WHEN each task was created SHALL be written once and SHALL NOT
be restamped by a heartbeat wake. A creation time that resets every
heartbeat would report every long-waiting task as minutes old — which is
exactly the reading that stops anyone chasing it.

#### Scenario: Two approvals require two answers

- **GIVEN** a flow with two sequential user-task nodes
- **WHEN** the first node's task is completed
- **THEN** the run MUST suspend again on the second node
- **AND** a second, distinct task MUST exist
- @e2e a two-approval flow requires both approvals

#### Scenario: A heartbeat does not restamp the asked-at time

- **GIVEN** a user-task node suspended for several heartbeat intervals
- **WHEN** the node's recorded creation time is read
- **THEN** it MUST equal the time the task was created
- @e2e exclude covered by a clock-controlled unit test

### Requirement: The advance budget says how far a completion may push the run

The node SHALL accept an `advance` budget with exactly three shapes:

- `0` — the DEFAULT. The completion parks the run as due and returns; the
  worker advances it on its next pass.
- a positive integer `N` — the completing request continues the run for at
  most N transitions, then leaves the remainder to the worker.
- the string `"all"` — the completing request continues until the run
  suspends again, reaches another user task, or ends.

Unlimited SHALL be spelled `"all"`. The system SHALL NOT accept `null`,
an empty string, or an absent value as a synonym for unlimited, and SHALL
reject `null` at config validation naming the value. A missing budget SHALL
mean `0`.

Every budget SHALL remain bounded by the engine's existing transition
ceiling and by the pre-hop oversight check. An in-request continuation SHALL
be subject to the SAME oversight veto as a worker-driven one, and an
oversight check that cannot complete SHALL refuse the hop rather than be
skipped because the caller was in a hurry.

An error raised while continuing in-request SHALL NOT lose the completion:
the task SHALL remain completed and the run SHALL remain advanceable by the
worker. The completing caller SHALL be told that the task was accepted even
when the continuation did not finish.

#### Scenario: The default parks for the worker

- **GIVEN** a user-task node with no `advance` configured
- **WHEN** its task is completed
- **THEN** the completing request MUST return with the run still suspended
  and due
- **AND** the run MUST advance on the next worker pass
- @e2e exclude covered by unit tests over the completion listener

#### Scenario: A budget of "all" runs to the next stopping point

- **GIVEN** a user-task node with `advance` set to `"all"`, followed by two
  ordinary steps and an end node
- **WHEN** its task is completed
- **THEN** the completing request MUST return the run already ended
- @e2e completing a task with an "all" budget finishes the run in one request

#### Scenario: null is refused, not read as unlimited

- **GIVEN** a flow saved with `advance` set to `null` on a user-task node
- **WHEN** the node's configuration is validated
- **THEN** validation MUST fail with an error naming the value and stating
  that unlimited is spelled `"all"`
- @e2e exclude covered by UserTaskNode config-validation unit tests

#### Scenario: An oversight veto still applies in-request

- **GIVEN** a user-task node with `advance` set to `"all"` and an oversight
  check that vetoes the next hop
- **WHEN** its task is completed
- **THEN** the run MUST stop with the veto's reason and the check's id
- **AND** the hop MUST NOT be taken
- @e2e exclude covered by unit tests with a vetoing oversight check

#### Scenario: A failed continuation does not lose the answer

- **GIVEN** a user-task node with a positive `advance` budget and a
  downstream step that throws
- **WHEN** its task is completed
- **THEN** the task MUST remain completed
- **AND** the completing caller MUST be told the task was accepted
- @e2e exclude covered by unit tests with an injected downstream failure

### Requirement: A task whose run or branch has died is terminated, not orphaned

When a run reaches a terminal status, every non-terminal task created by any
user-task node in that run SHALL be terminated with a reason naming the run
and its terminal status.

When a branch decision makes a user-task node unreachable — a competing
branch settled the choice, or the stage the node belonged to closed — that
node's non-terminal task SHALL be terminated with a reason naming the
branch.

A terminated task SHALL disappear from every inbox as actionable work, and
SHALL NOT be deleted: its audit trail records who or what terminated it.

Propagation SHALL be idempotent. Run terminality can be observed more than
once — by the completing request and by the worker's reaper — and a second
observation SHALL be a no-op rather than a second termination entry.

Propagation SHALL NEVER reach a task that carries no run uuid.

#### Scenario: Stopping a run empties its inboxes

- **GIVEN** a run suspended on two user-task nodes with two open tasks
  assigned to two people
- **WHEN** the run is stopped
- **THEN** both tasks MUST be terminated with a reason naming the run
- **AND** neither MUST appear as actionable in its assignee's inbox
- @e2e stopping a run removes its tasks from the assignees' inboxes

#### Scenario: A losing parallel branch takes its task with it

- **GIVEN** a flow with two parallel branches, each with a user-task node,
  where settling either branch makes the other moot
- **WHEN** the first branch's task is completed
- **THEN** the second branch's task MUST be terminated with a reason naming
  the branch
- @e2e exclude covered by cancellation-propagation unit tests

#### Scenario: Observing terminality twice terminates once

- **GIVEN** a stopped run whose task was already terminated by propagation
- **WHEN** the worker observes the run's terminality again
- **THEN** no second termination MUST be recorded
- @e2e exclude covered by cancellation-propagation unit tests

### Requirement: The node describes its own form, served from the node catalog

The node SHALL declare its configuration vocabulary and a server-driven form
describing every field it accepts, and both SHALL be published through the
existing flow node catalog endpoint so a builder needs no hardcoded field
table.

The form SHALL cover at minimum: what the task is (title and description
templates), who may perform it (candidate users, groups or role, plus the
routing strategy and fallback), how urgent it is (priority), when it is due
and when it expires, the outcome vocabulary the flow will branch on, the
item key the outcome is written under, whether a rejection fails the run,
the heartbeat interval, and the `advance` budget.

The node SHALL be offered in both the administrator and the ordinary-user
palette scopes: asking a person to do something grants no privilege the
caller did not already have, and the task service authorizes the answer
independently.

A configuration naming no candidate performer of any kind SHALL be rejected
at validation. A task nobody can be found for is not a task; leaving it to
fail at run time buries the mistake in a suspended run.

#### Scenario: The catalog serves the node's form

- **GIVEN** an authenticated caller requesting the flow node catalog
- **WHEN** the response is read
- **THEN** it MUST contain an entry for `openregister.user-task` carrying a
  non-empty form description
- @e2e the node catalog offers the user-task node with its form

#### Scenario: A task with no possible performer is refused at save time

- **GIVEN** a user-task node configured with no candidate user, group or role
  and no routing fallback
- **WHEN** its configuration is validated
- **THEN** validation MUST fail with an error saying no performer can be
  resolved
- @e2e exclude covered by UserTaskNode config-validation unit tests

### Requirement: The signal node keeps machine-to-machine work

`openregister.await-signal` SHALL remain available and SHALL be unchanged by
this capability. Its heartbeat, its nudge-is-not-an-answer rule and its
opt-in fail-on-reject SHALL keep working exactly as before.

The division SHALL be stated in both nodes' palette descriptions so an
author picks correctly without reading the source: a signal is for a system
that will call back; a user task is for a performer who has to be found,
told, and allowed to say no.

A flow MAY contain both. A signal delivered to a run suspended on a
user-task node SHALL NOT continue that node, and completing a task SHALL NOT
continue an awaiting signal node.

#### Scenario: An existing signal flow is unaffected

- **GIVEN** a flow using `openregister.await-signal` that worked before this
  capability
- **WHEN** it is run and signalled
- **THEN** it MUST behave identically to before
- @e2e exclude regression covered by the existing AwaitSignalNode tests

#### Scenario: The two wait mechanisms do not answer each other

- **GIVEN** a flow containing both an awaiting signal node and a user-task
  node
- **WHEN** the run is suspended on the user-task node and a signal is
  delivered
- **THEN** the user task MUST remain non-terminal and the run MUST remain
  suspended
- @e2e exclude covered by unit tests over a mixed flow

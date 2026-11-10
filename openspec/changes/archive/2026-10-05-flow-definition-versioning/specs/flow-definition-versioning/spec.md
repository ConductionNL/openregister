## Purpose

Gives a flow definition a version and a `draft`/`published`/`deprecated`
lifecycle, and binds every run to the exact version it started on, so a run
that waits days for a person still finishes on the process it began.

## ADDED Requirements

### Requirement: A flow definition carries a version and a lifecycle status

A flow SHALL carry a `version` (a positive integer) and a `lifecycleStatus`
of exactly `draft`, `published` or `deprecated`. The meaning of each state
SHALL be:

- `draft` — editable; MUST NOT back a new triggered, scheduled or sub-flow
  run.
- `published` — immutable; backs new runs. A flow SHALL have **at most one**
  `published` version at any time.
- `deprecated` — immutable; MUST NOT back a new run, and runs already pinned
  to it SHALL continue and finish on it.

The flow's identity SHALL NOT move when its version does: the flow uuid that
apps, trigger records and stored `flowId` configuration refer to SHALL keep
addressing the same flow across every version of it.

`lifecycleStatus` SHALL be independent of the flow's `enabled` flag. A
published flow may be switched off, and a switched-off flow is not a
deprecated one.

#### Scenario: A new flow starts as a draft

- **GIVEN** an author creates a flow
- **WHEN** the flow is first saved
- **THEN** its lifecycle status MUST be `draft` and its version MUST be `1`
- **AND** no published version MUST exist for it
- @e2e exclude persistence contract — covered by flow lifecycle unit tests

#### Scenario: Publishing a new version deprecates its predecessor

- **GIVEN** a flow whose version 2 is `published` and whose version 3 is a
  `draft`
- **WHEN** version 3 is published
- **THEN** version 3 MUST become `published` and version 2 MUST become
  `deprecated` in the same transaction
- **AND** the flow MUST still have exactly one `published` version
- @e2e exclude transactional lifecycle rule — covered by lifecycle guard unit
  tests

#### Scenario: A published flow can be disabled without being deprecated

- **GIVEN** a flow with a published version
- **WHEN** its `enabled` flag is set to false
- **THEN** the published version MUST remain `published`
- **AND** new triggers MUST NOT queue runs, for the reason "disabled", not
  "deprecated"
- @e2e exclude flag orthogonality — covered by unit tests

### Requirement: A published version is immutable, and editing produces a new draft

Once published, a version's graph — its nodes, edges and limits — SHALL NOT
change. An attempt to write a definition change onto a flow whose head is
`published` SHALL be REFUSED with a client error carrying a
machine-readable reason, and SHALL NOT be silently applied, merged, or
turned into a new version behind the author's back.

Creating a draft from a published flow SHALL copy the published graph into a
new draft at version N+1 and leave version N published and backing new runs
until the draft is itself published.

Deleting or discarding a draft SHALL NOT affect any published or deprecated
version, and SHALL NOT affect any run.

#### Scenario: Editing a published flow is refused

- **GIVEN** a flow whose head version is `published`
- **WHEN** a client submits a changed set of nodes or edges for it
- **THEN** the request MUST be refused with a 409 and a machine-readable
  reason naming the lifecycle state
- **AND** the stored graph MUST be byte-identical to what it was before the
  request
- @e2e exclude API contract — covered by controller tests and Newman

#### Scenario: Creating a draft leaves the published version serving

- **GIVEN** a flow with published version 4 and a trigger wired to it
- **WHEN** the author creates a draft version 5 and edits it
- **THEN** version 4 MUST remain `published`
- **AND** runs queued by that trigger while version 5 is still a draft MUST
  be pinned to version 4
- @e2e exclude lifecycle + pinning interaction — covered by integration tests

### Requirement: A run is pinned to a definition version when it is queued

Every run SHALL record the flow version that backs it at the moment it is
queued, and that recorded version SHALL NOT change for the life of the run.
Pinning SHALL apply to every dispatch path without exception — manual,
object trigger, schedule, MCP, workflow-engine operation and sub-flow call.

A run SHALL be queued against the flow's `published` version. A `draft` or
`deprecated` version SHALL NOT back a newly queued run.

The pre-queue refusal that rejects a flow with a node its token cannot leave
SHALL inspect the version being pinned, not the flow's editable head — a
draft's dead end is not grounds to refuse a run of the published version,
and a published version's dead end MUST NOT be hidden by a repaired draft.

An interactive test run is the one exception to "a draft cannot back a run":
it SHALL be permitted against a draft, and SHALL carry the exact draft graph
it was started with on the run itself, so it is pinned in the same sense
every other run is. A test run of a draft SHALL be distinguishable from a run
of a published version wherever runs are listed.

#### Scenario: A queued run records its version

- **GIVEN** a flow with published version 3
- **WHEN** an object trigger queues a run
- **THEN** the run record MUST carry version 3
- @e2e exclude persistence contract — covered by queue-path unit tests

#### Scenario: Publishing a new version does not move a queued run

- **GIVEN** a run queued against published version 3 and not yet started
- **WHEN** version 4 is published before the worker reaches that run
- **THEN** the run MUST still be pinned to version 3 and MUST execute
  version 3's graph
- @e2e exclude engine-internal pinning — covered by advancer unit tests

#### Scenario: The dead-end refusal judges the pinned version

- **GIVEN** a flow whose published version 2 is fully wired and whose draft
  version 3 has a node with no outgoing edge
- **WHEN** a trigger queues a run
- **THEN** the run MUST be accepted and pinned to version 2
- @e2e exclude preflight scoping — covered by dead-end unit tests

### Requirement: A run advances against its pinned version, never the live definition

Each time a run is advanced, the engine SHALL resolve the definition by
flow AND pinned version, and SHALL lower and walk that document. It SHALL
NOT read the flow's current head, and SHALL NOT substitute a newer version
for the pinned one under any circumstance — including a run resumed after an
arbitrarily long suspension.

Definition resolution caching SHALL be keyed by flow AND version. Two runs of
the same flow pinned to different versions, advanced in the same worker
batch, SHALL each receive their own version's graph.

The run's persisted marking SHALL therefore always name places that exist in
the document being walked, for as long as the pinned version exists.

#### Scenario: A run suspended across an edit resumes on its own version

- **GIVEN** a run pinned to version 1, suspended on an external signal
- **AND** version 2 has since been published with a node renamed
- **WHEN** the signal arrives fourteen days later and the run is advanced
- **THEN** the run MUST resume against version 1
- **AND** its marking MUST resolve without a dangling place
- @e2e exclude long-suspension behaviour — covered by advancer integration
  tests with a clock double

#### Scenario: Two versions of one flow advance in the same batch

- **GIVEN** one run pinned to version 1 and another pinned to version 2 of
  the same flow
- **WHEN** a single worker pass advances both
- **THEN** each MUST execute the graph of its own version
- **AND** neither MUST observe the other's nodes or edges
- @e2e exclude resolver cache keying — covered by locator unit tests

### Requirement: A run whose pinned version is gone fails loudly and is never re-pointed

When a run's pinned version cannot be resolved — the flow was deleted, the
owning app was removed, or the version row is absent — the run SHALL be
failed with an error that names BOTH the flow and the version that could not
be found. That error SHALL be distinguishable from the existing "no app
provides this flow" case, so an operator can tell "the flow is gone" from
"this version of it is gone".

The engine SHALL NOT fall back to the flow's head, to the latest published
version, or to any other version. Silently promoting an in-flight run onto a
different definition is forbidden, because the run's marking, its taken
decisions and its log all belong to the version it started on.

The run SHALL NOT be left queued, so a run whose definition disappeared can
never sit in the queue indefinitely being retried.

#### Scenario: The pinned version was deleted

- **GIVEN** a suspended run pinned to version 2, and version 2 has been
  removed
- **WHEN** the worker next advances it
- **THEN** the run MUST end in a failed state
- **AND** its error MUST name both the flow and version 2
- **AND** it MUST NOT have executed any node of any other version
- @e2e exclude failure path — covered by advancer unit tests

#### Scenario: A newer version is not a substitute

- **GIVEN** a run pinned to a version that no longer resolves, while a newer
  published version of the same flow exists
- **WHEN** the run is advanced
- **THEN** the run MUST fail
- **AND** it MUST NOT be re-pinned to the newer version
- @e2e exclude no-fallback rule — covered by advancer unit tests

### Requirement: A sub-flow call pins the child run at call time

When a step runs another flow, the child SHALL be pinned to the child flow's
own `published` version resolved at the moment the step executes — not
inherited from the parent's version number, and not resolved when the parent
was queued.

This SHALL hold for both sub-flow shapes: the waiting call, whose child run
executes within the parent's step, and the fire-and-forget call, whose child
run is queued.

A sub-flow step whose named flow has no `published` version SHALL fail the
step with a reason naming the flow and its lifecycle state, and SHALL NOT
fall back to that flow's draft.

#### Scenario: The child pins its own published version

- **GIVEN** parent flow P pinned to version 1, calling child flow C
- **AND** C's published version is 7
- **WHEN** the sub-flow step executes
- **THEN** the child run MUST be pinned to C version 7
- @e2e exclude sub-flow pinning — covered by sub-flow node unit tests

#### Scenario: A child with only a draft refuses the call

- **GIVEN** a sub-flow step naming a flow that has never been published
- **WHEN** the step executes
- **THEN** the step MUST fail with a reason naming the flow and its draft
  state
- **AND** the draft MUST NOT have been executed
- @e2e exclude sub-flow refusal — covered by sub-flow node unit tests

### Requirement: Trigger matching answers which flow; the queue path answers which version

The trigger index SHALL remain keyed by flow, event, register and schema,
without a version dimension, so the cost of matching a trigger on an object
write does not grow with the number of versions a flow has.

Only a flow's `published` version SHALL contribute trigger records.
Publishing a version SHALL rebuild that flow's trigger records from the
version being published; deprecating the last published version SHALL remove
them. A draft's trigger nodes SHALL NOT match anything.

#### Scenario: A draft's new trigger does not fire

- **GIVEN** published version 1 with an object-created trigger, and draft
  version 2 that adds an object-updated trigger
- **WHEN** an object of that schema is updated
- **THEN** no run MUST be queued
- @e2e exclude trigger index scoping — covered by trigger service unit tests

#### Scenario: Publishing swaps the trigger set atomically

- **GIVEN** published version 1 triggering on schema A and draft version 2
  triggering on schema B
- **WHEN** version 2 is published
- **THEN** writes to schema B MUST queue runs pinned to version 2
- **AND** writes to schema A MUST NOT queue runs
- @e2e exclude trigger rebuild — covered by integration tests

### Requirement: The upgrade leaves nothing in flight unresolvable

The migration that introduces versioning SHALL publish version 1 of every
existing flow from that flow's current stored graph, and SHALL pin
`version 1` onto every run that is not in a terminal state at upgrade time.

No run that was queued or suspended before the upgrade SHALL be left with an
unresolvable pin, and none SHALL be failed by the upgrade itself.

The migration SHALL be idempotent: running it twice SHALL NOT create a second
version 1, and SHALL NOT re-pin a run that is already pinned.

#### Scenario: A suspended pre-upgrade run keeps running

- **GIVEN** a run suspended on a signal before the upgrade, with no version
  recorded
- **WHEN** the migration runs and the signal then arrives
- **THEN** the run MUST be pinned to version 1
- **AND** it MUST advance against the graph that was live at upgrade time
- @e2e exclude migration behaviour — covered by migration tests against a
  seeded database

#### Scenario: Re-running the migration changes nothing

- **GIVEN** a database already migrated
- **WHEN** the migration is applied again
- **THEN** the version rows and run pins MUST be unchanged
- @e2e exclude idempotency — covered by migration tests

### Requirement: The editor states which version it is showing and why it is read-only

The flow editing surface SHALL show, for the flow being viewed, its version
number and its lifecycle status, and SHALL make a published or deprecated
version non-editable in the interface rather than letting an author edit it
and discover the refusal on save.

A published flow SHALL offer an explicit action to create a draft version.
An author SHALL be able to list a flow's versions and open any one of them
read-only.

A run's detail SHALL show the version it is pinned to, and SHALL mark a run
pinned to a deprecated version as such, so "why is this run behaving
differently from the flow I am looking at" is answerable without reading the
database.

#### Scenario: A published flow's canvas is read-only

- **GIVEN** an author opens a flow whose head version is published
- **THEN** the canvas MUST NOT accept node or edge edits
- **AND** a "create draft version" action MUST be offered
- **AND** the version number and lifecycle status MUST be visible
- @e2e covered by the flow editor lifecycle e2e spec

#### Scenario: A run shows its pinned version

- **GIVEN** a run pinned to version 2 of a flow whose head is version 4
- **WHEN** the run detail is opened
- **THEN** version 2 MUST be shown as the run's definition
- **AND** the run MUST be marked as running a version that is no longer the
  published one
- @e2e covered by the flow run detail e2e spec

## Purpose

One step type that asks a party OUTSIDE the Nextcloud instance to do
something: it matches the party from the case, creates an external task,
parks the run heartbeat-safe, delivers the ask through the portal, accepts
an answer whose files land on the case object, refuses everyone but the
matched party, and can ask again with a reason.

## ADDED Requirements

### Requirement: A portal-task step creates one external task and suspends the run

The system SHALL provide a step node type `openregister.portal-task`. On
its first firing with items, the node SHALL create ONE task through the
`flow-tasks` task service with performer type `external`, carrying the
run's uuid and this node's id as provenance, and SHALL then suspend the
run.

Whether a task already exists for this firing SHALL be determined from
this node's own resume slot, exactly as `flow-user-task-node` specifies: a
heartbeat wake, a lost delivery or a duplicated worker pass MUST NOT
produce a second ask.

A firing that carries no items SHALL create no task and SHALL NOT suspend
the run.

The node SHALL delegate task creation in full to the task service. It
SHALL NOT define a task field, a lifecycle state or an authorization rule
of its own.

#### Scenario: The first firing produces an external task and a suspended run

- **GIVEN** a flow whose graph contains one `openregister.portal-task` node
  and a subject case object naming an initiator
- **WHEN** the run reaches that node carrying one item
- **THEN** exactly one task MUST exist with performer type `external`,
  carrying the run's uuid and the node's id
- **AND** the run MUST be suspended
- @e2e a flow with a portal task suspends and the task reaches the portal
  subject

#### Scenario: A heartbeat wake does not ask twice

- **GIVEN** a run suspended on a portal-task node whose task is still open
- **WHEN** the worker wakes it on its heartbeat and the node fires again
- **THEN** the task count for that run and node MUST still be one
- **AND** the run MUST suspend again
- @e2e exclude covered by PortalTaskNode unit tests over a resumed context

#### Scenario: An empty branch creates nothing

- **GIVEN** a routing node that sent every item down a sibling branch
- **WHEN** the portal-task node on the empty branch fires with no items
- **THEN** no task MUST be created
- **AND** the run MUST NOT suspend
- @e2e exclude engine-internal suspend rule, covered by PortalTaskNode unit
  tests

### Requirement: The matched party comes from the case and is frozen at creation

The node's configuration SHALL name a party ROLE on the subject case
object, defaulting to `initiator`. At task creation the system SHALL
resolve that role against the case object to a party reference, SHALL
store the resolved reference on the task, and SHALL record the resolution
in the task audit.

The stored reference SHALL NOT be re-resolved afterwards. A later edit to
the case's party data SHALL NOT transfer an open task; correcting a wrong
match SHALL be done by cancelling or re-asking, which creates a new task
with a new match and a new audit entry.

A firing whose case names NO party for the configured role SHALL fail the
firing with an error naming the role and the case, and SHALL NOT create a
task. An unperformable ask parked in a suspended run buries the mistake.

#### Scenario: The initiator is matched and recorded

- **GIVEN** a portal-task node with the default party role and a case whose
  initiator is a known party
- **WHEN** the node fires
- **THEN** the created task MUST store that party's reference
- **AND** the audit MUST record the role and the resolved reference
- @e2e exclude covered by party-matching unit tests

#### Scenario: A case with no initiator fails loudly

- **GIVEN** a portal-task node whose subject case object names no party for
  the configured role
- **WHEN** the node fires with items
- **THEN** the firing MUST fail with an error naming the role
- **AND** no task MUST be created
- @e2e exclude covered by party-matching unit tests

#### Scenario: Editing the case does not move an open ask

- **GIVEN** an open portal task matched to party A
- **WHEN** the case's initiator is changed to party B
- **THEN** the task's stored party reference MUST still be party A
- **AND** party B MUST NOT be able to complete it
- @e2e exclude covered by completion-authorization unit tests

### Requirement: The suspension is heartbeat-safe and continues on task terminality

The node SHALL suspend with a non-null heartbeat `resumeAt`, defaulting to
15 minutes and clamped to a 5-minute floor, matching the shipped waiters.
It SHALL NEVER suspend with a null `resumeAt`: that is the only shape
`findAbandonedSignals` reaps, and the 14-day failure it drives is shorter
than an ordinary hersteltermijn.

A suspended portal-task node SHALL continue the run only when its task has
reached a terminal state, read from the TASK, never from the run context's
signal slot. While the task is non-terminal the node SHALL suspend again
without restamping its recorded creation time.

When the node continues, it SHALL write the task's result onto EVERY item
it passes on, under a configurable key defaulting to `portalTask`,
carrying at minimum the outcome, the submitted answer fields, the stored
file references, and the matched party reference. A task that went
terminal WITHOUT a completion (terminated, expired) SHALL be
distinguishable downstream from a completed one.

#### Scenario: A suspended portal task is reachable by the clock

- **GIVEN** a run suspended on a portal-task node
- **WHEN** its persisted resume time is read
- **THEN** it MUST NOT be null
- @e2e exclude covered by a unit test asserting the thrown suspension

#### Scenario: Completing the task advances the run with the answer on the items

- **GIVEN** a run suspended on a portal-task node
- **WHEN** the matched party completes the task
- **THEN** the run MUST become due and continue past the node
- **AND** every item leaving the node MUST carry the outcome and the file
  references under the configured key
- @e2e a resident's completed portal task advances the case flow

#### Scenario: An expired ask is not an answer

- **GIVEN** a portal task transitioned terminally by expiry enforcement
- **WHEN** the run continues past the node
- **THEN** the item payload MUST distinguish the expiry from a completion
- @e2e exclude covered by PortalTaskNode unit tests over a terminated task

### Requirement: Delivery rides the portal contribution surface and nothing else

Creating an external task, and every re-ask, SHALL record a delivery
request for a portal inbox message and a mail to the matched party. The
delivery state SHALL be queryable, so an undelivered ask reads as "not
yet delivered" rather than as silence.

The system SHALL expose a subject-scoped read listing a portal subject's
open portal tasks with their case context, shaped for consumption through
the ADR-046 contribution contract: descriptors aggregate, rows stay behind
subject-scoped readers, and one subject's read MUST NOT return or count
another subject's tasks.

An external task SHALL NOT be delivered through `INotificationManager`,
SHALL NOT be projected to CalDAV, and SHALL NOT appear in any Nextcloud
user's or group's inbox. The rendering of the portal task and its upload
form is portaliq's own change and is NOT specified here.

A delivery request that cannot be recorded SHALL NOT roll back the task or
the suspension: the ask outlives a delivery outage, and the queryable
delivery state is what makes the outage visible.

#### Scenario: The task is visible to its matched subject and to nobody else

- **GIVEN** an open portal task matched to subject A, and portal subjects A
  and B
- **WHEN** each subject's portal task list is read
- **THEN** subject A's list MUST contain the task with its case context
- **AND** subject B's list MUST NOT contain it and MUST NOT count it
- @e2e a portal task is listed for its matched subject and hidden from
  another subject

#### Scenario: No Nextcloud channel carries the ask

- **GIVEN** a portal task created for an external party
- **WHEN** delivery runs
- **THEN** no Nextcloud notification MUST be sent
- **AND** no VTODO projection MUST be written
- @e2e exclude covered by delivery-seam unit tests

#### Scenario: A failed delivery leaves the ask standing and visible

- **GIVEN** a portal task whose delivery request cannot be recorded
- **WHEN** the firing finishes
- **THEN** the task MUST exist and the run MUST be suspended
- **AND** the delivery state MUST read as not delivered
- @e2e exclude covered by delivery-seam unit tests

### Requirement: An upload completion lands as a file attachment on the case object

A completion MAY carry files, and the node's configuration SHALL declare
whether at least one file is REQUIRED, how many are accepted, and the
accepted types and maximum size. A completion violating a constraint SHALL
be refused naming the constraint, and the task SHALL remain open.

Each accepted file SHALL be stored as an OpenRegister file attachment on
the CASE object, through the file service, BEFORE the completion is
recorded; the completion SHALL reference the stored files rather than
carrying bytes. Any dossier folder view of the file is a projection of
that attachment (decision 2026-08-31); no portal-private file store SHALL
be written.

#### Scenario: The uploaded file is on the case

- **GIVEN** a portal task requiring one file
- **WHEN** the matched party completes it with a valid file
- **THEN** the file MUST exist as a file attachment on the case object
- **AND** the completion MUST reference it
- @e2e a resident's upload appears as a file on the case object

#### Scenario: A required upload cannot be skipped

- **GIVEN** a portal task requiring one file
- **WHEN** the matched party submits a completion with no file
- **THEN** the completion MUST be refused naming the requirement
- **AND** the task MUST remain open
- @e2e exclude covered by completion-validation unit tests

#### Scenario: An oversized file is refused before anything is stored

- **GIVEN** a portal task with a configured maximum file size
- **WHEN** the matched party submits a larger file
- **THEN** the completion MUST be refused naming the limit
- **AND** no file MUST be stored on the case
- @e2e exclude covered by completion-validation unit tests

### Requirement: Only the matched party completes, fail-closed

Completion of an external task SHALL be authorized by comparing the acting
portal subject to the task's STORED party reference. Any other caller
SHALL be denied: another portal subject, any authenticated Nextcloud user
including administrators acting through the portal seam, and any caller
whose subject cannot be resolved. When the comparison cannot be evaluated,
the answer SHALL be denial, never a skipped check.

`POST /api/flow-runs/{uuid}/resume` SHALL NOT be able to complete a portal
task, for the same reason it cannot complete a user task: it authorizes
running the flow, not answering for a performer.

A caseworker who needs the ask withdrawn SHALL cancel or re-ask through
the flow; there SHALL be no completion-on-behalf path for external tasks.

#### Scenario: Another subject who knows the task cannot answer it

- **GIVEN** an open portal task matched to subject A
- **WHEN** authenticated portal subject B attempts to complete it
- **THEN** the completion MUST be denied
- **AND** the task state MUST be unchanged
- @e2e another portal subject cannot complete a task that is not theirs

#### Scenario: The resume endpoint cannot answer for the resident

- **GIVEN** a run suspended on a portal-task node, and a Nextcloud user who
  may run the flow
- **WHEN** that user posts a decision to the run's resume endpoint
- **THEN** the task MUST remain non-terminal
- **AND** the run MUST remain suspended
- @e2e exclude same contract as flow-user-task-node, covered by its e2e plus
  PortalTaskNode unit tests

#### Scenario: An unresolvable subject is denied

- **GIVEN** an open portal task and a completion whose acting subject cannot
  be resolved
- **WHEN** completion is attempted
- **THEN** it MUST be denied with a reason
- **AND** the denial MUST be recorded in the task audit
- @e2e exclude covered by completion-authorization unit tests

### Requirement: A re-ask creates a new task carrying a mandatory reason

When the flow routes back into a portal-task node whose slot task is
TERMINAL, the node SHALL create a NEW external task: matched afresh from
the case, carrying a re-ask reason, recording the cycle number and the
previous task's uuid, and delivered like a first ask.

The reason SHALL be read from a configured item field and SHALL be
MANDATORY on re-entry: a re-entering firing with no reason SHALL fail the
firing rather than ask the party the same thing with no explanation.

The previous task and its audit SHALL remain untouched. The cycle count
SHALL be queryable, so "how often has this party been asked" is a read,
not a reconstruction.

#### Scenario: A rejected submission goes back with the reason

- **GIVEN** a flow where a caseworker review step routes its rejection edge
  back into the portal-task node, and a completed first ask
- **WHEN** the reviewer rejects with a reason and the node fires again
- **THEN** a second task MUST exist carrying the reason, cycle number 2 and
  the first task's uuid
- **AND** the first task MUST be unchanged
- @e2e a rejected submission returns to the resident with the reason

#### Scenario: A re-ask without a reason is refused

- **GIVEN** a portal-task node re-entered after its task went terminal, with
  no reason on the configured item field
- **WHEN** the node fires
- **THEN** the firing MUST fail naming the missing reason
- **AND** no new task MUST be created
- @e2e exclude covered by PortalTaskNode re-entry unit tests

### Requirement: The overdue path is consumed from flow-business-timers, never rebuilt

The node SHALL pass `due_at` and `expires_at` references through to the
task and SHALL implement no sweep, no cadence and no business-day
arithmetic of its own.

The reminder and escalation contract SHALL be expressed as
`flow-business-timers` escalation rungs: a `preBreach` rung addressed to
the PARTY is delivered through the same portal delivery seam as the ask,
and a `slaBreached` rung addressed to the caseworker role escalates
inside the organisation. Expiry enforcement transitioning the task is
that capability's rule; this node only guarantees that an
expiry-terminated task continues the run distinguishably (see the
suspension requirement).

#### Scenario: A reminder reaches the party without this node owning a clock

- **GIVEN** a portal task with a due date and a preBreach reminder rung
- **WHEN** the rung fires
- **THEN** the reminder MUST be delivered through the portal delivery seam
  to the matched party
- **AND** no timer logic MUST exist in the portal-task node
- @e2e exclude timer firing is flow-business-timers' surface; the seam is
  covered by delivery-seam unit tests

#### Scenario: A breach escalates inward, not to the party

- **GIVEN** a portal task with a slaBreached escalation rung naming the
  caseworker role
- **WHEN** the rung fires
- **THEN** the escalation MUST be addressed to the caseworker role
- **AND** the party MUST NOT receive it
- @e2e exclude covered by flow-business-timers rung-addressing unit tests

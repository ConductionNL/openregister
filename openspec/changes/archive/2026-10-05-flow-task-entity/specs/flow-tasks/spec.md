## Purpose

One fleet-generic human-or-agent task: a durable record of work owed by a
performer, its authorized lifecycle, its append-only audit, and the inbox
query that answers "what is waiting for me?". A task may be attached to a
flow run, but a standalone task is equally first-class.

## ADDED Requirements

### Requirement: A task is a first-class record, not a flow artefact

The system SHALL persist a task as a native record with its own identity,
independent of any flow run.

`run_uuid` and `node_id` SHALL be OPTIONAL and SHALL carry provenance only.
A task created with neither SHALL be valid, listable, claimable,
completable and auditable by every rule in this capability — no code path
may treat "no run" as a degraded or unsupported case.

A task SHALL be addressable by uuid. `title` SHALL be nullable: of the 23
fleet task shapes inventoried on 2026-08-22, four (`approval_request`,
`ApprovalStep`, `parafeeractie`, `handhavingsactie`) carry no title at all.
When `title` is null the system SHALL synthesize a display title from the
task's action and its subject object, and SHALL NOT persist the synthesized
value — a synthesized title that is stored becomes stale the moment the
subject is renamed.

A task SHALL carry an unstructured `metadata` map for fields a migrating
app needs to preserve and this capability does not model. `metadata` SHALL
NOT be readable by any lifecycle, authorization or inbox rule: it is
carried, not interpreted.

#### Scenario: A task with no run behaves identically to one with a run

- **GIVEN** two otherwise identical tasks, one carrying a `run_uuid` and one
  with `run_uuid` null
- **WHEN** each is offered, claimed, and completed by the same performer
- **THEN** both MUST succeed with the same lifecycle states and the same
  audit entries
- **AND** neither MUST require a flow definition to exist
- @e2e exclude covered by TaskService unit tests over both shapes

#### Scenario: A titleless task still displays

- **GIVEN** a task with `title` null anchored to a subject object
- **WHEN** it is returned from the inbox
- **THEN** the response MUST carry a non-empty display title derived from
  the task action and the subject
- **AND** the stored `title` MUST still be null
- @e2e exclude covered by unit tests on title synthesis

### Requirement: One lifecycle, with every legacy value mapped onto it

A task's state SHALL be one of the CMMN plan-item states `available`,
`enabled`, `active`, `completed`, `terminated`, `disabled` (ADR-098 D4).
No other value SHALL be persistable.

The system SHALL publish a mapping from the legacy vocabularies in use
across the fleet onto these six, and every migration and every API that
accepts a legacy value SHALL resolve it through that one mapping. The
mapping SHALL cover at minimum: `open`, `pending`, `todo`, `blocked`,
`in_progress`, `in-progress`, `in-execution`, `done`, `resolved`,
`approved`, `rejected`, `waived`, `skipped`, `cancelled`, `expired`,
`error`, `dead_letter`, `reopen`. Values that collapse onto one state
(`done`/`completed`; `cancelled`/`terminated`; the four spellings of
in-progress) SHALL resolve to the SAME state — the distinction they carried
SHALL survive on `outcome`, never on state.

The system SHALL materialise a boolean `is_terminal` alongside the state so
that "is anything still open on this object?" is one indexed predicate and
not a set-membership test that each caller re-derives.

Every state change SHALL be effected by a NAMED transition action (for
example `claim`, `complete`, `reject`, `cancel`). The action name SHALL be
recorded, because downstream notification rules (ADR-031
`transition(action)` triggers) address the action, not the resulting state.

An unrecognised legacy value SHALL be REJECTED with an error naming the
value. It SHALL NOT be silently coerced to a default state.

#### Scenario: Two legacy spellings converge without losing the distinction

- **GIVEN** one task imported with a legacy status `done` and one with
  `approved`
- **WHEN** both are read back
- **THEN** both MUST report state `completed` with `is_terminal` true
- **AND** their `outcome` values MUST still differ
- @e2e exclude covered by the legacy-mapping unit test table

#### Scenario: An unmapped status is refused, not defaulted

- **GIVEN** an import carrying the status `'open'` against a vocabulary that
  does not define it — the live defect at
  `procest/lib/Service/Transitions/CreateTaskHandler.php:76`
- **WHEN** the task is written
- **THEN** the write MUST fail with an error naming the unmapped value
- **AND** no task record MUST be created
- @e2e exclude covered by TaskService validation unit tests

### Requirement: Overdue is derived and MUST NOT be stored

The system SHALL NOT provide any column, state value, or persisted field
that records whether a task is overdue.

Overdue SHALL be computed at read time by comparing `due_at` (and, where
set, `expires_at`) against the current time. The same computation SHALL
back the inbox filter, the API projection, and any notification recipient
query — one derivation, no second opinion.

The reason is measured: three fleet schemas store `overdue` as a status
value, and decidesk's `actionOverdue` notification filters
`taskStatus: 'overdue'`, so it fires only when something remembered to
write that value. A clock-derived fact maintained by hand is a fact that is
wrong between writes.

#### Scenario: A task becomes overdue with no write

- **GIVEN** a task with `due_at` in the future and no writes performed on it
- **WHEN** the clock passes `due_at`
- **THEN** reading the task MUST report it as overdue
- **AND** the inbox overdue filter MUST return it
- **AND** the task's stored row MUST be byte-identical to before
- @e2e exclude covered by a clock-controlled unit test

### Requirement: due_at advises, expires_at enforces

`due_at` and `expires_at` SHALL be separate columns with separate meanings.

`due_at` is ADVISORY: passing it changes what the task is reported as and
what may be notified about it, and SHALL NOT change its state.

`expires_at` is ENFORCING: passing it makes the task eligible for automatic
transition to a terminal state. Of the 23 inventoried shapes, only
openconnector's `approval_request` carries enforcing expiry; conflating the
two would silently arm auto-termination on every advisory deadline in the
fleet.

Setting `expires_at` earlier than `due_at` SHALL be rejected: a task that
dies before it is due is a configuration error, not a schedule.

The ACT of enforcing expiry — the sweep, the business-day arithmetic, the
escalation matrix — is NOT part of this capability and is specified by
`flow-business-timers`. This capability specifies only that the two columns
exist, mean different things, and that nothing in it auto-transitions a
task on `due_at`.

#### Scenario: A due date passing does not change state

- **GIVEN** an `active` task whose `due_at` has passed and whose
  `expires_at` is null
- **WHEN** it is read
- **THEN** its state MUST still be `active`
- **AND** it MUST be reported overdue
- @e2e exclude covered by a clock-controlled unit test

#### Scenario: expires_at before due_at is refused

- **GIVEN** a task write with `expires_at` earlier than `due_at`
- **WHEN** it is submitted
- **THEN** it MUST be rejected with an error naming both values
- @e2e exclude covered by TaskService validation unit tests

### Requirement: The performer model spans people, groups, agents and workers

Every task SHALL carry a `performer_type` of `user`, `group`, `agent` or
`worker` (ADR-098 D3) alongside the performer reference. An AI agent or an
external worker SHALL claim and complete a task through the SAME verbs and
the SAME authorization as a person; there SHALL be no agent-only or
worker-only completion path.

The model SHALL express, without app-specific columns:
a Nextcloud uid; a uid together with a group (only pipelinq separates
`assigneeUserId` from `assigneeGroupId`); a group-only CANDIDATE POOL with
no assignee yet (openconnector `approverGroup`); and a ROLE name resolved
to people at authorization time — `lib/Db/ApprovalStep.php:81-87` stores a
role, not a uid, and today the word "assignee" means a uid, a
non-binding recorded string, and a role name in three different fleet apps.

Assignment from a candidate pool SHALL support the routing strategies
`single-role`, `or-set`, `hierarchical`, `round-robin` and `least-loaded`,
plus a `fallback` performer used when a strategy resolves to nobody. A
strategy that resolves to nobody and has no fallback SHALL leave the task
unassigned in the pool and SHALL NOT assign it to the requester, the
system, or an arbitrary member.

Delegation SHALL be first-class: a delegate acts with `on_behalf_of`
naming the original performer and a `mandate` recording the authority
relied on. The audit SHALL show both the acting identity and the
on-behalf-of identity; a delegated completion SHALL NOT be recorded as the
original performer acting.

The task SHALL additionally carry a `requester` distinct from the
performer, and a `watchers` list that confers read visibility and no
lifecycle rights whatsoever.

#### Scenario: A group task has no assignee until someone claims it

- **GIVEN** a task with `performer_type` `group` and a candidate group
- **WHEN** it is created
- **THEN** its assignee MUST be empty
- **AND** it MUST appear in the unclaimed inbox of every group member
- @e2e exclude covered by TaskInboxService unit tests

#### Scenario: An agent completes a task exactly as a person does

- **GIVEN** a task with `performer_type` `agent` assigned to a registered
  agent identity
- **WHEN** the agent completes it
- **THEN** the completion MUST pass the same authorization checks as a user
  completion
- **AND** the audit entry MUST record performer type `agent`
- @e2e exclude covered by TaskService unit tests with an agent identity

#### Scenario: A delegate's action names both identities

- **GIVEN** a task assigned to one user and delegated to another with a
  recorded mandate
- **WHEN** the delegate completes it
- **THEN** the audit MUST record the delegate as actor and the original
  performer as on-behalf-of
- **AND** the mandate MUST be recorded on the audit entry
- @e2e exclude covered by delegation unit tests

#### Scenario: A routing strategy that finds nobody assigns nobody

- **GIVEN** a `least-loaded` strategy over a candidate group whose members
  have all been filtered out, with no fallback configured
- **WHEN** assignment runs
- **THEN** the task MUST remain unassigned in the pool
- **AND** no implicit assignment to requester or system identity MUST occur
- @e2e exclude covered by routing-strategy unit tests

### Requirement: Every lifecycle verb is authorized fail-closed

The system SHALL expose the verbs `create`, `offer`, `claim`, `unclaim`,
`assign`, `reassign`, `delegate`, `resolve`, `complete` and `cancel`.

Each verb SHALL evaluate authorization BEFORE any mutation, and SHALL DENY
when the answer cannot be determined — an unresolvable role, an
unavailable group backend, or an unknown performer type SHALL produce a
denial, never a skipped check. No verb SHALL be reachable by an
authenticated caller merely because they know the task's uuid. This closes
the hole measured at `lib/Controller/FlowRunController.php:423-436`, where
`resume` checks only that the flow is runnable and never that the caller is
the person being waited on.

At minimum: `claim` SHALL require membership of the candidate pool;
`complete` and `resolve` SHALL require being the assignee, an authorized
delegate of the assignee, or an administrator; `reassign` and `cancel`
SHALL require the requester, an authorized supervisor, or an
administrator; `unclaim` SHALL require being the current assignee.

`claim` SHALL be atomic: concurrent claims on one unassigned task SHALL
result in exactly one assignee, and the losing caller SHALL receive a
conflict, not a silent overwrite.

A verb applied to a task already in a terminal state SHALL be refused with
a conflict naming the current state.

Where a task's `outcome` is a rejection or a return, a non-empty `comment`
SHALL be MANDATORY and the verb SHALL be refused without one — openconnector
and procest's `parafeeractie` both require this today and both enforce it
in their own app code.

#### Scenario: A stranger cannot complete someone else's task

- **GIVEN** a task assigned to one user
- **WHEN** a different authenticated user who knows its uuid calls complete
- **THEN** the call MUST be denied
- **AND** the task state and assignee MUST be unchanged
- @e2e a stranger is refused on the task detail route

#### Scenario: Two claims race and one loses

- **GIVEN** an unassigned task in a candidate pool with two members
- **WHEN** both claim it concurrently
- **THEN** exactly one MUST become the assignee
- **AND** the other MUST receive a conflict response
- @e2e exclude covered by a concurrency unit test against the mapper

#### Scenario: An unresolvable role denies rather than passes

- **GIVEN** a task whose performer is a role name that the role resolver
  cannot resolve
- **WHEN** any user attempts to complete it
- **THEN** the call MUST be denied with a reason naming the unresolvable
  role
- **AND** the failure MUST NOT be reported as success or as "no check
  applicable"
- @e2e exclude covered by TaskAuthorizationService unit tests

#### Scenario: A rejection without a comment is refused

- **GIVEN** a task being completed with a rejecting outcome and an empty
  comment
- **WHEN** complete is called
- **THEN** it MUST be refused
- **AND** the task MUST remain in its pre-call state
- @e2e exclude covered by TaskService validation unit tests

### Requirement: A task that has become moot is terminated, not orphaned

When a flow run reaches a terminal status, every non-terminal task carrying
that `run_uuid` SHALL be transitioned to `terminated` with a reason naming
the run and its terminal status.

When a branch decision makes a pending task unreachable — a competing
branch resolved a choice, or a stage the task belonged to closed — the task
SHALL likewise be terminated with a reason.

Termination by propagation SHALL be recorded in the audit with the
propagation source as actor. It SHALL NOT be silently deleted and SHALL NOT
remain visible in any inbox as actionable work.

A task with no `run_uuid` SHALL NEVER be terminated by propagation: nothing
about it is derived from a run.

#### Scenario: Killing a run empties its inboxes

- **GIVEN** a run with three tasks pending across two assignees
- **WHEN** the run is stopped
- **THEN** all three tasks MUST become `terminated` with a reason naming the
  run
- **AND** neither assignee's inbox MUST list them as actionable
- @e2e exclude covered by cancellation-propagation unit tests

#### Scenario: A standalone task survives everything

- **GIVEN** a task with `run_uuid` null
- **WHEN** unrelated runs terminate
- **THEN** the task MUST remain in its current state
- @e2e exclude covered by cancellation-propagation unit tests

### Requirement: The inbox answers "what is waiting for me?" in one query

The system SHALL expose an inbox query supporting at minimum: tasks
assigned to the calling user; unclaimed tasks in the calling user's
candidate pools; tasks the caller watches; and tasks anchored to a given
object.

Each returned row SHALL carry the subject object's identifying context
(register, schema, uuid and its display title) alongside the task, so a
list is readable without a second request per row. This is the capability
`AwaitSignalNode` provably lacks: its answer lives in
`$run->getContext()['signal']` (`lib/Service/Flow/FlowRunService.php:521-537`),
which is not listable, not countable and not poolable.

The query SHALL support filtering by state, by `is_terminal`, by derived
overdue, by priority, and by anchor; and SHALL support sorting by `due_at`,
priority and creation time. It SHALL be paginated, and its result SHALL
carry a total so a badge count does not require fetching every row.

Filtering and pagination SHALL be performed in the datastore. A
client-side filter applied over a server-paginated result SHALL NOT be
used, because it silently drops rows that the current page did not contain.

The inbox SHALL return only tasks the caller may see: assignee, candidate
pool member, requester, watcher, or administrator. Visibility SHALL be
enforced in the query, not by filtering a wider result afterwards.

#### Scenario: One request lists my work with case context

- **GIVEN** a user assigned four tasks anchored to four different objects
- **WHEN** they request their inbox
- **THEN** the response MUST contain four rows
- **AND** each row MUST carry its subject object's register, schema, uuid
  and display title
- @e2e the inbox route returns tasks with subject context

#### Scenario: A pooled task is visible to the pool and to nobody else

- **GIVEN** an unclaimed task in a candidate group
- **WHEN** a group member and a non-member each request their inbox
- **THEN** the member's response MUST include it
- **AND** the non-member's response MUST NOT include it, and MUST NOT
  reveal its existence through the total
- @e2e exclude covered by TaskInboxService authorization unit tests

#### Scenario: Filtering happens in the datastore

- **GIVEN** 120 tasks matching a filter, with a page size of 25
- **WHEN** the filtered inbox is requested
- **THEN** the first page MUST contain 25 matching rows
- **AND** the reported total MUST be 120
- @e2e exclude covered by mapper-level pagination tests

### Requirement: One generic anchor, plus typed relations

A task SHALL anchor to its subject through ONE generic reference:
`object_uuid` together with `register_id` and `schema_id`.

Additional related objects SHALL be recorded in a typed relation table
carrying the relation's ROLE (for example `subject`, `case`, `decision`,
`contract`, `evidence`). The 23 inventoried shapes anchor to at least
eighteen distinct entity kinds — case, client, ticket, project, column,
zaakUuid, decision, meeting, goal, phase, endpoint, rule, synchronization,
tenant, contract, clause, agendaItem, motion. Modelling those as columns
would produce a table that grows a column per consuming app; the relation
table absorbs the nineteenth without a migration.

The anchor SHALL be optional: a task about nothing in particular is valid.

#### Scenario: A new consuming entity kind needs no schema change

- **GIVEN** a task anchored to an object of a schema this capability has
  never seen
- **WHEN** it is created with a relation role of that schema's choosing
- **THEN** it MUST be stored and queryable by that role
- **AND** no column MUST have been added
- @e2e exclude covered by TaskRelationMapper unit tests

### Requirement: A templated task freezes its template at creation

Where a task is created from a template, the system SHALL record
`template_id`, `template_version`, and a `template_snapshot` holding the
template content as it stood at creation.

All lifecycle evaluation, checklist rendering and completion validation for
that task SHALL read the SNAPSHOT, never the live template. Editing a
template SHALL NOT change any task already created from it.

A task's checklist SHALL be a typed array of
`{id, label, description, checked}` entries. It SHALL NOT be a string
containing JSON — procest stores it that way today, which makes a checklist
unqueryable and its item state unaddressable.

#### Scenario: Editing a template leaves running tasks alone

- **GIVEN** a task created from a template with three checklist items
- **WHEN** the template is edited to have five
- **THEN** the existing task MUST still present three items
- **AND** its `template_version` MUST still name the version it was created
  from
- @e2e exclude covered by template-snapshot unit tests

#### Scenario: A checklist item is addressable

- **GIVEN** a task with a three-item checklist
- **WHEN** one item is checked by its id
- **THEN** only that item's `checked` MUST change
- **AND** the change MUST appear in the task audit
- @e2e exclude covered by checklist unit tests

### Requirement: The task audit is append-only and names the performer type

Every lifecycle verb that succeeds, and every authorization denial, SHALL
append an audit entry recording: the task, the transition action, the
resulting state, the ACTING identity, the PERFORMER TYPE
(`user|group|agent|worker`), any `on_behalf_of` and `mandate`, the
timestamp, and the reason or comment where one was supplied.

Audit entries SHALL NOT be updatable or deletable through any API. Deleting
a task SHALL NOT delete its audit entries.

An audit entry SHALL be written in the same transaction as the mutation it
records, so a completed task without its audit entry is not a reachable
state.

#### Scenario: A completion and its audit entry are inseparable

- **GIVEN** a task being completed where the audit write fails
- **WHEN** the transaction resolves
- **THEN** the task MUST NOT be recorded as completed
- @e2e exclude covered by a transactional unit test with an injected audit
  failure

#### Scenario: A denial is auditable

- **GIVEN** an unauthorized completion attempt
- **WHEN** it is denied
- **THEN** an audit entry MUST record the attempt, the acting identity and
  the denial reason
- @e2e exclude covered by TaskAuthorizationService unit tests

### Requirement: Priority is normalised to one scale on the way in

A task's priority SHALL be one of `low`, `normal`, `high`, `urgent`.

The system SHALL accept and normalise the fleet's other scales on write:
the three-value `low|normal|high`, the iCal integer range 0-9 used by the
CalDAV VTODO wire format, and the notification scale
`low|medium|high|critical`. Normalisation SHALL be a single published
mapping used by every caller.

A value outside every known scale SHALL be rejected naming the value, not
coerced. pipelinq's `task.priority` today declares the enum
`["low","normal","high"]` with `"default": "normaal"` — a default that is
not in its own enum; a coercing normaliser would have hidden that for as
long as it existed.

#### Scenario: An iCal integer arrives and lands on the scale

- **GIVEN** a task written with priority `1` from the VTODO wire format
- **WHEN** it is read back
- **THEN** its priority MUST be `urgent`
- @e2e exclude covered by the priority-normalisation unit test table

#### Scenario: An off-scale value is refused

- **GIVEN** a task written with priority `"normaal"`
- **WHEN** the write is submitted
- **THEN** it MUST be rejected with an error naming the value
- @e2e exclude covered by the priority-normalisation unit test table

## Purpose

Deliver the engine task onto Nextcloud's own surfaces — notifications a
person can decide from, a VTODO in their calendar that links back to the
task, and the inbox widgets that show what is waiting — as PROJECTIONS of
one truth, with exactly one authorizing path back from a user-editable
store into the engine.

## ADDED Requirements

### Requirement: Truth flows one way, and the one path back is a gate

The task record owned by `flow-tasks` SHALL be the only store of a task's
lifecycle state, assignee, deadlines and outcome. Every surface this
capability delivers — Nextcloud notifications, CalDAV VTODOs, the inbox
widget, the object task tab — SHALL be a PROJECTION: derived from that
record, rebuildable from it, and authoritative for nothing.

No projection SHALL be read as an input to any lifecycle decision. The
single exception is the write-back gate specified below, and it SHALL NOT
be an exception to the rule that the engine decides: what arrives through it
is a REQUEST to perform a named lifecycle verb, evaluated by the same
authorization as any other caller of that verb, and discarded when refused.

Every projection SHALL be reconstructible from the task record alone. No
field SHALL exist only in a projection: if a projected surface displays it,
the task record or a derivation from the task record SHALL be able to
produce it.

The reason is measured. `hermiq/src/manifest.json:1240` records a page that
"used to declare hermiq/agentflow — a duplicate mirror of the native flow
rows — so the list showed objects while the engine ran the native rows, free
to drift". A calendar is more writable than that mirror was, so the
one-directional contract is stated as a requirement rather than left as an
intention.

#### Scenario: A projection destroyed is a projection rebuilt

- **GIVEN** a task with a calendar projection, whose VTODO is deleted
  outright from the calendar
- **WHEN** the projection is next reconciled
- **THEN** the VTODO MUST be recreated with the same content it had
- **AND** the task record MUST be unchanged, including its state and audit
- @e2e exclude covered by projection-reconciliation unit tests

#### Scenario: An edit that is not a recognised verb does not reach the engine

- **GIVEN** a projected VTODO whose `SUMMARY` and `DESCRIPTION` are edited
  in a calendar client
- **WHEN** the write is processed
- **THEN** the task record's title and description MUST be unchanged
- **AND** the next projection render MUST restore the projected values
- @e2e exclude covered by write-back gate unit tests

### Requirement: Task lifecycle delivery is automatic and declarative

The system SHALL notify people about task lifecycle events WITHOUT a flow
author placing a node, a schema author writing a rule per app, or any code
path calling the notification API imperatively for a task.

Task notification rules SHALL be expressed in the canonical
`x-openregister-notifications` dialect (ADR-031) and SHALL be evaluated by
the same dispatcher that evaluates schema-declared and system-entity rules.
A second notification pipeline for tasks SHALL NOT exist.

Rules SHALL address the NAMED TRANSITION ACTION recorded by `flow-tasks`,
not the resulting state, so that two actions landing on the same state
(a completion by approval and a completion by rejection) are separately
addressable.

At minimum the following SHALL be delivered: a task offered to a candidate
pool, a task assigned to a person, a task reassigned away from a person, a
task approaching its deadline, a task escalated, and a task terminated by
propagation.

Recipients SHALL be resolved from the task's performer model. A task offered
to a candidate pool SHALL notify the pool's members and SHALL NOT notify
anyone outside it. A task with no assignee SHALL NOT produce an
assignee-addressed notification.

#### Scenario: Assignment reaches the assignee and nobody else

- **GIVEN** a task assigned to one user, with a second user watching it and a
  third unrelated to it
- **WHEN** the assign verb succeeds
- **THEN** the assignee MUST receive the assignment notification
- **AND** the unrelated user MUST receive nothing
- @e2e exclude covered by TaskNotificationRules dispatch unit tests

#### Scenario: Offering to a pool notifies the pool

- **GIVEN** a task offered to a candidate group of four members
- **WHEN** the offer verb succeeds
- **THEN** all four members MUST receive the offer notification
- **AND** no notification MUST name an assignee, because there is none
- @e2e exclude covered by TaskNotificationRules dispatch unit tests

#### Scenario: Two outcomes on one state are separately addressable

- **GIVEN** two rules, one on the `complete` action and one on the `reject`
  action, both of which leave the task in the same terminal state
- **WHEN** each action is performed on a different task
- **THEN** each task MUST trigger only its own rule
- @e2e exclude covered by transition-action matching unit tests

### Requirement: A binary decision is decidable from the notification

Where a task's completion is a binary choice, the notification SHALL carry
the choice as ACTIONS the recipient can invoke directly. It SHALL NOT
require the recipient to navigate to a page to express a decision the
notification already stated.

An action SHALL be able to name a task lifecycle verb and its outcome, and
SHALL be delivered as a state-changing request, not as a navigation link.
The existing navigation action kinds SHALL keep working unchanged.

A decision action SHALL be authorized by exactly the same rules as the same
verb invoked from any other surface. Possessing the notification SHALL NOT
confer any right the recipient does not otherwise have.

Where the verb requires a mandatory comment — `flow-tasks` requires one on a
rejecting or returning outcome — a notification action for that verb SHALL
NOT complete the task directly. It SHALL open the surface that can collect
the comment. Silently completing without the mandated comment, or failing
after the user believes they answered, are both forbidden.

At most two decision actions SHALL be offered on one notification.

#### Scenario: Approving from the notification completes the task

- **GIVEN** a task assigned to a user whose completion is a binary approve or
  reject, delivered as a notification with both actions
- **WHEN** the assignee invokes the approve action
- **THEN** the task MUST be completed with the approving outcome
- **AND** the task audit MUST record the acting identity as the assignee
- @e2e an assignee approves a task from the notification

#### Scenario: A decision action confers no authority

- **GIVEN** a notification delivered to a user who is subsequently removed
  from the task's candidate pool
- **WHEN** that user invokes the decision action
- **THEN** the call MUST be denied
- **AND** the task state MUST be unchanged
- **AND** the denial MUST be recorded in the task audit
- @e2e exclude covered by notification-action authorization unit tests

#### Scenario: A rejection collects its mandatory comment

- **GIVEN** a notification offering approve and reject on a task whose
  rejecting outcome requires a comment
- **WHEN** the recipient invokes reject
- **THEN** the task MUST NOT be completed by that invocation alone
- **AND** the recipient MUST be taken to the surface that collects the
  comment
- @e2e exclude covered by notification-action routing unit tests

### Requirement: A notification for an answered task is withdrawn

When a task reaches a terminal state, or when its assignee changes, every
outstanding notification about it SHALL be withdrawn from the recipients for
whom it is no longer actionable.

A decision action invoked against a task that has already been answered
SHALL be refused with a conflict naming the current state, and SHALL NOT
overwrite the recorded outcome.

Withdrawal SHALL apply to a task terminated by propagation as well as to one
a person completed: a task cancelled because its run stopped MUST NOT leave
approve and reject buttons standing in anyone's notification list.

#### Scenario: Claiming a pooled task clears the other members' notifications

- **GIVEN** a task offered to four pool members, each notified
- **WHEN** one member claims it
- **THEN** the other three members' notifications MUST be withdrawn
- **AND** the claiming member MUST retain an actionable notification
- @e2e exclude covered by notification-withdrawal unit tests

#### Scenario: A stale decision button loses to the recorded outcome

- **GIVEN** a task already completed with an approving outcome, and a
  notification whose reject action was rendered before that
- **WHEN** the reject action is invoked
- **THEN** it MUST be refused with a conflict naming the terminal state
- **AND** the recorded outcome MUST still be the approving one
- @e2e exclude covered by notification-action conflict unit tests

### Requirement: Deadline notifications filter on the derived predicate

Any rule that notifies about a task being due, nearly due, or overdue SHALL
evaluate a predicate DERIVED from the task's deadline columns against the
current time. No rule SHALL filter on a stored field that records whether a
task is overdue, because `flow-tasks` forbids such a field from existing.

The derivation backing a deadline notification SHALL be the same derivation
that backs the inbox filter and the API projection. Two implementations of
"is this overdue" SHALL NOT exist.

The measured counter-example is decidesk's `actionOverdue`
(`decidesk/lib/Settings/decidesk_register.json:4173-4180`), whose filter is
`taskStatus: "overdue"` — a clock-derived value written by hand, so the
notification reaches only the tasks something remembered to stamp.

#### Scenario: A task becomes notifiable without anything writing to it

- **GIVEN** a task whose deadline is in the future and on which no write has
  occurred
- **WHEN** the clock passes the deadline and the scheduled evaluation runs
- **THEN** the overdue notification MUST be produced
- **AND** the task's stored row MUST be unchanged by the evaluation
- @e2e exclude covered by clock-controlled scheduled-rule unit tests

#### Scenario: A terminal task is not chased

- **GIVEN** a task past its deadline that has already reached a terminal
  state
- **WHEN** the scheduled evaluation runs
- **THEN** no overdue notification MUST be produced for it
- @e2e exclude covered by clock-controlled scheduled-rule unit tests

### Requirement: An assigned task appears in the assignee's own calendar

When a task has a resolved individual assignee, the system SHALL project it
as a VTODO into that person's calendar.

The projected VTODO SHALL carry: a summary derived from the task's display
title; a due date derived from the task's advisory deadline where one is
set; the task's priority mapped onto the VTODO priority range; a status
mapped from the task's lifecycle state through one published mapping; the
TASK's identity as a property, so any later write is addressable back to the
task; and a `URL` property that deep-links to the task's own form.

The deep link SHALL address a surface a person can act on. A link to an API
endpoint SHALL NOT satisfy this requirement.

A task with no resolved individual assignee — an unclaimed task in a
candidate pool — SHALL NOT be projected into any calendar. A VTODO lives in
exactly one person's calendar and there is no such person yet; projecting it
into an arbitrary member's calendar would assert an assignment that the
engine has not made.

When a task's assignee changes, the projection SHALL be removed from the
previous assignee's calendar and created in the new assignee's calendar.
When a task reaches a terminal state, the projection SHALL be updated to
reflect it and SHALL NOT remain as outstanding work.

#### Scenario: A task lands in the assignee's calendar with a working link

- **GIVEN** a task assigned to a user, with a due date and a subject object
- **WHEN** the projection runs
- **THEN** a VTODO MUST exist in that user's calendar carrying the task's
  title, its due date and its priority
- **AND** the VTODO MUST carry a `URL` resolving to the task's form
- **AND** opening that URL MUST present the task
- @e2e an assigned task appears in the assignee's calendar and links back

#### Scenario: An unclaimed pooled task is in nobody's calendar

- **GIVEN** a task offered to a candidate group with no assignee
- **WHEN** the projection runs
- **THEN** no VTODO MUST be created in any group member's calendar
- **AND** the task MUST still be listed in every member's inbox
- @e2e exclude covered by projection unit tests

#### Scenario: Reassignment moves the calendar entry

- **GIVEN** a task projected into one user's calendar
- **WHEN** it is reassigned to another user
- **THEN** the first user's calendar MUST no longer contain the projection
- **AND** the second user's calendar MUST contain it
- @e2e exclude covered by projection unit tests

### Requirement: The projection carries a real assignee, not prose

The projected VTODO SHALL carry the assignee as a machine-readable identity
resolvable to a Nextcloud account.

The system SHALL NOT encode the assignee as text inside the description, and
SHALL NOT recover an assignee by parsing description text. Any surface that
writes or reads the assignee SHALL use the identity, not the prose.

This ends a measured defect: `lib/Service/TaskService.php:258-264` recovers
an assignee by taking `substr($description, strlen('Assigned to: '))`, and
`nextcloud-vue/src/components/CnObjectSidebar/CnTasksTab.vue:304` writes
that prefix followed by the user's DISPLAY NAME — so the value does not
round-trip to an account, two users sharing a display name are
indistinguishable, and any user editing the description silently changes who
the task appears to belong to.

#### Scenario: Two users with the same display name stay distinct

- **GIVEN** two accounts with identical display names, one of which is
  assigned a task
- **WHEN** the projection is read back and its assignee resolved
- **THEN** it MUST resolve to the assigned account
- **AND** MUST NOT resolve to the other account
- @e2e exclude covered by projection assignee unit tests

#### Scenario: Editing the description does not reassign anything

- **GIVEN** a projected VTODO whose description is edited to read
  "Assigned to: somebody else"
- **WHEN** the write is processed
- **THEN** the task's assignee MUST be unchanged
- @e2e exclude covered by write-back gate unit tests

### Requirement: Ticking off the VTODO completes the engine task, through authorization

When a projected VTODO is marked completed in a calendar or task client, the
system SHALL request the corresponding lifecycle verb on the engine task,
identified by the task identity the projection carries.

Everything arriving this way SHALL be treated as UNTRUSTED INPUT. The engine
SHALL re-validate it in full: that the identity names a real task, that the
task is not already terminal, that the requested transition is legal from
its current state, and that the acting user is authorized to perform it.

The verb SHALL be invoked through the same service and the same
authorization as any other caller. A write-back path SHALL NOT have a
completion route of its own, and SHALL NOT be able to set a state the
lifecycle would otherwise refuse.

A write to a VTODO carrying no task identity SHALL be ignored by this
capability entirely — it is an ordinary calendar task and not the engine's
business.

#### Scenario: Completing in the Tasks app completes the task

- **GIVEN** a task assigned to a user and projected into their calendar
- **WHEN** the user marks the VTODO completed in a task client
- **THEN** the engine task MUST reach its completed state
- **AND** the task audit MUST record the completion with that user as actor
- @e2e completing the projected VTODO completes the engine task

#### Scenario: A hand-made calendar task is not touched

- **GIVEN** a VTODO in the user's calendar carrying no task identity
- **WHEN** it is created, edited and completed
- **THEN** no engine task MUST be created, changed or completed
- @e2e exclude covered by write-back gate unit tests

#### Scenario: An illegal transition through the calendar is refused

- **GIVEN** a projected VTODO for a task already in a terminal state
- **WHEN** it is edited back to an incomplete status
- **THEN** the engine task MUST remain terminal
- @e2e exclude covered by write-back gate unit tests

### Requirement: An unauthorized write-back is undone and explained

When a write-back is refused — by authorization, by validation, or because
the transition is illegal — the system SHALL NOT leave the projection
showing a state the engine did not accept.

Where the refusal can be delivered in-band to the client performing the
write, the write SHALL be refused so the client never records it. Where the
write has already been committed, the projection SHALL be REVERTED to the
engine's state.

In both cases the acting user SHALL be notified, and the notification SHALL
say which task was affected and why the change did not take. A silent revert
is forbidden: the user believes they completed something, and a calendar
entry that quietly reappears reads as a bug rather than as a refusal.

Every refused write-back SHALL be recorded in the task audit as a denial
with the acting identity and the reason, exactly as a refused API call is.

#### Scenario: A stranger's tick is undone and the stranger told

- **GIVEN** a projected VTODO reachable by a user who is not authorized to
  complete the underlying task, for example through a shared calendar
- **WHEN** that user marks it completed
- **THEN** the engine task MUST NOT be completed
- **AND** the VTODO MUST end up showing the engine's state, not the user's
  edit
- **AND** that user MUST receive a notification naming the task and the
  reason
- @e2e an unauthorized calendar completion is reverted and reported

#### Scenario: A refused write-back is auditable

- **GIVEN** any refused write-back
- **WHEN** the refusal is processed
- **THEN** a task audit entry MUST record the attempt, the acting identity
  and the denial reason
- @e2e exclude covered by write-back gate unit tests

### Requirement: Projection is idempotent and does not feed itself

Rendering a projection SHALL be idempotent: rendering the same task twice
without an intervening change SHALL leave the projected surfaces in the same
state and SHALL NOT produce duplicate calendar entries or duplicate
notifications.

A projection write SHALL NOT be observed by the write-back gate as a user
edit. A write-back that succeeds and causes the projection to be re-rendered
SHALL NOT cause a further write-back.

#### Scenario: Re-running the projection changes nothing

- **GIVEN** a task whose projections are current
- **WHEN** the projection is rendered again
- **THEN** the calendar MUST contain exactly one VTODO for that task
- **AND** no additional notification MUST be delivered
- @e2e exclude covered by projection idempotency unit tests

#### Scenario: A completion through the calendar does not echo

- **GIVEN** a projected VTODO completed by its assignee
- **WHEN** the engine completes the task and re-renders the projection
- **THEN** exactly one completion MUST be recorded in the task audit
- @e2e exclude covered by write-back loop unit tests

### Requirement: A delivery failure never fails the task

A failure to deliver any projection — an unreachable calendar backend, a
user with no calendar that accepts tasks, a notification backend error —
SHALL NOT fail, roll back, or block the lifecycle transition that caused it.

The transition SHALL commit, the audit SHALL be written, and the projection
SHALL be retried or reconciled afterwards. The failure SHALL be logged
naming the task and the surface that failed.

Delivery is how a person finds out about a task. It is not a condition of
the task existing, and making it one would let a calendar outage stop an
approval chain.

#### Scenario: A calendar outage does not stop an approval

- **GIVEN** a calendar backend that fails every write
- **WHEN** a task is assigned
- **THEN** the assignment MUST succeed and MUST be recorded in the audit
- **AND** the task MUST appear in the assignee's inbox
- **AND** the calendar failure MUST be logged naming the task
- @e2e exclude covered by projection failure-isolation unit tests

#### Scenario: A user with no task-capable calendar still gets tasks

- **GIVEN** an assignee whose account has no calendar accepting tasks
- **WHEN** a task is assigned to them
- **THEN** the assignment MUST succeed
- **AND** the task MUST be notified and listed in their inbox
- @e2e exclude covered by projection failure-isolation unit tests

### Requirement: The inbox surfaces read the inbox, and count from its total

The system SHALL provide a dashboard widget listing the calling user's open
tasks and a per-object surface listing the tasks anchored to an object. Both
SHALL read the task inbox query owned by `flow-tasks`.

Filtering, sorting and pagination SHALL be performed by that query. A
surface SHALL NOT apply a filter of its own over a page of results, because
a client-side filter over server-paginated data silently drops the rows the
current page did not contain and reports a count that is wrong.

Any badge or count SHALL be taken from the total the query reports, never
from the number of rows the surface happens to be holding.

Each listed row SHALL carry enough subject context to be readable without a
further request per row.

#### Scenario: A badge counts the work, not the page

- **GIVEN** a user with 120 open tasks and a widget page size of 25
- **WHEN** the widget renders
- **THEN** it MUST display 25 rows
- **AND** its count MUST read 120
- @e2e the task widget shows a page of rows and the full count

#### Scenario: A filter narrows the query, not the page

- **GIVEN** a user with tasks spanning several states, filtered to one state
- **WHEN** the filter is applied
- **THEN** the request MUST carry the filter to the inbox query
- **AND** the reported total MUST be the total matching the filter across all
  pages
- @e2e exclude covered by widget unit tests asserting the outgoing request

### Requirement: The task surfaces speak the task vocabulary

Every user-facing task surface SHALL express the lifecycle vocabulary that
`flow-tasks` defines, and SHALL NOT be limited to the four-value VTODO
status vocabulary it used previously.

A surface SHALL be able to display: a task in any of the lifecycle states; a
pooled task with no assignee, offered rather than assigned; a task shown as
overdue from the derived predicate rather than from a stored field; and the
lifecycle verbs the calling user is authorized to invoke, and only those.

A surface SHALL NOT offer a verb the calling user is not authorized to
perform. Offering it and letting the server refuse teaches users that the
interface is unreliable, and it leaks who else can act.

#### Scenario: A pooled task is shown as claimable, not as assigned

- **GIVEN** an unclaimed task in a candidate group the calling user belongs
  to
- **WHEN** it is listed
- **THEN** it MUST be shown with no assignee
- **AND** a claim action MUST be offered
- @e2e exclude covered by task surface unit tests

#### Scenario: A verb the user cannot perform is not offered

- **GIVEN** a task visible to a watcher who has no lifecycle rights on it
- **WHEN** the watcher views it
- **THEN** no lifecycle verb MUST be offered
- **AND** the task MUST still be readable
- @e2e a watcher sees the task and no action buttons

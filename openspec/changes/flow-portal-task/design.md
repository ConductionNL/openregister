# Design: flow-portal-task

## Context

See proposal.md for the motivation. What the approach stands on, measured:

- **The suspend/resume machinery is proven and per-node.**
  `FlowNodeResumeState` scopes state to one node
  (`lib/Service/Flow/FlowNodeResumeState.php:39-65`), `FlowSuspension` is
  thrown with a non-null heartbeat by both shipped waiters
  (`WaitNode.php:192`, `AwaitSignalNode.php:266`), and
  `flow-user-task-node` specifies continuation on TASK terminality rather
  than the run's signal slot. This node changes none of that; it changes
  who the performer is and how the ask travels.
- **The reaper defines the floor.** `findAbandonedSignals()` matches
  `resume_at IS NULL` (`lib/Db/FlowRunMapper.php:589-605`) and the worker
  fails matches at 14 days (`lib/BackgroundJob/FlowRunWorker.php:94`,
  `:311-349`). A hersteltermijn of six weeks parked on null would be
  FAILED mid-term. Non-null `resumeAt` is therefore a correctness rule
  here, not a style preference.
- **Portaliq's boundary is structural.** Contributions are descriptors,
  never data (ADR-046 rule 3); data reaches a visitor only through
  subject-scoped readers; actions forward server-to-server under a signed
  `X-Portal-Subject` assertion
  (`portaliq/lib/Contribution/IPortalContributionProvider.php`). The
  portal task rides these rails; it adds no new edge surface.
- **The case object already owns files.**
  `FileService::addFile()` writes into the object's own folder
  (`lib/Service/FileService.php:1480`). The 2026-08-31 decision makes that
  the ONLY landing place for a resident's upload.

## Goals / Non-Goals

**Goals:**

- One node that asks a party outside the instance and holds the run open
  until they answer, with the same graph semantics as
  `flow-user-task-node`.
- Matching, visibility and completion authorization that are fail-closed
  against the CASE's own data, so a portal subject can never see or answer
  work that is not theirs.
- An upload path whose result is a case-object file attachment, so the
  case sees its own evidence without a sync.
- A re-ask loop an author can draw in the graph, with the reason carried
  to the party.

**Non-Goals:**

- Rendering anything. The portal page, the upload form and their styling
  are portaliq's follow-up change.
- Notifying Nextcloud users. The caseworker's review step is an ordinary
  `openregister.user-task`; this node's delivery goes outward only.
- Timers. Reminder cadence, business-day arithmetic and expiry enforcement
  are `flow-business-timers`' rules; this node stores two timestamps and
  names two rungs.
- Identity brokering. DigiD, eHerkenning and eIDAS terminate at
  portaliq's edge (ADR-046, ADR-108); OpenRegister sees a resolved,
  server-derived subject and nothing upstream of it.

## Decisions

### D-1: A separate node, not an external mode on `openregister.user-task`

The one-line version: the performer resolution, the delivery channel and
the completion payload all differ, and a mode would make half of each
node's config keys invalid depending on the mode.

The longer version, in the order that decided it:

1. **The config vocabularies are disjoint.** `user-task` speaks candidate
   users, groups, roles, routing strategies and fallbacks. This node
   speaks a party role on the case, delivery addresses and upload
   constraints. Under a mode flag, `validateConfig()` becomes a two-branch
   validator where every key's validity depends on the flag, which is
   precisely the shape that ships a config the author believed was
   checked and the validator never read.
2. **The verb sets differ.** External tasks refuse `claim`, `unclaim` and
   `delegate` (specs/flow-tasks delta). A mode would put refusable verbs
   and poolable candidates one flag away from each other in the same node,
   and the flag is exactly what a copy-pasted flow definition gets wrong.
3. **The delivery seams must not meet.**
   `flow-task-inbox-projections` projects Nextcloud tasks into
   notifications and VTODO; the portal seam is subject-scoped and outward.
   Two nodes keep the seams apart by construction; a mode keeps them apart
   by an `if`.
4. **The palette is the author's contract.** `flow-user-task-node` D-9
   already pairs the waiters with one line each. This node extends the set
   to three: a signal is for a system that will call back; a user task is
   for a performer in the organisation; a portal task is for a party
   outside it.

The cost is a third waiting node in the palette and some shared mechanics.
The mechanics are shared by reusing `flow-user-task-node`'s bridge
(suspension, terminality read, outcome placement, cancellation
propagation), not by copying it; only performer resolution, delivery and
completion handling are node-specific.

### D-2: Declarative-vs-imperative (ADR-031)

Same verdict as `flow-user-task-node` D-1, inherited deliberately: the
node is imperative because a node is the imperative half of the platform,
and everything it points at stays declarative. The party role is data on
the case schema; the delivery message content is a portal notification
key, not PHP; the reminder and escalation are declarative timer rungs; the
upload constraints are validated config, not code. The fence carries over
verbatim: no branch in `PortalTaskNode` may be about what a specific app's
case MEANS.

### D-3: The match is frozen at creation

The node resolves the party role against the case object once, when the
task is created, and stores the resolved party reference on the task. It
is not re-resolved at completion.

A re-resolving match would let an edit to the case's initiator silently
transfer an open ask to a different person, with the audit showing neither
the transfer nor who authorized it. Frozen, the correction has one honest
path: cancel or re-ask, which creates a new task with a new match and a
new audit entry. The completion check compares the acting portal subject
to the STORED reference, fail-closed: no stored reference, no resolvable
subject, no match, all deny.

### D-4: The heartbeat is non-null, inherited as a rule

`flow-user-task-node` D-3 already argued this against the same two facts
(`findAbandonedSignals()` matching only null, the 14-day failure) and this
node's waits are LONGER: a hersteltermijn is measured in weeks. Same
default (15 minutes), same floor (5 minutes), same consequence stated
honestly: a run holding an open portal task is never reaped as abandoned,
and the thing that ends an unanswered ask is `expires_at` enforcement in
`flow-business-timers`.

### D-5: The upload is a case-object file attachment, and the completion references it

Files are stored through the file service onto the case object's own
folder before the task completion is recorded; the completion carries the
stored file references, not the bytes. Order matters: a completion that
records first and stores second can be interrupted into a completed task
whose evidence does not exist. Storing first degrades the other way, an
orphaned file on the right case, which is recoverable and visible.

Node config declares whether an upload is required, how many files are
accepted, and the accepted types and maximum size; violations refuse the
completion naming the constraint. Any dossier folder view of the file is a
projection of the attachment (decision 2026-08-31), one-directional per
ADR-098 Decision 2; nothing in this change writes a second file store.

### D-6: Re-ask is graph re-entry, and a reason is mandatory

The loop lives in the graph, where the author can see it: a caseworker
review step (an ordinary user task) routes its rejection edge back into
the portal-task node. On re-entry the node finds its slot task TERMINAL
and creates a new task; on a heartbeat wake it finds its slot task OPEN
and suspends again. Terminality of the slot task is what distinguishes a
re-ask from a duplicate, so idempotence and looping use one mechanism
instead of two flags.

The re-ask reason is read from a configured item field and is MANDATORY:
a firing that re-enters with no reason fails validation of the firing, not
silently. Asking a resident the same thing twice with no explanation is
the behaviour every complaint procedure exists to punish. The cycle count
and the previous task's uuid are recorded on the new task, so "asked
three times" is a query, not an archaeology.

### D-7: Delivery is a contract with portaliq, owned here as a seam only

This change specifies WHAT crosses the seam: a subject-scoped read that
lists a portal subject's open portal tasks with their case context, and a
delivery request (portal inbox message plus mail) recorded at creation and
at re-ask. It deliberately does not specify how portaliq renders either;
that is portaliq's follow-up change, and tasks.md carries exactly one
pointer line for it (one canonical home per spec).

Fail-open is refused on both sides of the seam: a task whose delivery
request cannot be recorded still exists and still suspends the run (the
ask outlives a mail outage), and the delivery state is queryable so the
caseworker sees "not yet delivered" instead of silence.

### D-8: The overdue path names timer rungs and owns nothing else

The node passes `due_at` and `expires_at` references to the task, and the
flow author attaches an escalation ladder from `flow-business-timers`: a
`preBreach` rung addressed to the party (delivered through the same portal
seam as the ask) is the reminder; a `slaBreached` rung addressed to the
caseworker role is the escalation; expiry enforcement transitions the task
terminally there. This node contains no sweep, no cadence and no
business-day arithmetic, and its spec asserts that absence.

## Risks / Trade-offs

- **The portaliq follow-up can lag, leaving tasks undeliverable.**
  → The delivery state is recorded and queryable, the task and suspension
  are correct without the portal, and the seam is specified here so the
  portaliq change implements against a contract instead of guessing.
- **Party matching is only as good as the case's party data.** A case
  whose initiator reference is stale asks the wrong person.
  → The match is frozen and audited (D-3), so the error is visible and
  recoverable through re-ask; and a case naming nobody fails the firing
  loudly instead of creating an unperformable task.
- **Long-lived heartbeats accumulate.** A six-week hersteltermijn at a
  15-minute heartbeat is ~4,000 no-op wakes.
  → The same trade `AwaitSignalNode` documents and the platform already
  pays; the interval is configurable per node.
- **A three-waiter palette can confuse authors.**
  → D-1 point 4: three one-line descriptions written as a set; and using
  the wrong node fails visibly (a user task for a resident has no
  performable candidate; a portal task for an employee never reaches an
  inbox).
- **Uploads are attack surface.** Type and size constraints are validated
  fail-closed (D-5), the file lands under the case object's ordinary OR
  permissions, and the portal edge's existing throttling (ADR-082) fronts
  the endpoint. Anti-virus scanning is the instance's existing NC file
  pipeline, not re-specified here.

## Migration Plan

Additive: one node registration, one performer-type extension on an
entity that is being implemented in parallel and has no shipped consumers
to migrate. Deploy order is the dependency chain:
`flow-task-entity` → `flow-user-task-node` → this change → portaliq's
contribution change. Rollback is removing the registration; open external
tasks are then terminated through the ordinary run-cancellation
propagation.

## Open Questions

- **Does one portal task ever address several parties?** A case with two
  applicants may owe both an ask. Provisionally: one task, one matched
  party; a multi-party ask is modelled in the graph (a fan-out over the
  party list), which `flow-parallel-streams` serves better than a
  multi-match here would.
- **Does the caseworker need a "complete on behalf of the resident at the
  desk" path?** Today: no; the desk scenario is the caseworker uploading
  to the case directly and cancelling the portal task with a reason. If
  practice demands more, it arrives as a delegation rule on the `external`
  performer type in `flow-tasks`, not as a bypass in this node.

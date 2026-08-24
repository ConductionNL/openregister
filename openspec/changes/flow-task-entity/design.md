# Design: flow-task-entity

## Context

See proposal.md — Why. The measured starting point:

- `AwaitSignalNode` is the engine's only human step. Its `assignee` config
  is free text and its own help string says it "does not by itself restrict
  who may answer" (`lib/Service/Flow/Nodes/AwaitSignalNode.php:200-203`).
- The answer lands in `$run->getContext()['signal']`
  (`lib/Service/Flow/FlowRunService.php:521-537`) — a JSON column on the
  run. There is no row per person, so no list, no count, no pool.
- `POST /api/flow-runs/{uuid}/resume` (`appinfo/routes.php:1312`) is
  `#[NoAdminRequired]` and its only guard is `refuseUnlessRunnable()` on the
  FLOW (`lib/Controller/FlowRunController.php:423-436`).
- The nearest existing task-shaped entity is `ApprovalStep`
  (`lib/Db/ApprovalStep.php`, 208L): uuid, chain_id, object_uuid,
  step_order, role, status, decided_by, comment, decided_at, created,
  requester_id. It has the right skeleton — a unit of owed work anchored to
  an object with a decision and an audit-relevant requester — and is
  missing everything that makes it fleet-generic.
- A DIFFERENT `TaskService` already exists at `lib/Service/TaskService.php`
  (753L): the CalDAV VTODO integration leaf serving nc-vue's `tasks` leaf.
  It is not touched here; the new service is namespaced
  `lib/Service/Task/` to keep the two apart by path, not by hope.

Constraint from the chain: `flow-definition-versioning` lands first. A task
that stores `node_id` is storing a pointer into a definition; without a
pinned version that pointer's meaning can change under a live task.

## Goals / Non-Goals

**Goals:**
- One durable task record that all 23 inventoried fleet shapes can migrate
  onto without a per-app column.
- A service where authorization is structurally unavoidable — not a check a
  caller may forget.
- An inbox query that is cheap enough to power a badge count on every page
  load.
- Storage decisions that survive the migration wave: adding the nineteenth
  consuming entity kind must not need a migration.

**Non-Goals (design-level, on top of the proposal's scope):**
- No performance work on the existing flow run tables. Tasks join to runs by
  `run_uuid`; the run tables are unchanged.
- No API for editing a task's `metadata` field-by-field. It is a carried
  blob, written whole.
- No admin UI. The inbox is an API in this change; the Vue surface arrives
  with `flow-task-inbox-projections`.
- No attempt to reconcile the fleet's SIX status enums inside the consuming
  apps. This change publishes the mapping; the apps adopt it in
  `flow-approval-consolidation`.

## Decisions

### D-1 — Declarative-vs-imperative decision (ADR-031)

**The task's LIFECYCLE and AUTHORIZATION are imperative by necessity; its
DELIVERY and its DERIVED FIELDS stay declarative. Both halves are justified
below, and the imperative half is deliberately fenced.**

ADR-031's default path is: when an `x-openregister-*` schema extension
expresses the requirement, declare it rather than write a service. Applying
that test field by field:

**Imperative — the entity, the lifecycle and the authorization.**
`x-openregister-lifecycle` operates on OR OBJECTS: it evaluates transitions
and guards over object state stored in the object store. A task is not an
object, and ADR-098 D2 rejected making it one. The reasons are measurable
rather than stylistic:

1. **Pooled-inbox queries.** "Every unclaimed task in any group I am in,
   with its subject's title, ordered by due date, page 1 of 5, plus a
   total" is a join with indexed predicates on assignee, candidate group,
   state and `due_at`. Over the object store these are JSON-extract
   predicates over a generic table — the same shape that makes
   `getAllUserTasks()` (`lib/Service/TaskService.php:120`) walk calendars.
   An inbox badge is rendered on every page; it has to be an index hit.
2. **Atomic claim.** `claim` must be a conditional update — assign IF still
   unassigned — so two clicks produce one assignee and one conflict. The
   object write path is a read-modify-write over a serialized document;
   last write wins is exactly the wrong semantics for a claim.
3. **Fail-closed authorization on a verb.** The check must run before the
   mutation and must DENY on "cannot determine". A schema guard evaluates
   over object data; it cannot express "resolve this role against the group
   backend and refuse if the backend is unavailable".

So: a native table + `TaskService`, in the same category ADR-031 preserves
for PHP (engine mechanics, external identity resolution, concurrency
control) — not a business rule that a schema could have carried.

**Declarative — everything that CAN be.** Nothing in this change writes a
notification. Delivery is `x-openregister-notifications` (ADR-031) on the
task projection, specified in `flow-task-inbox-projections`, addressing the
NAMED TRANSITION ACTIONS this change records (`transition(action)` triggers)
— which is exactly why the action name is stored alongside the resulting
state rather than being derivable from it. Escalation rules are declarative
too and belong to `flow-business-timers`.

**Derived, never stored.** `overdue`, `days_until_due` and `days_overdue`
are computed projections in the ADR-031 calculated-field spirit. The
counter-example is live: three fleet schemas store `overdue` as a status
value, and decidesk's `actionOverdue` notification filters
`taskStatus: 'overdue'`, so it only ever fires for tasks something
remembered to stamp. A stored clock-derived field is a field that is wrong
between writes. The same reasoning gives `is_terminal` the opposite
treatment — it is materialised because it is derived from the STATE, which
only changes on a write we control, not from the clock, which changes
constantly.

The imperative half is fenced by one rule: `TaskService` may not contain a
business rule about what a specific app's task means. Every branch in it
must be about lifecycle, identity or concurrency.

### D-2 — Native table, not OR objects, not VTODO-as-store

Three storage options were evaluated (ADR-098 D2 records the outcome; the
reasoning is restated here because it drives the whole schema):

1. **OR objects with a `task` schema.** Rejected. Every consuming app would
   get the pooled-inbox query cost of D-1(1), and — worse — a task's
   lifecycle would be writable through the generic object API, so "who may
   move this task" would be an object ACL question rather than a performer
   question. Half the fleet's shapes are OR objects today and that is
   precisely why none of them can enforce who completes them.
2. **VTODO as the store.** Rejected on four counts, each independently
   fatal: ICS blobs make pooled queries a parse-per-row; a VTODO lives in
   ONE calendar, so a candidate-group pool has no home; DAV objects are
   user-editable, so lifecycle state would be untrusted input; and DAV
   writes are whole-object PUTs, so a claim is racy by construction.
3. **A native table** (chosen). The projections we want from VTODO — a task
   appearing in the user's calendar — are a PROJECTION, built in
   `flow-task-inbox-projections` with an authorizing write-back listener.
   Projection is cheap; storage is not reversible.

`ApprovalStep` is the shape's ancestor, not its implementation: it is
NOT extended in place, because a chain step's `step_order`/`chain_id` are
chain semantics that a standalone task must not carry. `ApprovalStep`
retires in `flow-approval-consolidation`.

### D-3 — run_uuid/node_id are provenance, not identity

The single most consequential shape decision. If `run_uuid` were required,
every consuming app would need a flow to have a task, and the 23-shape
migration would become "rewrite 23 apps as flows first" — a programme that
never finishes. Making the columns nullable costs one index and buys the
migration order: shapes move onto the entity first, and onto flows later,
independently.

The rule this creates and the spec enforces: nothing derived from a run may
be load-bearing for a task with no run. Cancellation propagation is the
place this bites, so the spec states it explicitly — a task with
`run_uuid` null is never terminated by propagation.

### D-4 — Two deadline columns, one enforced

`due_at` advises, `expires_at` enforces. Merging them into one column plus a
boolean was considered and rejected: a boolean makes the DEFAULT the
dangerous case. Of 23 shapes exactly one (openconnector's
`approval_request`) auto-transitions on a deadline; with a merged column,
any migration that got the flag wrong would arm auto-termination on an
advisory date, and the failure mode is a silently cancelled approval. Two
columns make the enforcing case require an explicit act of writing to the
enforcing column.

`start_at`, the SLA triple `{value, unit}`, `compliance_period`,
suspendability and `recurrence` are STORED here (columns exist, values
round-trip) and INTERPRETED in `flow-business-timers`. Storing them here
avoids a second migration on the same table two changes later; not
interpreting them here keeps the clock logic in one place.

### D-5 — Performer as (type, ref, pool, strategy), not as columns per app

The union needs: uid; uid + group (pipelinq alone separates
`assigneeUserId` from `assigneeGroupId`); group-only pool
(openconnector `approverGroup`); role name (`lib/Db/ApprovalStep.php:81-87`
stores `role`); five routing strategies; and delegation. Modelled as:

- `performer_type` ∈ `user|group|agent|worker` (ADR-098 D3),
- `assignee` — the resolved current holder, empty while pooled,
- `candidate_users` / `candidate_groups` / `candidate_role` — the pool
  before resolution,
- `routing_strategy` ∈ `single-role|or-set|hierarchical|round-robin|
  least-loaded` + `routing_fallback`,
- `on_behalf_of` + `mandate` for delegation.

`agent` and `worker` are not a special path: an agent claims and completes
through the same verbs, which is what makes the Camunda external-task
generalisation in ADR-098 D3 real rather than aspirational. The audit
records the performer type so that "a human approved this" and "a model
approved this" are distinguishable after the fact — which is the whole
point of writing it down.

A strategy resolving to nobody leaves the task pooled. The tempting
fallbacks (assign to requester; assign to the first pool member; assign to
a system identity) each turn a routing misconfiguration into a silently
answerable task.

### D-6 — One anchor plus a typed relation table

`object_uuid` + `register_id` + `schema_id` is the anchor;
`openregister_task_relations` (task_id, role, object_uuid, register_id,
schema_id) carries everything else. The inventory found at least eighteen
distinct anchored entity kinds. Twenty FK columns would mean the table's
width is a function of how many apps have migrated, and every new consumer
would ship a migration. The relation table absorbs the nineteenth kind with
an INSERT.

The cost is one join for "tasks related to this contract". It is indexed on
(object_uuid, role) and it is not the inbox's hot path, which uses the
anchor columns directly.

### D-7 — Legacy values are mapped at the boundary and refused when unknown

One published mapping table from the fleet's six status vocabularies onto
the six CMMN states, applied by every writer. Two rules make it safe:

- Values that collapse (`done`/`completed`, `cancelled`/`terminated`, the
  four spellings of in-progress) land on the same state, and the
  distinction they carried moves to `outcome`. Nothing is lost, but nothing
  stays in the state column that does not belong there.
- An unrecognised value is REFUSED. A coercing default would have absorbed
  `procest/lib/Service/Transitions/CreateTaskHandler.php:76` writing
  `status:'open'` into an enum without `open`, and absorbed pipelinq's
  `task.priority` default `"normaal"` against the enum
  `["low","normal","high"]` — both of which are bugs that should surface
  during migration, loudly, once.

### D-8 — Cancellation propagation is a listener on run terminality

Tasks are terminated by a listener on the run reaching a terminal status
(`completed`, `stopped`, `dead_letter`, `failed` — `lib/Db/FlowRun.php`
STATUS constants), and by an explicit service call when a branch decision
makes a task moot. Deleting the task instead was rejected: the audit must
survive, and "why did this disappear from my inbox" must be answerable.

Termination is idempotent and skips already-terminal tasks, because run
terminality can be observed more than once (the stale-run reaper in
`lib/Cron/FlowRunWorker.php` runs on a 15-minute cadence and can race a
completing run).

### D-9 — The inbox filters and paginates in the datastore

Non-negotiable, and stated in the spec as a requirement rather than left to
implementation: a client-side filter over a server-paginated result drops
rows the current page did not contain, reports a wrong total, and looks
like success. Visibility is part of the WHERE clause for the same reason —
filtering a wider result afterwards makes the total leak the existence of
tasks the caller may not see.

## Data model

`openregister_tasks` — grouped by concern, all columns nullable unless
stated:

| Group | Columns |
|---|---|
| Identity | `id` (PK), `uuid` (NOT NULL, unique), `key` (external ref), `title`, `description`, `metadata` (JSON) |
| Provenance | `run_uuid`, `node_id`, `app_id`, `workflow_step_id`, `organisation` |
| Lifecycle | `state` (NOT NULL, CMMN six), `is_terminal` (NOT NULL bool), `last_action`, `outcome`, `blocked_reason` |
| Performer | `performer_type` (NOT NULL), `assignee`, `candidate_users` (JSON), `candidate_groups` (JSON), `candidate_role`, `routing_strategy`, `routing_fallback`, `on_behalf_of`, `mandate`, `requester`, `watchers` (JSON) |
| Timing | `start_at`, `due_at`, `expires_at`, `sla_value`, `sla_unit`, `compliance_period_days`, `suspended_until`, `recurrence` |
| Priority | `priority` (NOT NULL, `low|normal|high|urgent`) |
| Anchor | `object_uuid`, `register_id`, `schema_id` |
| Template | `template_id`, `template_version`, `template_snapshot` (JSON) |
| Checklist | `checklist` (JSON array of `{id,label,description,checked}`), `responses` (JSON append-only), `percent_complete` |
| Completion | `completed_at`, `completed_by`, `result_text`, `comment`, `evidence` (JSON file refs), `override_reason` |
| Hierarchy | `parent_task_id`, `epic_task_id` |
| Audit stamps | `created` (NOT NULL), `updated`, `created_by` |

No `overdue`. No `days_until_due`. No `days_overdue`. Two hierarchy columns
because planix genuinely has two (subtask parent and epic) and overloading
one `parent` is what makes its two hierarchies indistinguishable today.

Indexes: `(assignee, is_terminal, due_at)` for "my open work";
`(is_terminal, due_at)` for the overdue sweep; `(object_uuid)` for "tasks
on this object"; `(run_uuid)` for propagation; unique on `uuid`.

Candidate pools are stored twice on purpose: the `candidate_users` /
`candidate_groups` JSON columns are the readable record, and a companion
index table `openregister_task_candidates` (task_id, kind, ref, index on
`(kind, ref)`) is what the pooled inbox joins against — so "unclaimed tasks
in any group I am in" is an index hit rather than a JSON scan per row. The
drift risk this creates is named in Risks and is mitigated by one write
path maintaining both inside the transaction.

`openregister_task_relations`: `id`, `task_id`, `role`, `object_uuid`,
`register_id`, `schema_id`, index on `(object_uuid, role)`.

`openregister_task_audit`: `id`, `task_id`, `action`, `state_after`,
`actor`, `performer_type`, `on_behalf_of`, `mandate`, `reason`,
`authorized` (bool — denials are recorded too), `created`. Append-only: no
UPDATE or DELETE path exists, and the task's own deletion does not cascade
to it.

## Seed Data (ADR-001)

Fixtures for PHPUnit and for a demo instance, spanning three organisation
archetypes so the entity is exercised beyond the approval case. All UUIDs
are nil placeholders; all uids are obviously fake.

**1. Municipality — a pooled permit check, no flow attached.**
Exercises: `performer_type: group`, unclaimed pool, advisory `due_at`, an
anchor to a case object, `run_uuid` null.

```json
{
  "uuid": "00000000-0000-0000-0000-000000000001",
  "title": "Controleer bouwtekening op welstandseisen",
  "state": "enabled",
  "is_terminal": false,
  "performer_type": "group",
  "assignee": null,
  "candidate_groups": ["GEMEENTE_VERGUNNINGEN_TEAM"],
  "routing_strategy": "least-loaded",
  "priority": "normal",
  "due_at": "2026-09-04T17:00:00+02:00",
  "expires_at": null,
  "run_uuid": null,
  "object_uuid": "00000000-0000-0000-0000-0000000000aa",
  "register_id": 1,
  "schema_id": 1,
  "requester": "EXAMPLE_BALIE_USER"
}
```

**2. Consultancy — a delegated approval with enforcing expiry, on a run.**
Exercises: `performer_type: user`, delegation (`on_behalf_of` + `mandate`),
`expires_at` set, `run_uuid`/`node_id` provenance, comment-mandatory reject
path, a template snapshot.

```json
{
  "uuid": "00000000-0000-0000-0000-000000000002",
  "title": "Keur inkooporder > EUR 10.000 goed",
  "state": "active",
  "is_terminal": false,
  "performer_type": "user",
  "assignee": "EXAMPLE_DELEGATE_USER",
  "on_behalf_of": "EXAMPLE_DIRECTOR_USER",
  "mandate": "Volmacht inkoop 2026 — art. 4 lid 2",
  "priority": "high",
  "due_at": "2026-08-29T12:00:00+02:00",
  "expires_at": "2026-09-01T12:00:00+02:00",
  "run_uuid": "00000000-0000-0000-0000-0000000000f1",
  "node_id": "approve-purchase-order",
  "template_id": "00000000-0000-0000-0000-0000000000e0",
  "template_version": 3,
  "template_snapshot": { "checklist": [] },
  "object_uuid": "00000000-0000-0000-0000-0000000000bb",
  "requester": "EXAMPLE_CONTROLLER_USER"
}
```

**3. Travel agency — an agent task with a checklist and a relation.**
Exercises: `performer_type: agent`, typed checklist array, an epic parent,
a typed relation to a second object, priority normalised from an iCal
integer on import.

```json
{
  "uuid": "00000000-0000-0000-0000-000000000003",
  "title": "Verifieer visumvereisten voor reisgroep",
  "state": "active",
  "is_terminal": false,
  "performer_type": "agent",
  "assignee": "EXAMPLE_AGENT_IDENTITY",
  "priority": "urgent",
  "checklist": [
    { "id": "c1", "label": "Paspoortgeldigheid > 6 maanden", "description": null, "checked": true },
    { "id": "c2", "label": "Visumplicht per bestemming gecontroleerd", "description": null, "checked": false },
    { "id": "c3", "label": "Transitvisum nodig?", "description": null, "checked": false }
  ],
  "epic_task_id": null,
  "object_uuid": "00000000-0000-0000-0000-0000000000cc",
  "run_uuid": null,
  "requester": "EXAMPLE_TRAVEL_PLANNER"
}
```

**4. Two terminal tasks** — one `completed` with `outcome: "approved"` and
one `terminated` with a propagation reason naming a stopped run — so
`is_terminal`, outcome-preserved-through-collapse (D-7) and cancellation
propagation (D-8) have fixtures, and so the inbox has rows it must NOT
return as actionable.

**5. One audit fixture per task**, including one DENIED entry
(`authorized: false`) so the append-only denial path is covered by seed data
rather than only by a test double.

The seeds install through the existing seeding path used for OR's other
non-object fixtures and are idempotent on uuid.

## Migration Plan

1. Migration creates `openregister_tasks`, `openregister_task_candidates`,
   `openregister_task_relations`, `openregister_task_audit` with their
   indexes. Additive only — no existing table is altered, so rollback is a
   table drop and nothing else regresses.
2. `ApprovalChain`/`ApprovalStep` and `lib/Service/ApprovalService.php`
   stay live and untouched. They retire in `flow-approval-consolidation`;
   running both for one release is deliberate, and no data is copied here.
3. No data backfill in this change. The tables ship empty apart from seeds.
4. Rollback: drop the four tables. Because nothing else reads them yet,
   there is no dependent state to unwind — which is the reason this change
   is scoped to "the target exists" and migration is a later change.

## Risks / Trade-offs

- **A wide table (≈45 columns) invites "just one more column" per consuming
  app** → The fence is D-6 (relations, not FK columns) and the `metadata`
  blob for genuinely app-private fields, with the spec's rule that
  `metadata` is never read by lifecycle, authorization or inbox logic. A PR
  adding a column named after an app is the signal to push back.
- **Two `TaskService` classes in one codebase** (`lib/Service/TaskService.php`
  = CalDAV VTODO leaf, 753L; `lib/Service/Task/TaskService.php` = this) →
  Namespaced by directory and both docblocks state which is which. The VTODO
  leaf becomes a projection consumer in `flow-task-inbox-projections`; it is
  not renamed here to keep this change free of unrelated churn.
- **Storing SLA/recurrence/suspension columns that nothing interprets yet**
  → Accepted deliberately (D-4): the alternative is a second migration on
  the same table two changes later. The risk is a column that ships wrong
  and is only discovered when `flow-business-timers` reads it; mitigated by
  round-trip tests on every stored-but-uninterpreted column.
- **`is_terminal` is denormalised and can drift from `state`** → It is
  written only by the one lifecycle method that writes `state`, in the same
  statement, and a test asserts the invariant across every transition in
  the mapping table.
- **The candidate index table can drift from the `candidate_*` JSON** →
  Same mitigation shape: one write path maintains both inside the
  transaction; a test asserts pool membership queries and JSON agree after
  every assignment verb.
- **Refusing unknown legacy status values will fail migrations loudly**
  (procest's `'open'`, pipelinq's `"normaal"`) → Intended (D-7). The
  mitigation is that these are already filed as defects against the owning
  apps, not fixed silently inside this change.

## Open Questions

- Whether `openregister_task_candidates` should also index resolved role
  members (materialised) or resolve roles at query time. Resolution can be
  deferred: it is an index-population question that changes neither the
  spec, the table shape, nor the task breakdown, and the answer depends on
  role-backend latency measured after the inbox exists.
- The exact retention policy for `openregister_task_audit`. It is
  append-only either way; how long it is kept is an operations setting that
  can land with `flow-approval-consolidation`, when there is real volume to
  size it against.

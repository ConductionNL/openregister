---
kind: code
depends_on: [flow-definition-versioning]
---

# Proposal: flow-task-entity

## Summary

Give the fleet ONE task: a native `openregister_tasks` table, a
`TaskService` whose every lifecycle verb is fail-closed authorized, and a
queryable inbox that joins a task to the object it is about. The task is
**fleet-generic** — `run_uuid`/`node_id` are optional provenance, not
identity, so a standalone task with no flow attached is first-class. This
change delivers the entity, the service, the authorization and the inbox
query. Nothing that stands on top of them (the `user-task` node, forms,
projections, timers, migrations) is in it.

## Why

**A human step in OR Flow today has no owner.** `AwaitSignalNode` takes an
`assignee`, and its own config form says what it is worth:
"A user or group id. Recorded with the request; it does not by itself
restrict who may answer."
(`lib/Service/Flow/Nodes/AwaitSignalNode.php:200-203`). The endpoint behind
it, `POST /api/flow-runs/{uuid}/resume` (`appinfo/routes.php:1312`), is
`#[NoAdminRequired]` and guards only that the FLOW is runnable
(`lib/Controller/FlowRunController.php:423-436`) — never that the CALLER is
the person being waited on. Any authenticated user who knows a run uuid can
approve anyone's case. That is not a hardening backlog item; it is the
reason a human step cannot be trusted with an approval.

**And there is no inbox.** The signal is written into
`$run->getContext()['signal']` (`FlowRunService.php:521-537`). There is no
row that says "this person owes an answer", so there is nothing to list,
nothing to count, nothing to pool across a group, and nothing to chase.
"What is waiting for me?" is answerable only by scanning suspended runs and
reading free text out of a JSON column.

**Meanwhile the fleet has built the same task 23 times.** The inventory ran
2026-08-22: 23 task shapes across procest, pipelinq, planix, decidesk,
openbuild, shillinq, openconnector and OpenRegister itself. They do not
merely differ, they CONFLICT:

- `status` is six incompatible enums — `done` vs `completed`, `cancelled`
  vs `terminated`, and one single state spelled four ways
  (`in_progress` / `in-progress` / `in-execution` / `active`). Even the
  FIELD is renamed per app: `status` / `taskStatus` / `lifecycle` /
  `instanceState` / `action`.
- `assignee` means three different things: a Nextcloud uid, a
  non-binding recorded string, and a ROLE name
  (`lib/Db/ApprovalStep.php:81-87` stores `role`, not a uid).
- Priority runs on four scales: `low|normal|high|urgent`,
  `low|normal|high`, iCal integer 0-9, and `low|medium|high|critical`.
- The due date is `date-time` in some schemas and `date` in others, under
  six names (`dueDate`/`deadline`/`dueAt`/`expiresAt`/`dueBefore`/
  `targetDate`) — and `due` (advisory) and `expires` (enforcing) are
  conflated, though only openconnector actually auto-transitions on one.

The cost is already being paid in bugs, not in tidiness:
`procest/lib/Service/Transitions/CreateTaskHandler.php:76` writes
`status:'open'` into a schema whose enum has no `open`, so every
flow-spawned procest task is out-of-enum. And **three fleet schemas store
`overdue` as a status value** — a clock-derived fact written by hand, so
decidesk's `actionOverdue` notification, which filters
`taskStatus:'overdue'`, fires only if something remembered to write it.

## What Changes

- **A native entity + table**, `OCA\OpenRegister\Db\Task` /
  `openregister_tasks`. Shape-wise this is `ApprovalStep`
  (`lib/Db/ApprovalStep.php`, 208L) given a nullable `run_uuid` + `node_id`
  so it is addressable from a flow graph, plus the columns the 23-shape
  union demands. It is NOT an OR object and NOT a VTODO: both were
  evaluated and rejected (design.md records why — pooled-inbox query cost,
  one-calendar ownership, user-editable lifecycle state, racy whole-object
  PUT).
- **The union, resolved once**: nullable/synthesized `title` (4 shapes have
  none); CMMN lifecycle `available|enabled|active|completed|terminated|
  disabled` with a legacy-value mapping table and a materialised
  `is_terminal`; a `performer_type` of `user|group|agent|worker` (ADR-098
  D3) spanning uid, uid+group, group-only candidate pool, role name, five
  routing strategies and delegation (`on_behalf_of` + `mandate`);
  `due_at` (advisory) and `expires_at` (enforcing) as **separate columns**;
  one normalised priority; a typed `checklist` array replacing procest's
  JSON-in-a-STRING; **one** generic anchor (`object_uuid` +
  `register_id`/`schema_id`) plus a typed relation table, NOT 20 FK
  columns; completion metadata with comment-mandatory-on-reject; and
  `template_id` + `template_version` + a frozen `template_snapshot`.
- **`overdue` is DERIVED, never stored.** No column, no enum value. It is a
  computed projection of `due_at`/`expires_at` against now, so it cannot go
  stale and cannot be forgotten.
- **`TaskService`** with create / offer / claim / unclaim / assign /
  reassign / delegate / resolve / complete / cancel — each one authorized
  fail-closed against the performer model before it mutates anything, and
  each one appending to an immutable task audit that records the actor AND
  the performer type.
- **Cancellation propagation**: when a run reaches a terminal status, or a
  branch decision makes a pending task moot, its tasks are terminated with
  a reason. Orphaned inbox entries are the classic retrofit bug; this is
  built in, not added later.
- **A queryable inbox API**: "my tasks", "my group's unclaimed tasks",
  "tasks on this object", each joined to the subject object so a row
  carries case context — the exact thing `AwaitSignalNode` provably lacks.

## What does NOT change

Each of these is a separate change in the ADR-098 chain and is explicitly
OUT of scope here:

- **`flow-user-task-node`** — the `openregister.user-task` node, the
  suspend/resume wiring, and the `advance` budget (ADR-098 D9). This change
  gives the node something to create; it does not create the node.
- **`flow-task-forms`** — structured completion payloads over the
  lifecycle transition `inputs` contract
  (`lib/Service/Lifecycle/TransitionEngine.php:675-704`) and the nc-vue
  form family. Task completion here takes a typed but hand-specified
  payload.
- **`flow-task-inbox-projections`** — `INotificationManager` notifications
  and the CalDAV VTODO projection with the authorizing write-back listener.
  No task in this change notifies anybody or appears in a calendar.
- **`flow-business-timers`** — SLA, business-day arithmetic, escalation
  matrices, breach sweeps. `due_at`/`expires_at` are STORED here and acted
  on there.
- **`flow-approval-consolidation`** and the per-app changes — migrating the
  23 shapes, retiring `ApprovalService`, procest CMMN, openconnector HITL.
  Nothing is migrated in this change; the target simply comes into
  existence.

`AwaitSignalNode` also stays exactly as it is. It is superseded by
`flow-user-task-node`, not by this change.

## Capabilities

### New Capabilities
- `flow-tasks`: the fleet-generic task entity, its authorized lifecycle
  service, its append-only audit, and the inbox query surface.

### Modified Capabilities
<!-- None. flow-engine is untouched: no node, no run-lifecycle change here. -->

## Impact

- **Affected specs**: new `flow-tasks`. `flow-engine` untouched — this
  change adds no node and changes no run semantics.
- **Affected code**: new `lib/Db/Task.php`, `TaskMapper.php`,
  `TaskRelation.php`, `TaskRelationMapper.php`, `TaskAudit.php`,
  `TaskAuditMapper.php`; new `lib/Service/Task/TaskService.php` +
  `TaskAuthorizationService.php` + `TaskInboxService.php`; new
  `lib/Controller/TaskController.php` + routes; one migration.
  `lib/Service/TaskService.php` (753L, the CalDAV VTODO leaf) is a
  DIFFERENT thing and is not touched — the naming collision is resolved by
  namespacing the new one under `lib/Service/Task/`.
- **Affected apps**: none yet, by design. Consumers arrive with
  `flow-approval-consolidation`.
- **Depends on**: `flow-definition-versioning` — a task pinned to
  `run_uuid`/`node_id` is only meaningful if the run's definition version is
  pinned too; otherwise a task outlives the node that created it and points
  at a node id that has since changed meaning.
- **ADRs**: ADR-098 D2 (native entity, not objects, not VTODO-as-store),
  D3 (performer `user|group|agent|worker`), D4 (CMMN lifecycle leads),
  D6 (versioning first); ADR-031 (declarative-vs-imperative — see design.md);
  ADR-001 (seed data); ADR-005 (fail-closed authorization); ADR-065 (one
  engine).

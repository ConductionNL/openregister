# Tasks: flow-task-entity

## 1. Storage

- [x] 1.1 Migration creating `openregister_tasks`,
      `openregister_task_candidates`, `openregister_task_relations` and
      `openregister_task_audit` with the columns and indexes in design.md —
      Data model. Additive only: no existing table altered, no data
      backfilled. Verify `openregister_tasks` has NO `overdue`,
      `days_until_due` or `days_overdue` column.
- [x] 1.2 Entities + mappers under `lib/Db/`: `Task`/`TaskMapper`,
      `TaskCandidate`/`TaskCandidateMapper`,
      `TaskRelation`/`TaskRelationMapper`, `TaskAudit`/`TaskAuditMapper`.
      Follow `lib/Db/FlowRun.php` conventions (docblock `@method` block,
      `@spec` tag, EUPL-1.2 header, `hydrate()` + `jsonSerialize()` as in
      `lib/Db/ApprovalStep.php:154-206`). `TaskAuditMapper` exposes NO
      update or delete method.

## 2. Normalisation at the boundary

- [ ] 2.1 Lifecycle: the six CMMN states, the published legacy→state
      mapping covering at minimum the 18 values named in the spec,
      `is_terminal` written in the same statement as `state`, and the
      collapsed distinctions (`done`/`approved`, `cancelled`/`terminated`)
      preserved on `outcome`. An unmapped value is REFUSED naming the
      value — never coerced to a default.
- [ ] 2.2 Field normalisation and validation: priority across the four
      fleet scales onto `low|normal|high|urgent` (off-scale refused,
      naming the value); `expires_at` earlier than `due_at` refused;
      `title` synthesis from action + subject computed on read and NEVER
      persisted.

## 3. Authorization (before any mutation)

- [ ] 3.1 `lib/Service/Task/TaskAuthorizationService.php` — per-verb
      decisions per the spec, evaluated before mutation, DENYING on
      indeterminate (unresolvable role, unavailable group backend, unknown
      performer type). No nullable "service unavailable" return that a
      caller can read as "check skipped".
- [ ] 3.2 Performer resolution: `user|group|agent|worker`, candidate pool
      (users / groups / role), and the five routing strategies
      `single-role|or-set|hierarchical|round-robin|least-loaded` plus
      `routing_fallback`. A strategy resolving to nobody with no fallback
      leaves the task POOLED — no implicit assignment to requester, first
      pool member, or a system identity. Delegation lands here too:
      `on_behalf_of` + `mandate` accepted on the acting verbs, both
      identities carried into the audit entry, and a delegated action never
      recorded as the original performer acting.

## 4. TaskService

- [ ] 4.1 `lib/Service/Task/TaskService.php` skeleton + `create`, `offer`,
      `assign`, `reassign`. One write path maintains the
      `candidate_users`/`candidate_groups` JSON and the
      `openregister_task_candidates` index rows inside one transaction.
- [ ] 4.2 `claim` / `unclaim` — `claim` is a conditional update (assign IF
      unassigned) so concurrent claims yield exactly one assignee and a
      conflict for the loser, never a silent overwrite.
- [ ] 4.3 `resolve` / `complete` / `cancel` — a non-empty `comment` is
      MANDATORY on a rejecting or returning outcome; any verb against an
      already-terminal task is refused with a conflict naming the current
      state.
- [ ] 4.4 Template freeze and checklist: `template_id` +
      `template_version` + `template_snapshot` written at creation, all
      later evaluation reading the snapshot; checklist as a typed
      `{id,label,description,checked}` array with per-item addressing by
      id.
- [ ] 4.5 Audit append written in the SAME transaction as the mutation it
      records, for successes AND denials (`authorized: false`), carrying
      actor, `performer_type`, `on_behalf_of`, `mandate` and reason.

## 5. Cancellation propagation

- [ ] 5.1 Listener terminating every non-terminal task carrying a
      `run_uuid` when that run reaches a terminal status (`completed`,
      `stopped`, `dead_letter`, `failed` — `lib/Db/FlowRun.php` STATUS
      constants), plus an explicit service call for a task made moot by a
      branch decision. Idempotent (the reaper in
      `lib/BackgroundJob/FlowRunWorker.php` can observe terminality more than
      once), audited with the propagation source as actor, and a NO-OP for
      any task with `run_uuid` null.

## 6. Inbox

- [ ] 6.1 `lib/Service/Task/TaskInboxService.php` — assigned-to-me,
      unclaimed-in-my-pools, watched-by-me, and by-object queries joined to
      the subject object for register/schema/uuid/title. Filtering,
      sorting, pagination AND the total run in the datastore; visibility is
      part of the WHERE clause, never a post-filter over a wider result.
- [ ] 6.2 Derived-only temporal projection: `overdue`, `days_until_due`,
      `days_overdue` computed from `due_at`/`expires_at` against the clock
      by ONE function that backs the API projection and the inbox filter
      alike. Nothing writes them anywhere.

## 7. API

- [ ] 7.1 `lib/Controller/TaskController.php` + `appinfo/routes.php`
      entries for the lifecycle verbs and the inbox queries. Every method
      declares its auth posture attribute, and every method's actual
      authorization is `TaskAuthorizationService` — the attribute is never
      the whole check (the gap measured at
      `lib/Controller/FlowRunController.php:423-436`).

## 8. Seed data

- [ ] 8.1 Install the five seed groups from design.md — Seed Data
      (municipal pooled permit check with no run; consultancy delegated
      approval with enforcing expiry on a run; travel-agency agent task
      with a typed checklist; two terminal tasks including one terminated
      by propagation; one audit fixture per task including a DENIED entry)
      through the existing seeding path, idempotent on uuid.

## 9. Tests

- [ ] 9.1 Table-driven unit tests for the legacy status mapping and the
      priority normalisation, each including the live fleet defects as
      cases: `'open'`
      (`procest/lib/Service/Transitions/CreateTaskHandler.php:76`) and
      `"normaal"` (pipelinq `task.priority`) MUST both be refused.
- [ ] 9.2 Authorization and concurrency tests: a stranger denied on every
      verb; the two-claim race producing one assignee and one conflict; an
      unresolvable role denying rather than passing; a rejection without a
      comment refused; an injected audit-write failure leaving the task
      NOT completed.
- [ ] 9.3 Inbox and derivation tests: clock-controlled overdue with a
      byte-identical row before and after; a pooled task invisible to a
      non-member including in the total; datastore pagination returning 25
      of 120 with a correct total; `run_uuid`-null task surviving
      unrelated run terminations.
- [ ] 9.4 Playwright coverage for the two `@e2e`-marked scenarios in
      `specs/flow-tasks/spec.md`: a stranger refused on the task detail
      route, and the inbox route returning tasks with subject context.

## Acceptance criteria

- A task with `run_uuid` null passes every verb, every authorization rule
  and every inbox query identically to one with a run. No code path treats
  it as degraded.
- No column, enum value or persisted field anywhere records overdue.
- Every verb denies before mutating when the authorization answer cannot be
  determined; no verb is reachable by knowing a uuid alone.
- No app-named column exists on `openregister_tasks`; a new consuming
  entity kind is absorbed by `openregister_task_relations` without a
  migration.
- Terminating a run empties its assignees' inboxes of its tasks, with the
  reason recorded, and leaves standalone tasks untouched.
- `lib/Service/TaskService.php` (the CalDAV VTODO leaf, 753L) is unchanged
  by this work.
- Nothing in this change sends a notification, writes a VTODO, registers a
  flow node, or migrates an existing fleet task shape.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- Every new PHP file carries `@license EUPL-1.2` and
  `@copyright 2026 Conduction B.V.`; every public/protected method carries
  a `@spec openspec/specs/flow-tasks/spec.md` anchor.
- Regression check against opencatalogi and softwarecatalog: both are
  additive-migration-only consumers here, so the check is that their
  suites are green and no shared service signature changed.
- Depends on `flow-definition-versioning` — `node_id` is a pointer into a
  definition and needs the version pinned before it means anything.
- References ADR-098 (D2 native entity, D3 performer types, D4 CMMN
  lifecycle, D6 versioning first), ADR-031 (declarative-vs-imperative,
  design.md D-1), ADR-001 (seed data), ADR-005 (fail-closed authorization).

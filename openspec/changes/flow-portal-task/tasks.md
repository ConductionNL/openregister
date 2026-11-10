# Tasks: flow-portal-task

## 1. The node

- [x] 1.1 `lib/Service/Flow/Nodes/PortalTaskNode.php` implementing
      `IFlowNode`, `IFlowNodeConfigKeys` and `IFlowNodeConfigForm`. Follow
      `UserTaskNode` for shape: EUPL-1.2 header, `@spec` on every method, a
      file docblock stating the three-waiter division of labour (signal:
      a system that calls back; user task: a performer in the organisation;
      portal task: a party outside it). `getId()` returns
      `openregister.portal-task`.
- [x] 1.2 `configForm()` + `configKeys()`: title/description templates,
      party role (default `initiator`), upload requirements (required,
      count, types, max size), `outcomeKey` (default `portalTask`),
      re-ask reason item field, `dueAt`/`expiresAt` references,
      `heartbeatMinutes`, `advance`. Register the node in
      `lib/Listener/FlowNodeRegistrationListener.php`.
- [x] 1.3 `validateConfig()`: refuse a config with no party role; refuse
      `advance: null` exactly as `UserTaskNode` does. The mandatory re-ask
      reason cannot be checked statically (whether a firing is a re-entry
      is runtime knowledge), so it is validated at fire time per section 6.

## 2. Party matching

- [x] 2.1 Party resolver: resolve the configured role against the subject
      case object at creation, freeze the reference on the task, record
      role and reference in the audit; fail the firing loudly when the case
      names nobody for the role.
- [x] 2.2 No re-resolution anywhere: completion authorization reads the
      STORED reference only; add the case-edit scenario as a regression
      test.

## 3. Suspend, resume, outcome

- [x] 3.1 Reuse `flow-user-task-node`'s bridge for suspension and
      continuation: one task per node per run via the resume slot,
      non-null heartbeat `resumeAt` (15-minute default, 5-minute floor,
      never null: `FlowRunMapper::findAbandonedSignals()` matches
      `resume_at IS NULL` and `FlowRunWorker` fails matches at 14 days),
      continuation on task terminality read from the task.
- [x] 3.2 Outcome placement onto every item under `outcomeKey`: outcome,
      answer fields, stored file references, matched party reference;
      expiry/termination distinguishable from completion.

## 4. The portal delivery seam

- [x] 4.1 Subject-scoped portal-task read: list one portal subject's open
      external tasks with case context, shaped for ADR-046 consumption
      (descriptor aggregate, subject-scoped rows, no cross-subject rows or
      counts).
- [x] 4.2 Delivery request record (portal inbox message + mail) written at
      creation and re-ask, queryable delivery state, failure leaves the
      task and suspension standing.

## 5. Completion

- [x] 5.1 Completion endpoint on the portal seam: validate upload
      constraints fail-closed, store each accepted file via
      `FileService::addFile()` onto the CASE object BEFORE recording the
      completion, reference the stored files from the completion.
- [x] 5.2 Completion authorization: acting portal subject vs stored party
      reference, deny on any mismatch or unresolvable comparison, audit
      every denial; no completion-on-behalf path.
- [x] 5.3 Keep `POST /api/flow-runs/{uuid}/resume` unable to touch an
      external task (same contract as `flow-user-task-node`); regression
      test it.

## 6. Re-ask

- [x] 6.1 Re-entry path: slot task terminal + reason present → new task
      (fresh match, reason, cycle number, previous task uuid) + delivery;
      slot task terminal + reason absent → fail the firing naming the
      missing reason; slot task open → suspend again (heartbeat case).

## 7. The external performer type (flow-tasks delta)

- [x] 7.1 Extend the task entity/service with `performer_type: external`
      and the party-reference performer shape; refuse `claim`, `unclaim`
      and `delegate` for it naming the performer type.
- [x] 7.2 Exclude external tasks from every Nextcloud inbox query, count
      and projection; keep them readable on their anchored object for
      authorized caseworkers, with delivery state.

## 8. Timers

- [x] 8.1 Pass `due_at`/`expires_at` references through to the task; wire
      the preBreach (party reminder via the portal seam) and slaBreached
      (caseworker escalation) rung addressing as consumption of
      `flow-business-timers`; add no clock, sweep or business-day code
      here.

## 9. Follow-up (not in this change)

- [ ] 9.1 portaliq: a follow-up change IN PORTALIQ'S REPO contributes the
      portal-task collection and completion action (rendering the task,
      the upload form and the inbox message) against the seam of section
      4. One canonical home per spec; nothing of it is specified here.

## 10. Tests

- [x] 10.1 Node unit tests: idempotence across a heartbeat wake, empty
      firing, frozen match incl. the case-edit regression, re-ask cycle
      and mandatory reason, expiry-vs-completion distinguishability,
      non-null `resumeAt`.
- [x] 10.2 Authorization and validation tables: wrong subject,
      unresolvable subject, missing required upload, oversized file, and
      the refused verbs (`claim`/`unclaim`/`delegate`) on an external
      task.
- [x] 10.3 Playwright coverage for the six `@e2e`-marked scenarios across
      `specs/flow-portal-task/spec.md` and `specs/flow-tasks/spec.md`,
      including the negative one: another portal subject cannot complete
      a task that is not theirs.

## Acceptance criteria

- A flow containing a portal-task node matches the case's initiator,
  creates exactly one external task, suspends with a non-null `resumeAt`,
  and continues only on task terminality, verified across a heartbeat
  wake.
- The uploaded file exists as an OR file attachment on the case object
  before the completion is recorded; no portal-private file store exists.
- Completion is denied to every caller but the matched portal subject,
  fail-closed, including through the run resume endpoint.
- A re-ask produces a new task with a mandatory reason, a cycle number and
  the previous task's uuid; the previous task is untouched.
- External tasks appear in no Nextcloud inbox and no notification or
  VTODO projection, and remain visible with delivery state on their
  anchored case.
- No timer logic exists in this change; reminder and escalation are
  expressed as `flow-business-timers` rungs.
- `AwaitSignalNode`, `UserTaskNode`, `FlowRunController::resume()` and all
  existing endpoints are unchanged.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- Every new PHP file carries `@license EUPL-1.2` and
  `@copyright 2026 Conduction B.V.`; every public/protected method carries
  a `@spec openspec/changes/flow-portal-task/...` anchor.
- Depends on `flow-task-entity` and `flow-user-task-node`; deploy order is
  that chain, then this change, then portaliq's contribution change.
- References ADR-098 D3 as amended 2026-08-31 (`external` performer;
  upload lands on the case object), ADR-046, ADR-108, ADR-022, ADR-005,
  ADR-031 (design.md D-2).

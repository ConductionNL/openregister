# Tasks: flow-user-task-node

## 1. The node

- [x] 1.1 `lib/Service/Flow/Nodes/UserTaskNode.php` implementing `IFlowNode`,
      `IFlowNodeConfigKeys` and `IFlowNodeConfigForm`. Follow
      `lib/Service/Flow/Nodes/AwaitSignalNode.php` for shape: EUPL-1.2
      header, `@spec` on every method, a file docblock that states the
      division of labour with `await-signal` and the reason the heartbeat
      exists. `getId()` returns `openregister.user-task`;
      `isAvailableForScope()` allows `SCOPE_ADMIN` and `SCOPE_USER`.
- [x] 1.2 `configForm()` + `configKeys()` covering title/description
      templates, candidate users/groups/role, routing strategy and fallback,
      priority, `dueAt`/`expiresAt` references, outcome vocabulary,
      `outcomeKey` (default `task`), `failOnReject`, `heartbeatMinutes`,
      `advance`. No editor change needed —
      `FlowNodeRegistry::palette()` already publishes `configForm`
      (`lib/Service/Flow/FlowNodeRegistry.php:243-250`).
- [x] 1.3 `validateConfig()` — refuse a config naming no candidate user,
      group, role or fallback; refuse `advance: null` with a message naming
      the value and stating that unlimited is spelled `"all"`; refuse an
      `advance` that is neither `0`, a positive integer, nor `"all"`.
- [x] 1.4 Register the node in `lib/Listener/FlowNodeRegistrationListener.php`
      alongside the existing built-ins. Do not touch `AwaitSignalNode.php`.

## 2. Suspend and resume

- [x] 2.1 First-firing path: no items → return items unchanged and do NOT
      suspend; items present and no task in this node's resume slot → create
      one task via `flow-tasks`' `TaskService` with `run_uuid` + `node_id`,
      store its uuid and the creation time in the slot
      (`FlowNodeResumeState`), then throw `FlowSuspension`.
- [x] 2.2 Heartbeat: suspend with a non-null `resumeAt`, defaulting to 15
      minutes and clamped to a 5-minute floor, matching
      `AwaitSignalNode.php:87` and `:98`. NEVER `resumeAt: null` — that is
      the only shape `FlowRunMapper::findAbandonedSignals()`
      (`lib/Db/FlowRunMapper.php:589-605`) reaps, and it would FAIL slow
      approvals at 14 days (`lib/BackgroundJob/FlowRunWorker.php:94`).
- [x] 2.3 Continuation path: read terminality from the TASK by uuid, never
      from `$context['signal']`. Non-terminal → suspend again without
      restamping the creation time. Terminal → continue. Idempotence is
      per node via the resume slot, so two user-task nodes in one flow keep
      independent state.

## 3. Outcome and rejection

- [x] 3.1 Write the completion result onto EVERY item under `outcomeKey` —
      outcome, comment, completing identity, performer type, `on_behalf_of`
      — and mark a task that went terminal WITHOUT a completion (terminated,
      expired) distinguishably. Non-array items are skipped, not fatal.
      Rationale is `AwaitSignalNode.php:294-296`: a Switch cannot branch on
      something only the run holds.
- [x] 3.2 Rejection is a BRANCH: continue by default, `failOnReject` opt-in
      raising `FlowStop`, same shape as `AwaitSignalNode.php:277-287`.

## 4. The advance budget

- [x] 4.1 Completion listener: signal the run with an EMPTY payload so it is
      parked as due (`FlowRunService::signal()`), then honour the node's
      budget — `0` returns immediately for the worker; `N` and `"all"` call
      `FlowRunAdvancer::advance(run: $run, rethrow: true)`, the same path
      `FlowService.php:511` already uses.
- [x] 4.2 Per-walk transition ceiling carried on the run context and read by
      the engine's existing loop counter alongside `MAX_TRANSITIONS`
      (`lib/Service/Flow/FlowEngine.php:103`, `:325`); the lower ceiling
      wins. No second walk implementation, no second oversight call site —
      `assertOversightAllows()` (`FlowEngine.php:425`) still gates every hop
      and still fails closed.
- [x] 4.3 Degradation: a throw during in-request continuation leaves the task
      completed and the run due for the worker, and the completing caller is
      told the task was accepted. The budget is an optimisation; its failure
      mode is the unoptimised behaviour.

## 5. Cancellation propagation

- [x] 5.1 Run-terminality listener: on `completed`, `stopped`, `failed` or
      `dead_letter` (`lib/Db/FlowRun.php` STATUS constants), terminate every
      non-terminal task created by any user-task node in that run, reason
      naming the run and its status, propagation source as actor.
      Idempotent — terminality is observable twice (completing request and
      `FlowRunWorker::reapStale()`, `lib/BackgroundJob/FlowRunWorker.php:226-287`).
- [x] 5.2 Branch-mootness call: when `keepOnlyTakenExits()`
      (`FlowEngine.php:410`, `:540`) prunes a place holding a live
      user-task node's task, terminate it with a reason naming the branch.
      Never reaches a task with `run_uuid` null.

## 6. Tests

- [x] 6.1 Node unit tests: one task per node per run across a heartbeat wake;
      empty firing creates nothing and does not suspend; claim is not
      completion; terminality read from the task and never from the signal
      slot; asked-at not restamped; two nodes in one flow requiring two
      completions.
- [x] 6.2 Config-validation table: `advance` accepting `0`, `3`, `"all"` and
      REFUSING `null`, `""`, `-1` and `"unlimited"`, each with the value in
      the message; a config with no resolvable performer refused.
- [x] 6.3 Budget and oversight tests: `0` leaves the run suspended-and-due;
      `"all"` finishes the run in-request; a vetoing oversight check stops
      the in-request walk with the check id; an injected downstream throw
      leaves the task completed and the run advanceable.
- [x] 6.4 Propagation tests: stopping a run terminates both its tasks with a
      reason; a losing parallel branch takes its task with it; a second
      observation of terminality records nothing; a `run_uuid`-null task is
      untouched.
- [ ] 6.5 Playwright coverage for the eight `@e2e`-marked scenarios in
      `specs/flow-user-task-node/spec.md`, including the negative one: a
      caller who may run the flow but is not the performer CANNOT answer the
      task through `POST /api/flow-runs/{uuid}/resume`.

## Acceptance criteria

- A flow containing a user-task node creates exactly one task, suspends, and
  continues only when that task is terminal — verified across a heartbeat
  wake and a duplicated worker pass.
- No code path suspends a user-task node with `resumeAt: null`, and no run
  holding an open user task becomes eligible for the abandoned-signal
  reaper.
- `advance: null` is refused at config validation; `0`, `N` and `"all"` are
  the only accepted shapes, and `"all"` stops at the next suspension, the
  next user task, or an end.
- Every in-request continuation passes the same fail-closed oversight check
  as a worker-driven one, and stays under `FlowEngine::MAX_TRANSITIONS`.
- Terminating a run empties its assignees' inboxes of that run's tasks, with
  reasons recorded and no second entry on a second observation.
- A downstream Switch can branch on the outcome without reading run context,
  and can tell a rejecting completion from an expiry.
- `lib/Service/Flow/Nodes/AwaitSignalNode.php`,
  `lib/Controller/FlowRunController.php` and
  `lib/Service/Flow/FlowRunService.php::signal()` semantics are unchanged;
  existing `await-signal` flows behave identically.
- Nothing in this change sends a notification, writes a VTODO, defines a
  form, computes an SLA, or maps BPMN.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- Every new PHP file carries `@license EUPL-1.2` and
  `@copyright 2026 Conduction B.V.`; every public/protected method carries a
  `@spec openspec/specs/flow-user-task-node/spec.md` anchor.
- Regression check against opencatalogi and softwarecatalog: both consume
  the shared engine, so the check is that their suites are green and no
  existing node, endpoint or service signature changed.
- Depends on `flow-task-entity` (itself on `flow-definition-versioning`) —
  a `node_id` on a task is a pointer into a definition and means nothing
  until that definition is pinned for the life of the run.
- References ADR-098 (D1 one engine, D3 performer types, D9 the advance
  budget and `"all"` never `null`), ADR-065 (the node joins the single
  engine), ADR-031 (declarative-vs-imperative, design.md D-1).

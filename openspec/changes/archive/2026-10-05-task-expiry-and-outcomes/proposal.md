# Task expiry and configurable outcomes

## Why

The shared task entity stores `expires_at` and calls it the ENFORCING
deadline, but nothing enforces it unless a `wettelijk` expiry timer happens
to exist for the task. A task created with a bare `expiresAt` — over HTTP,
through `import()`, or by a consuming app — keeps a decorative deadline
forever.

integriq's app-local HITL implementation (harvested by the fleet audit) has
the semantics the shared service lacks: every `approval_request` carries an
`expiresAt`, an `onTimeout` behaviour and an `onReject` behaviour. Its sweep
turns a pending request past its `expiresAt` into `expired` (or
`dead_letter` when `onTimeout` says so), and a rejection lands in
`dead_letter` when `onReject` says so. Those behaviours are what this change
moves into the shared service, so integriq (wave 2) and later adopters can
delegate instead of duplicating.

## What changes

- `openregister_tasks` gains two nullable columns, `on_timeout` and
  `on_reject`, each holding one value of the existing reserved timer-outcome
  vocabulary (`skip` | `error` | `dead_letter`).
- `TaskBuilder` accepts `onTimeout` and `onReject` at intake, refuses values
  outside the vocabulary by name, and refuses `onTimeout` on a task without
  an `expiresAt`.
- `FlowTimerSweep` gains a third bounded range scan: non-terminal tasks whose
  `expires_at` has passed and whose `on_timeout` is declared. Each hit goes
  through the existing `TaskService::applyTimerOutcome()`. Same worker
  (`FlowTimerWorker`), same pass, same bounded-scan discipline — no second
  scheduler.
- `FlowTimerService` applies a task's own declared `on_timeout` when a
  non-enforcing expiry timer of that task fires, so a timer-managed task is
  enforced deterministically even though `project()` rewrites `expires_at`.
- A rejecting completion of a task that declares `on_reject: dead_letter`
  records the dead-letter outcome instead of a plain rejection, through the
  same outcome mapping the timer path uses.
- The task JSON (entity serialization and the task-form description) carries
  `onTimeout` and `onReject`, and the user-task and portal-task nodes accept
  both keys next to `expiresAt`.

## Impact

- Affected specs: task-expiry-and-outcomes (new delta).
- Affected code: `lib/Db/Task.php`, `lib/Db/TaskMapper.php`, a new
  migration, `lib/Service/Task/TaskBuilder.php`,
  `lib/Service/Task/TaskService.php`,
  `lib/Service/Task/TaskFormResolver.php`,
  `lib/Service/Flow/Timer/FlowTimerSweep.php`,
  `lib/Service/Flow/Timer/FlowTimerService.php`,
  `lib/Service/Flow/Nodes/UserTaskNode.php` (+ config),
  `lib/Service/Flow/Nodes/PortalTaskNode.php` (+ config).
- Backwards compatible: a task without a declared `on_timeout` keeps today's
  behaviour (nothing enforces the bare deadline); a task without `on_reject`
  keeps the plain rejecting completion.

# Tasks: task-expiry-and-outcomes

## 1. Storage

- [x] 1.1 Add `on_timeout` and `on_reject` columns plus the
      `or_tasks_open_expiry (is_terminal, expires_at)` index to
      `openregister_tasks` (new migration).
- [x] 1.2 Add the fields to the `Task` entity (types, serialization).

## 2. Intake

- [x] 2.1 `TaskBuilder` accepts and validates `onTimeout`/`onReject`
      against the reserved vocabulary; `onTimeout` without `expiresAt`
      refused.

## 3. Enforcement

- [x] 3.1 `TaskMapper::findDueTimeouts()` — bounded, index-backed scan.
- [x] 3.2 `FlowTimerSweep` third scan calling
      `TaskService::applyTimerOutcome()` per hit, counted and truncation
      reported.
- [x] 3.3 `FlowTimerService::applyOutcome()` fallback to the task's
      declared `onTimeout` for a non-enforcing expiry timer.

## 4. Reject routing

- [x] 4.1 `completeInternal()` routes a rejecting completion through the
      timer-outcome mapping when the task declares `onReject:
      dead_letter`.

## 5. Surfaces

- [x] 5.1 Task JSON and task-form description carry both behaviours.
- [x] 5.2 User-task and portal-task nodes accept both config keys.

## 6. Tests

- [x] 6.1 TaskBuilder validation, sweep scan, timer fallback, reject
      routing.

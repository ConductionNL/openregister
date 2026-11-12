# Design: task expiry and configurable outcomes

## D-1: the behaviours live on the task row, not in metadata

`on_timeout` and `on_reject` are two nullable string columns. The sweep
selects on `on_timeout`, so it must be a column, not a JSON key; `on_reject`
matches for symmetry and because consumers (a resuming bridge, a portal
form) read it as a first-class contract of the task. Null means "no declared
behaviour", which is exactly today's behaviour.

## D-2: one vocabulary, one mapping

Both columns use the reserved timer-outcome vocabulary already published by
`TaskService::timerOutcomeTarget()`: `skip` → completed/skipped, `error` →
terminated/failed, `dead_letter` → disabled/dead_letter. integriq's values
map onto it directly (`error` default, `dead_letter` opt-in; its `skip`
means "continue without the gate", which the flow reads from the recorded
outcome). A second mapping for the same words would drift; a rejecting
completion routed by `on_reject` therefore resolves through the SAME method
as a fired timer.

## D-3: the sweep rides FlowTimerSweep — a third scan, not a second scheduler

integriq enforced expiry with its own 300s `ApprovalTimeoutSweepJob`. The
shared service already has a 300s sweep worker (`FlowTimerWorker` →
`FlowTimerSweep`) with the bounded-range-scan discipline (design D-8 of
flow-business-timers: never read a page of open rows and filter in PHP).
Task expiry becomes the pass's third scan:

    is_terminal = false AND on_timeout IS NOT NULL AND expires_at <= now
    ORDER BY expires_at LIMIT batch

backed by a new `or_tasks_open_expiry (is_terminal, expires_at)` index. A
processed row leaves the scan because `applyTimerOutcome()` makes it
terminal; a row that fails to process is counted, logged, and retried next
pass — the same contract the timer scans keep.

## D-4: a timer-managed task is enforced at the timer, not by the scan

`FlowTimerService::project()` rewrites a task's `expires_at` from its OPEN
timers, and nulls it once the last expiry timer fires. A non-enforcing
expiry timer (legal effect below `wettelijk`, so `onExpiry` is refused)
would therefore fire, null the projection, and starve the scan — the task's
declared `on_timeout` would never apply. So `applyOutcome()` gains a
fallback: when the fired expiry timer is NOT enforcing and the subject task
declares `on_timeout`, the task's own behaviour is applied. An enforcing
timer still wins: `wettelijk` is the stronger claim and its `onExpiry` was
validated at arm time.

## D-5: `on_reject` only reroutes `dead_letter`

integriq's `onReject` values `error` and `skip` are instructions to the
RESUMING orchestration (fail the pipeline vs continue past the gate); the
record itself stays `rejected`. Only `dead_letter` changes the terminal
record. The shared service mirrors that: a rejecting completion (per
`TaskState::isRejectingOutcome()`, comment still mandatory) of a task with
`on_reject: dead_letter` records outcome `dead_letter` in state `disabled`,
with the original rejecting outcome preserved in the audit reason. `error`
and `skip` are stored and serialized for the consumer to read, and change
nothing about the completion itself.

## D-6: intake refuses a timeout with no deadline

`onTimeout` without `expiresAt` is a configuration error, refused at
`TaskBuilder` intake naming both fields — the same posture as the existing
"expiry before due" refusal. `onReject` needs no deadline.

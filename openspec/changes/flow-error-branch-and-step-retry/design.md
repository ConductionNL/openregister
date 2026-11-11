# Design: flow-error-branch-and-step-retry

Read at openregister development 555af7212.

## Context

- `FlowEngine` knows three failure policies: `ON_ERROR_STOP`,
  `ON_ERROR_CONTINUE` and `ON_ERROR_DEAD_LETTER`
  (`lib/Service/Flow/FlowEngine.php:105-109`). The stream walk reads
  `$step['onError']` at `:965` and ends the stream or the run; the single-stream
  walk does the same in `outcomeForFailedStep()` (`:1236-1270`). No policy
  routes the failure anywhere.
- `FlowNodePreflight` reads `onError` as an EDGE-level key and warns when it
  sits in node config (`lib/Service/Flow/FlowNodePreflight.php:154-220`), with
  `stop` as the default.
- `FlowRunWorker` checks `continue` separately (`lib/BackgroundJob/FlowRunWorker.php:444`).
- A run can already suspend with a wake time: `FlowSuspension` carries
  `resumeAt` (`lib/Service/Flow/FlowSuspension.php:52`), and the engine records
  it on the stream (`FlowEngine.php:616`, `:938`).
- `FlowRunService::retry()` re-queues a whole run from the start.

## D-1: retry suspends, it does not sleep

A failed attempt with tries left throws a `FlowSuspension` with
`resumeAt = now + delay`, and records the attempt number on the node's resume
state. On resume, the engine runs the same step again with the same items. So
a retry never holds a worker, and a restart in between loses nothing.
`backoff: "fixed"` keeps the delay; `"exponential"` doubles it per attempt.
`attempts` is capped at 10 and `delaySeconds` at one hour, refused above at
save.

## D-2: the error branch is a fourth policy

`ON_ERROR_BRANCH = 'branch'` joins the constants. The lowered step carries
`errorTo`, the place the node's `error` output edge leads to. On failure after
the last attempt, the engine emits the failed items to `errorTo`, each with
`json.error = { message, step, attempt, at }`, and continues the walk. A step
with `branch` and no `error` edge is refused at publish by the preflight,
naming the node: a branch to nowhere would lose the items silently.

Per-item nodes that already isolate item failures (see the concurrency
requirement in `flow-engine/spec.md`) send only the failed items down the
branch. A node that fails as a whole sends all its input items.

## D-3: one reading of the policy

Both walks and `FlowRunWorker` read the policy through one helper,
`FlowErrorPolicy::for(step)`, so the three places cannot drift apart again.

## D-4: the trace shows attempts

Each attempt is a trace entry with its number and error. The entry for the
final failure says `branched` with the item count, or `failed` as today.

## Risks

- A retried step that already had side effects (a sent mail) repeats them.
  The docs say retry suits idempotent calls, and the node config form shows
  that sentence next to the setting.

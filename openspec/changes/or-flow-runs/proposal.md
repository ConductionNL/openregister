# Proposal: or-flow-runs

## Summary

Make a flow run outlive the request that started it.

## Why

`FlowEngine` takes a `MarkingStoreInterface` and the engine change (#2064)
deferred persisting the marking. That single deferral is what stands between us
and five separate workstreams:

| Blocked | Why |
|---|---|
| **Wait** | A run that pauses must survive the request that started it |
| **Sub-flows** | A parent must be suspended while a child runs |
| **Run history** | Nothing to list if runs are not stored |
| **Retry** | Cannot re-run what was not recorded |
| **The Nextcloud Flow bridge** | A Flow operation runs inside the dispatch of the event that triggered it — often a file write — and a graph must not block that |

Five things, one dependency. It is built first, not alongside.

## What Changes

- `FlowRun` + `FlowRunMapper` + `openregister_flow_runs`. A run stores its
  marking, its items, its context and its log.
- `FlowRunMarkingStore` — a `MarkingStoreInterface` backed by the run row, so
  the marking survives the request.
- `FlowSuspension` — thrown by a step that wants the run paused. An exception,
  not a return value: a node returns ITEMS, and smuggling "please suspend" into
  that return would mean every node author had to know about a magic item
  shape, with a forgetful node silently continuing.
- `FlowEngine::STATUS_SUSPENDED`, caught **before** the generic `Throwable`
  handler so a step's `onError` policy never sees a pause. `continue` would
  otherwise skip straight past a Wait, which is the opposite of waiting.
- `FlowRunService` — queue, execute, resume, persist.
- `FlowRunWorker` — a per-minute job that starts queued runs, resumes due ones,
  and prunes old ones.

## Design decisions

- **The marking does not advance past a suspended step.** The run must resume
  ON that transition, re-entering the step that asked to wait. Advancing would
  skip it.
- **Resume uses the stored items, never a re-seed.** Re-seeding from the
  subject would discard everything earlier steps produced.
- **A terminal run is never re-executed.** Retry creates a new run; re-running
  the old one would repeat every side effect it already performed.
- **Retention ships in this change, not after it.** Runs are operational data
  and grow without bound; this instance has already been taken down once by a
  file nobody was pruning. Default 30 days, `0` disables.

## Out of scope (this change)

- **Resolving the flow document and subject in the worker.** Both arrive with
  the flow-document store. Until then the worker leaves a claimed run for the
  next pass rather than failing it, so no run is lost between the two changes.
- **Retry, pin/mock and the run-history UI** — #2070, which this unblocks.

# Proposal: or-flow-test-run

## Summary

`POST /api/flow-runs/test` — run a flow now and get its result back. The
interactive surface that makes pinned output (or-flow-pins) and run-from-here
(or-flow-partial-run) usable: an author runs a flow synchronously, supplying
`startAt` and `pins`, and gets the whole trace straight away.

## Why

The engine and service already support pins and run-from-here, but nothing
exposed them — a UI or agent had no way to ask for a synchronous run. A trigger
queues a run for the worker and returns nothing to look at; iterating on a flow
needs the opposite: run it here, now, and show me what each step did. This is
n8n's "Execute workflow" button.

## What Changes

- `FlowRunController::test` (`POST /api/flow-runs/test`), `@NoAdminRequired`:
  - `flowId` (required) — resolved through the same `FlowResolverRegistry` the
    triggers use, so any consuming app's flow can be test-run. Unknown flow → 404;
    missing flowId → 400.
  - `startAt` (optional) — run from that node (run-from-here).
  - `pins` (optional) — step name → item list, put on the run context so pinned
    steps are skipped.
  - `seedItems` (optional) — the items to start with, normalised to the item
    shape.
  - Runs synchronously via `FlowRunService::queue` + `execute` and returns the
    finished run (status, per-step log, items).
- The run is persisted like any other, trigger `test`, so it also appears in the
  run history — a test run is a first-class run, not a throwaway.

## Out of scope

- A builder affordance (the "Execute" button and the pin/inspect UI). This is the
  API it calls.
- An MCP `testFlow` tool. The async `openregister.runFlow` already exists; a
  synchronous MCP variant can wrap this later if agents need it.

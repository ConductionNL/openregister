# Proposal: or-flow-partial-run

## Summary

Run-from-here — start a flow at a chosen node instead of its beginning, seeding
that node with supplied items. Together with pinned output (or-flow-pins) this is
n8n's authoring loop: pin the expensive upstream step, then re-run only the part
being worked on.

## Why

The other half of #2070's execution tooling. Pinning skips a step's side effect;
run-from-here skips whole upstream *sections*, so an author iterating on the tail
of a flow does not re-run (or re-pin) everything before it. Neither is useful
without the other — this completes the pair.

## What Changes

- `FlowEngine::run` gains an optional `startAt` node. When set, it overrides the
  flow's `initial` place, so the run begins there and the seed items land on that
  node; the steps before it do not run. The builder already validates `initial`,
  so an unknown start node fails the run exactly as a malformed document does.
- The override is a fresh-run concern only. A resume's marking is already
  mid-graph and comes from the store, so `FlowRunService::execute` drops
  `startAt` when resuming — the same rule it already applies to seed items.
- `run()`'s cyclomatic complexity is held at the baseline (12) by extracting the
  override into `withStartNode()`.

## Out of scope (follow-up)

- The **endpoint / MCP tool** that offers run-from-here interactively (supplying
  `startAt`, seed items and pins, and returning the result synchronously). The
  engine and service support are here; the interactive surface layers on top,
  and — because a partial run is an editor action, not a background trigger — it
  is a synchronous test-run endpoint rather than the async queue.

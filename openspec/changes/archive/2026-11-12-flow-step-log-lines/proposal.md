---
kind: code
---

# Proposal: flow-step-log-lines

## Summary

A run view shows, per step, when it started and what it said: "08:41:02.380
looked up the account", "08:41:02.441 no account on the portal". The run log
has neither today: one entry per step with a duration, and a free `report`
map. This change adds `startedAt` (ISO 8601, milliseconds, UTC) to every step
entry the engine writes, and `FlowStepReport::line(level, message)`, which a
node calls to append a timestamped line to its entry under `report.lines`.

## Why

The shared run view in `@conduction/nextcloud-vue` (boards PqFlowRun and
PqFlow) draws a step's log lines with an outcome pill and a time. Without a
start per step the client can only add durations up from the run's start,
and without lines it can only restate the step's status.

## Scope

- `FlowEngine`: `startedAt` on every step entry (completed, failed, stopped,
  suspended, pinned), on both walks.
- `FlowStepReport::line()`: levels `info`, `success`, `warning`, `error`
  (anything else is `info`), at most 50 lines per step, the last saying how
  many were left out.
- Nodes adopt `line()` in follow-up changes per app; nothing breaks for a node
  that does not.

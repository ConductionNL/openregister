---
kind: code
depends_on:
  - or-flow-action-nodes
---

# Proposal: or-flow-connectivity-and-last-run

## Summary

Make "this flow runs off the end of a node and stops" impossible to ship by
accident. Every node either **exits deliberately** or **continues somewhere**; a
node that does neither is a dead end. Saving one warns; running one is refused,
and the flow is left in `error` with a message saying why.

Flows also start carrying their **last run** — status, message, timestamp and
run uuid — so a list or an editor can show what happened without a second query
against the runs table.

## Why

A flow that walks into a node with no outgoing edge simply stops. The marking
has nowhere to go, the run ends, and the status is whatever the engine last set
— not an error, because nothing failed. That is indistinguishable from a flow
that finished its work, which means the two things an operator most needs to
tell apart look identical.

This is the same failure shape as the untyped-step problem
(`or-flow-action-nodes`): the engine reports success for work it never did.
Connectivity is the structural half of the same guarantee — **a flow should
always either exit or error.**

The dead end is also invisible while authoring. On a canvas, an unconnected
right-hand port looks exactly like a finished branch; nothing distinguishes
"I meant to end here" from "I have not wired this yet". So the author is told at
save time, when the intent is still in their head.

Refusing at save would be wrong, though. Half-built flows are the normal state
of an unfinished flow, and a save gate would force authors to keep work outside
the tool. The warning is loud and the save proceeds; the **run** is where the
refusal bites, because that is the first moment the ambiguity can cause harm.

## What Changes

- **An exit node is explicit.** A node is an exit if its step type is registered
  terminal, or it carries `exit: true`. Terminal-ness is declared by a new
  marker interface — `IFlowTerminalNode`, implemented by `StopNode` — and
  **not** by a new method on `IFlowNode`: openconnector and hermiq implement
  that interface from their own repositories and would fatal on load
  (established in `or-flow-preflight`).
- **A connectivity check** reports every non-exit node with no outgoing edge.
  It is a WARNING at save (`POST`/`PUT /api/flows`) and reported by
  `POST /api/flow/validate` under `warnings`.
- **A run is refused** when the check fails: no `FlowRun` is created, the flow's
  `status` becomes `error`, and `status_message` names the offending nodes.
- **Flows carry a status.** `status` + `status_message` columns describe the
  flow definition's own health, distinct from any individual run's outcome.
- **Flows carry their last run**: `last_run_uuid`, `last_run_status`,
  `last_run_message`, `last_run_at`, written when a run reaches a terminal state.

## Why a flow status separate from the last run's status

They answer different questions and they disagree in exactly the case that
matters. `last_run_status` is "what happened when this last executed";
`status` is "can this execute at all". A flow refused for a dead end has **no**
last run to point at — that is the whole point of refusing — so a UI reading
only run history would show it as never-run and healthy.

## Impact

- **Affected specs**: `flow-storage` (new columns), `flow-engine` (run refusal)
- **Affected code**: `FlowService::run()`, `FlowController::run()`,
  `FlowNodePreflight`, `FlowRunService` (write-back of the last run),
  `lib/Db/Flow.php` + mapper, `StopNode`
- **Affected data**: a migration adds six nullable columns; no backfill needed
  (a flow with no runs correctly has no last run)
- **Affected apps**: hermiq surfaces the warning and the last run
  (`hermiq-flow-canvas-ports`)

## Capabilities

### Modified Capabilities
- `flow-storage` — flow status and last-run fields
- `flow-engine` — connectivity as a run precondition

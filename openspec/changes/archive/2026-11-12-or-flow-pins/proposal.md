# Proposal: or-flow-pins

## Summary

Pinned step output — n8n's "pin data". A run may carry a map of step name to a
stored item list; a pinned step is not executed, its stored output is used
verbatim, and its side effect is skipped. This is the execution-tooling half of
#2070 that authoring a flow needs most.

## Why

Iterating on a flow means running it over and over. Without pins, every run
re-executes every step — including the one that hits a real API, sends the
email, or writes the record. Pinning that step's output lets an author re-run
the *downstream* steps as often as needed without paying (or repeating) the
expensive or irreversible one. It is the single biggest quality-of-life feature
in n8n's editor, and it is small here because the engine already threads items
step to step.

## What Changes

- `FlowEngine.run` checks a `pins` map before dispatching each step. The map is
  step name → item list. When the step is pinned, its stored items become the
  step's output, the step is **not** dispatched (no side effect), and the trace
  records it as `pinned` rather than `completed`.
- Pins are read from the run's `context.pins` first (a test/authoring run
  supplies them without touching the stored flow), falling back to a `pins` map
  on the flow document itself. A run's pins always win.
- A pinned step short-circuits before dispatch, so it can neither stop, suspend
  nor fail — a pinned step always just produces. That is the point: pin the step
  that would fail or block, and the rest of the flow runs.

## Out of scope (follow-up)

- **Partial execution / run-from-here**: starting a run at a chosen step, seeding
  the upstream from pins. That needs the initial marking placed mid-graph, which
  is a larger, riskier change to run seeding — a separate change. Pinning is its
  prerequisite and lands first.
- An authoring UI for capturing and storing pins (the builder side). The engine
  contract is here; a test-run endpoint / MCP parameter that supplies
  `context.pins` layers on top.

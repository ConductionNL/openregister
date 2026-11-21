# Proposal: or-flow-sub-flow

## Summary

A `openregister.sub-flow` node that runs another flow as a step — n8n's "Execute
Sub-workflow", closing one of the named gaps in #2067.

## Why

Parity, and reuse. A flow that is worth authoring once (enrich an address,
score a lead, notify a channel) should be callable from every other flow rather
than copied into each. n8n has this; without it the fleet's flows cannot be
composed, only duplicated. The run infrastructure to do it now exists
(`FlowRunService.queue`/`execute`, the resolver registry), so the node is small.

## What Changes

- **`SubFlowNode`** (`openregister.sub-flow`), a built-in node with two shapes:
  - **Wait** (default): resolve the named flow, run it *now* seeded with this
    step's items, and return its output items — the sub-flow is a function the
    parent calls and reads. The sub-run is a persisted `FlowRun` with its own
    trace, so it is as inspectable as a top-level run. A sub-run that does not
    complete cleanly raises, so it reaches the parent step's `onError` policy
    exactly as inline work would.
  - **Fire-and-forget** (`wait: false`): queue the named flow against the run's
    subject and carry on; the parent's items pass through untouched.
- Which flow is just an id resolved through the same `FlowResolverRegistry` the
  trigger side uses — so a sub-flow can live in any consuming app (openconnector
  → a hermiq agentflow, procest → an openconnector flow).
- **Recursion guards**: the run carries the stack of flow ids it is inside
  (`context.flowStack`). A sub-flow already on the stack is refused (a flow
  cannot call itself round a cycle), and a depth ceiling (16) backstops a chain
  that grows without repeating. Both fail the step, not the process.

## Out of scope

- Passing an explicit subject or a mapped input to the sub-flow (today the
  waited sub-flow is seeded with the parent's items; the fired one runs against
  the parent's subject). Input mapping is a later refinement.
- A visual "open sub-flow" affordance in the builder — this change is the engine
  node; the builder can add navigation on top of it.

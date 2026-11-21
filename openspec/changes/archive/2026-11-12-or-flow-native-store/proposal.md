# Proposal: or-flow-native-store

## Summary

Let a flow live in OpenRegister itself. OpenRegister owns the flow engine but had
no resolver of its own, so a flow could only be stored and run by a consuming app
(hermiq's agentflows). This adds `OpenRegisterFlowResolver`, which resolves flows
stored as ordinary OpenRegister objects — so the engine, triggers, sub-flows and
the test run all work with an OpenRegister-authored flow exactly as with a leaf
app's.

## Why

"OpenRegister is the fleet's one flow engine" was only half true: you could run
the engine, but you could not author a flow *in* OpenRegister and have anything
resolve it. Every other surface (the `/test` endpoint, the trigger listener,
the sub-flow node) resolves a flow id through the resolver registry, and that
registry had exactly one contributor — hermiq. This closes the gap so
OpenRegister is a first-class flow author, not only the runtime.

## What Changes

- **`OpenRegisterFlowResolver`** (registered through `RegisterFlowResolversEvent`,
  the same event hermiq uses):
  - `resolveFlow` loads a flow object and returns its `{nodes, edges, limits}`. An
    object in the flow store that is not shaped like a flow (no nodes/edges) is
    not treated as one.
  - `resolveSubject` loads the object a run is about.
  - `flowsForTrigger` lists the enabled flows wired to a fired event, scoped to
    the flow store, matching the flow's `trigger` and optional
    `triggerRegister`/`triggerSchema`.
- **The flow store is configuration**: `flow_register` / `flow_schema` app-config
  keys name where flows live, defaulting to the `flows` register and `flow`
  schema. Absent that store, every method resolves to nothing — the resolver
  never claims a flow it does not own, so it composes cleanly with hermiq's.

## Enabling it

Create a register and schema for flows (an object with `nodes`, `edges`, and —
for triggering — `enabled`, `trigger`, optional `triggerRegister`/`triggerSchema`),
then point `flow_register` / `flow_schema` at them (or name them `flows` / `flow`
to use the defaults). A flow is then just an object; the run history, retry, pins,
run-from-here and the `/test` endpoint all already work with it.

## Out of scope

- Shipping and force-importing a canonical `flow` schema on install. The resolver
  is deliberately store-agnostic (any register/schema, by config) so this does not
  depend on the register-import machinery; a bundled schema can follow.
- A flow-authoring UI. Flows are objects, so the existing object editor already
  edits them; a purpose-built canvas is a separate concern.

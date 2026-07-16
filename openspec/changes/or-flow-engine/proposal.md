# Proposal: or-flow-engine

## Summary

Give OpenRegister a real flow engine, so the fleet has **one** instead of five
things called "flow". The execution core is `symfony/workflow` (a Petri net);
openconnector's run lifecycle is ported on top. Leaf apps become consumers.

Governed by ADR-065 (hydra: `openspec/architecture/adr-065-flow-engine-and-canvas.md`).

## Why

- **Five unrelated systems use the word "flow"** — the NC Flow leaf,
  `x-openregister-flows`, openconnector's `flow`, procest's `workflowTemplate`,
  and openbuild's `Automation`. Every app that wants richer flow editing is
  currently a candidate to grow its own engine: the exact defect ADR-022 exists
  to prevent.
- **No fleet engine can express parallel work.** openconnector's model is a
  linear list where `order` is identity, sequence, and the implicit edge set at
  once; its spec rules out parallel/fan-out explicitly. procest's engine never
  walks a graph at all. A canvas invites users to draw a fork the moment they
  see one.
- **A Petri net is a superset of both models we already have**, so this unifies
  execution without forcing a state machine and an action pipeline into one
  shape. Verified by running it, not from package metadata.
- **Nothing viable exists to buy for the rest.** There is no PHP DMN engine (the
  only Packagist hit is an abandoned 2019 *writer*), no PHP FEEL parser at all,
  and no PHP CMMN anything. `symfony/workflow` is the one maintained,
  MIT, `php >=8.1` component that does what the execution core needs.

## What Changes

- Add `symfony/workflow: ^6.4`. OpenRegister already requires `symfony/*` at
  `^6.4` and already vendors `deprecation-contracts` and `event-dispatcher`, so
  this adds no new class of vendor-shadowing risk; NC core ships Symfony 6.4.x
  and no `symfony/workflow`, so there is nothing to shadow.
- `FlowDefinitionBuilder` — translates a stored flow document into a Petri-net
  `Definition`. Nodes become places; edges become transitions; multi-endpoint
  edges become splits and synchronising joins.
- `FlowEngine` — runs a flow, providing everything Symfony does not: run
  lifecycle, append-only trace, per-step `onError`, and a loop ceiling.
- `FlowStepDispatcher` — the seam between *when* a step runs (engine) and *what
  it does* (app), so the engine is container-free testable and consumers add
  step types without touching it.

## Out of scope (this change)

- **Relocating openconnector's `flow` schema and `FlowRunnerService`.** The
  engine core lands first; the migration is its own change, and its sharpest
  edge — `order` is referenced *by value* by `branches[].nextStepOrder`, so it
  cannot survive a canvas unchanged — deserves separate treatment.
- **Persisting the marking to an OR object.** The engine takes a
  `MarkingStoreInterface`; the OR-backed store lands with the relocation.
- **DMN/CMMN interchange** — parked, openregister#466.
- **A conformant FEEL implementation** — explicitly not planned.

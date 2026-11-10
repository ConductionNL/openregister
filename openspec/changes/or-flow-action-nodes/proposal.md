---
kind: code
---

# Proposal: or-flow-action-nodes

## Summary

Invert the flow authoring model: a **node is an action** and carries the step's
`type` and `config`; an **edge is sequence** and says only what runs next. The
Petri net stops being the authoring format and becomes what it always should
have been — an internal representation the engine lowers to.

This does not weaken the engine. Joins, splits and parallelism are all still
expressible, because the lowering is total. What changes is that authors stop
hand-writing the compiler's intermediate representation.

## Why

The current model is the inverse: `FlowDefinitionBuilder::extractPlaces()`
maps each node to a Petri-net place and each edge to a transition, and it
**throws** on a node carrying `type` or `config`:

> Flow node "%s" carries "type"/"config", which the engine never reads — a node
> is a place. Move the step onto the edge that leaves it.

That throw is not paranoia. It was added because three fleet graphs were
authored node-shaped, ran, reported COMPLETED, and did nothing at all — an
untyped transition dispatches to nothing and returns its items untouched. The
builder's own comment names the cause precisely:

> Node-shaped authoring is the natural mistake, **because that is how a graph
> editor presents a flow**.

The comment diagnoses the defect and then declines to treat it. Every flow tool
in existence — n8n, Camunda's modeler, Node-RED, GitHub Actions' graph view —
puts the work on the node, because that is how people read a diagram: a box is
a thing that happens, an arrow is what happens next. An engine whose authoring
format contradicts that universal reading will keep producing this mistake for
as long as it exists, and each occurrence is a flow that silently does nothing.

It also has a concrete, measured cost in the product. Hermiq's builder read
`nodes[].type` and `edges[].source`/`.target`. Neither key exists in a stored
flow, so on the live Hydra sequencer (17 nodes, 16 edges) the canvas drew
**zero** edges and rendered every node's label as an em-dash — a page of blank,
disconnected boxes over a flow the engine runs correctly every five minutes.
Nothing errored, because a dropped edge and an absent key are both silent.

## What Changes

- **`FlowDefinitionBuilder` lowers instead of mapping.** An action node becomes
  a `Transition` carrying its step; the edge between two nodes becomes the place
  they share. See `design.md` for the lowering table and its proof obligations.
- **`FlowEngine::stepFor()` resolves a transition to its NODE**, not to an entry
  in `edges[]`. `RegistryStepDispatcher` reads `type`/`config` off that node.
- **`FlowNodePreflight` validates node configs.** It currently walks
  `edges[].type` and calls each step's `validateConfig()`; it must walk nodes.
  The unknown-config-key check (`IFlowNodeConfigKeys`) moves with it.
- **The old shape is REFUSED, not accepted alongside.** There is exactly one
  authoring model. A document carrying `edges[].type` is rejected by name with
  a message pointing at the migration, never silently reinterpreted.
- **`openspec/specs/flow-engine/spec.md` is rewritten**, not patched — REQ-FE-002
  ("each flow node to a place and each flow edge to a transition") is the
  requirement being inverted.

## What does NOT change

- `symfony/workflow` stays the execution core. ADR-065 Decision 2 is untouched.
- Joins, splits, parallel markings, suspension/resume, `onError` policies, the
  run log and the marking store are all unaffected — they operate on
  transitions and places, which still exist.
- `CnGraphCanvas` needs no change. It already speaks `{id, source, target}`,
  which is what an authoring edge is under this model.

## Why not accept both shapes

Because that reintroduces the exact defect the throw exists to prevent. A
document with typed edges AND typed nodes has no correct interpretation, and a
half-migrated document — typed nodes, but one edge left typed — would run, skip
the step nobody claimed, and report success. Dual support turns a loud
migration into a silent data-dependent one.

So: one model, an explicit migration (`or-flow-migrate-definitions`), and any
document that does not match is refused by name.

## Impact

- **Affected specs**: `flow-engine` (rewritten), `flow-storage` (node/edge shape)
- **Affected code**: `lib/Service/Flow/FlowDefinitionBuilder.php`,
  `FlowEngine.php`, `FlowNodePreflight.php`, `RegistryStepDispatcher.php`
- **Affected data**: every stored flow. Migration is `or-flow-migrate-definitions`
  and MUST land before this reaches an instance with flows on it.
- **Affected apps**: hermiq (`hermiq-flow-canvas-ports`), openconnector, procest —
  all consume the engine and none author flows outside it.
- **ADR-065**: unaffected in substance. It never specified node-as-place; that
  rule came from this repo's `flow-engine` spec. Worth noting the ADR is still
  unmerged on `docs/adr-065-flow-engine-and-canvas` and says
  "Accepted — partially implemented".

## Capabilities

### Modified Capabilities
- `flow-engine` — the authoring model inverts; the lowering is new behaviour

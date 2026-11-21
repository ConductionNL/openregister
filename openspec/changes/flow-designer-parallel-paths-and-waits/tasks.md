# Tasks: flow-designer-parallel-paths-and-waits

## 1. Editor

- [ ] 1.1 The flow editor shows a node with two or more incoming edges with a "Wait for all paths" toggle that writes `join: true`, and draws a joined node so it reads differently from a merging one. Verify: component test on the node config form and the node badge.
- [ ] 1.2 The await-signal node is in the palette with its signal key and the routes it can continue on. Verify: component test that the palette offers it and the form saves its config.
- [ ] 1.3 A flow imported from BPMN opens on the canvas with its split, its join and its waits as nodes and edges, and saves through the same path as a drawn flow. Verify: e2e that imports a BPMN file with a parallel gateway pair, opens it, moves a node and saves.
      > 2026-11-13: the import half is built. A `parallelGateway` used to be refused and dropped, so a split/join model lost both paths' meeting point; it now imports as `openregister.merge`, with `join: true` where two or more flows enter (`FlowBpmnImporter::withJoins()`, `ParallelGatewayImportTest`). The e2e that opens, moves and saves it is still to write.

## 2. Run as drawn

- [ ] 2.1 A flow drawn with a split, a joined node and a wait runs to its end only after both paths finish and the signal arrives. Verify: e2e that draws the flow, starts a run, sends the signal and reads the run page.

## 3. Docs

- [x] 3.1 `docs/` section on drawing parallel paths and waits, with the BPMN import as the second route.

> 2026-11-13: 1.1 and 1.2 are canvas and palette work in `@conduction/nextcloud-vue`, not in this repository; the server already serves `openregister.await-signal` with its form (`AwaitSignalNode::configForm()`). 2.1 is an e2e. Docs: `docs/features/flow-parallel-paths-and-waits.md`.

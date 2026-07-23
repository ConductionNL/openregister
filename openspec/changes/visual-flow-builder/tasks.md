# Tasks — visual-flow-builder

## Phase 1 — Visual builder MVP (existing contract)
- [ ] 1.1 `CnFlowCanvas.vue` (lib): render `x-openregister-flows` as trigger + action nodes on `CnGraphCanvas`; edges = action order; `#node` slot per kind.
- [ ] 1.2 Node palette (drag-to-add trigger/action nodes) via `@canvas-drop`; `@node-move`/`@connect` update local graph; delete node/edge.
- [ ] 1.3 Per-node config panel (trigger: created/updated/deleted; actions: existing email/calendar/agent/federate fields).
- [ ] 1.4 Graph ⇄ `{ name, trigger, actions[] }` serializer (walk trigger→actions chain; persist node positions in a round-tripped `_layout`).
- [ ] 1.5 `CnFlowCanvasModal.vue` (lib): load `GET /schemas/{id}` per app register/schema, host `CnFlowCanvas`, save `PATCH /schemas/{id}` (`x-openregister-flows`); mirror `CnEditFlowsModal` load/save.
- [ ] 1.6 Point `CnOpenBuildEditButton` "Edit flows…" at `CnFlowCanvasModal` (keep form editor behind a Form/Canvas toggle).
- [ ] 1.7 Build procest vs LOCAL_LIB, deploy 8090, live-test create + edit + reload a flow on `/cases`.

## Phase 2 — Event catalog (object-CRUD → all Nextcloud events)
- [ ] 2.1 Event catalog service + `GET /api/flow/event-catalog` (sources: object.*, file.*, share.*, user.*, group.*, calendar.* …), each with an id, label, and payload→object resolver.
- [ ] 2.2 Generic `EventCatalogListener` subscribing catalog events → `FlowActionService::run(object, trigger=<catalog id>)`; keep `FlowActionListener` for object-CRUD back-compat (bare created/updated/deleted still match).
- [ ] 2.3 Builder trigger node offers the catalog (grouped by source); back-compat: object-CRUD triggers persist as bare `created/updated/deleted`.

## Phase 3 — Nextcloud Flow interoperability (both directions)
- [ ] 3.1 `GET /api/flow/nc-operations`: list registered `workflowengine` operations (id, name, icon).
- [ ] 3.2 `nc-flow-operation` action runner in `FlowActionService`: invoke the selected NC Flow operation for the object; builder palette exposes NC operations as blocks.
- [ ] 3.3 `Flow/OpenRegisterFlowOperation` implementing `OCP\WorkflowEngine\IOperation`: register each OpenRegister flow as a native Flow operation so a NC Flow rule can invoke it; map the rule's entity to an OR object.
- [ ] 3.4 Round-trip test: NC Flow rule → OR flow, and OR flow → NC operation.

## Phase 4 — Object-CRUD action nodes
- [ ] 4.1 Action runners: `object.create`, `object.update`, `object.delete`, `object.set-field`, `condition` (guard) in `FlowActionService::runAction()` (loop/recursion guarded).
- [ ] 4.2 Builder config panels + palette entries for the object actions (target register/schema, field mapping, condition expression).

## Phase 5 — Release
- [ ] 5.1 Publish lib (bump vue3 tag) + procest dep; prod build.
- [ ] 5.2 Deploy to 8080; live-verify on `/cases`.
- [ ] 5.3 `openspec verify` + archive.

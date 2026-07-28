# Tasks — visual-flow-builder

## Phase 1 — Visual builder MVP (existing contract)
- [x] 1.1 `CnFlowCanvas.vue` (lib): render `x-openregister-flows` as trigger + action nodes on `CnGraphCanvas`; edges = action order; `#node` slot per kind.
- [x] 1.2 Node palette (drag-to-add trigger/action nodes) via `@canvas-drop`; delete node/edge.
- [x] 1.3 Per-node config panel (trigger: created/updated/deleted; actions: existing email/calendar/agent/federate fields). Fixed an `NcTextField` `useModel` binding bug (kebab `@update:model-value` silently dropped edits → camelCase).
- [x] 1.4 Graph ⇄ `{ name, trigger, actions[] }` serializer (walk trigger→actions chain; persist node positions in a round-tripped `_layout`).
- [x] 1.5 `CnFlowCanvasModal.vue` (lib): load `GET /schemas/{id}` per app register/schema, host `CnFlowCanvas`, save `PATCH /schemas/{id}` (`x-openregister-flows`); mirror `CnEditFlowsModal` load/save.
- [x] 1.6 Point `CnOpenBuildEditButton` "Edit flows…" at `CnFlowCanvasModal` (form editor kept behind "Use the form editor instead").
- [x] 1.7 Build procest vs LOCAL_LIB, deploy 8090, live-verified create + edit + add action + save + reload round-trip (incl. node layout) on `/cases`.

## Phase 2 — Event catalog (object-CRUD → richer lifecycle events)
- [x] 2.1 `EventCatalogService` + `GET /api/flow/event-catalog`. Delivered the object-lifecycle events OpenRegister actually dispatches: `object.created/updated/deleted` (with legacy aliases) plus `object.locked/unlocked/reverted/transitioned`. Every catalog id is a real, dispatched, object-carrying event (no declared-but-never-fired triggers). File/share/user events are a future additive extension via the same catalog.
- [x] 2.2 `EventCatalogListener` routes the non-CRUD lifecycle events → `FlowActionService::run(object, <catalog id>)`; `FlowActionListener` keeps create/update/delete (no double-fire). `FlowActionService` matches via `EventCatalogService::aliasesFor()` so bare `created/updated/deleted` still fire.
- [x] 2.3 Builder trigger palette + "When" select driven by the fetched catalog; falls back to legacy triggers offline; legacy `created` flows still display/edit.

## Phase 3 — Nextcloud Flow interoperability
- [~] 3.1/3.2 **Deferred (not buildable honestly).** `OCP\WorkflowEngine` exposes no public "invoke operation" API — operations only run via `onEvent()` driven by the engine's rule-matcher. A `nc-flow-operation` action that "invokes the selected NC operation" would be a dead block, so it is intentionally not built.
- [x] 3.3 `WorkflowEngine/RegisterObjectEntity` (`IEntity`) registers OR objects as a Flow entity (object created/updated/deleted triggers); `WorkflowEngine/RunFlowOperation` (`ISpecificOperation`) registers "Run an OpenRegister flow" so a Flow rule can run a named flow gated behind Flow's checks. `FlowActionService::runNamedFlow()` runs one flow by name. Live-verified: both appear in the Flow admin UI.
- [x] 3.4 Full rule→fire→action e2e live-verified: a global Flow rule (OR object updated → Run flow "NC stamp", whose own trigger is `object.locked` so the native listener can't fire it) stamped the object's title on update — proving the NC Flow path ran the OR flow.
- [ ] 3.5 Follow-up: register a frontend operator-settings component (`window.OCA.WorkflowEngine.registerOperator`) so the flow-name value is enterable in the Flow admin UI (today it is set via the workflows API). Operation is registered, visible, and fires; only the value-input UI is missing.

## Phase 4 — Object-CRUD action nodes
- [x] 4.1 `object.set-field`/`object.update`, `object.create`, `object.delete`, and `condition` (guard, halts on false) in `FlowActionService::runAction()` (returns bool; loop breaks on a failed condition). Recursion guarded via an `activeObjects` UUID set. Live-verified: an `updated` flow set a Case title, persisted, did not loop.
- [x] 4.2 Builder palette + config panels for the object actions (register/schema, field/value, target UUID, operator) + node subtitles + icons.

## Phase 5 — Release
- [ ] 5.1 Reconcile branches with development (nc-vue + procest merge; openbuild cherry-pick); publish lib (bump vue3 tag) + procest dep; prod build.
- [ ] 5.2 Deploy to 8080; live-verify on `/cases`.
- [ ] 5.3 `openspec verify` + archive; push everything to development.

# Tasks — flow-engine-unification

## Phase 1 — Native flow storage (OpenRegister)
- [ ] 1.1 Migration: `oc_openregister_flows` (uuid, name, description, app, enabled, trigger, trigger_register, trigger_schema, cron, execution_mode, nodes, edges, limits, retention_days, owner, organisation, notes, created, updated) + indexes on (app), (trigger, enabled), (organisation), (uuid).
- [ ] 1.2 Migration: `oc_openregister_flow_run_steps` (run_uuid, flow_id, node_id, node_type, sequence, status, started, finished, duration_ms, output, error) + indexes on (run_uuid, sequence), (node_type, status), (flow_id).
- [ ] 1.3 `lib/Db/Flow.php` + `lib/Db/FlowMapper.php` — organisation-scoped queries, `app` filter, `findByTrigger`.
- [ ] 1.4 `lib/Db/FlowRunStep.php` + `lib/Db/FlowRunStepMapper.php` — `findByRun`, `findByNodeType`, `deleteOlderThan`.
- [ ] 1.5 `lib/Service/Flow/FlowService.php` — the surface apps consume (find/findAll/save/delete/run), owner + organisation stamping, ownerless-flow dispatch refusal.
- [ ] 1.6 `FlowController` CRUD + `/api/flows/{id}/run`; per-flow guard on run/update/delete (do not repeat the `retry()` IDOR, or#2290).
- [ ] 1.7 Point `OpenRegisterFlowResolver` at `FlowMapper`; delete `IFlowResolver`, `FlowResolverRegistry`, `RegisterFlowResolversEvent`.
- [ ] 1.8 Enforce namespaced node ids end to end: an unresolvable node type fails its step visibly instead of being skipped.

## Phase 2 — Execution history + retention
- [ ] 2.1 `FlowEngine`/`FlowRunService` write a step row per node execution (output, error, timing, sequence); appended, not replaced, across a resume.
- [ ] 2.2 `flow_run_retention_days` admin app setting, default 31; `retention_days` per-flow override honoured shorter **and** longer.
- [ ] 2.3 `FlowRunRetentionJob` daily `TimedJob` sweeping runs + their step rows (model on `ExecutionHistoryCleanupJob`).
- [ ] 2.4 Admin settings UI section for flow log retention (admin only — not personal settings).

## Phase 3 — Remove the old engines
- [ ] 3.1 Delete `FlowActionService`, `FlowActionListener`, `EventCatalogListener`'s dispatch into it, `WorkflowEngine/RunFlowOperation`.
- [ ] 3.2 Delete `CnEditFlowsModal` + its OpenBuild wiring; ensure `CnFlowCanvas`/`CnFlowCanvasModal` on `codeberg/feat/vue-3` are not merged to `beta`.
- [ ] 3.3 Delete hermiq `Service/Graph/GraphExecutor`, `Controller/GraphController`, `Flow/HermiqFlowResolver` + listener, `AgentFlow`/`AgentFlowRun` schemas. Keep `HermiqAgentNode`, `HermiqWorkloadNode`, `HermiqFlowNodeListener`.
- [ ] 3.4 Port GraphExecutor's advances into the engine as ENGINE-WIDE but OPTIONAL behaviour (admin default + nullable per-flow override, mirroring retention):
  - [ ] 3.4a Per-hop audit trail for every node type. `flow_audit_enabled` default **off** (write volume; step rows already carry operational history).
  - [ ] 3.4b `RegisterFlowOversightEvent` + `IFlowOversightCheck`: apps contribute checks the way they contribute nodes. The engine hardcodes none.
  - [ ] 3.4c Oversight gate consulted before each hop. `flow_oversight_enabled` default **on** (a safety rail that defaults off protects only the flows someone configured). A veto STOPS the run with its reason; a check that throws refuses rather than failing open.
  - [ ] 3.4d hermiq's kill-switch and budget become registered checks, not engine branches.
  - [ ] 3.4e State-delta trace onto the step row's `output`.
- [ ] 3.5 Retire the `visual-flow-builder` change and `flow_register.json` + `ImportFlowRegister`.

## Phase 4 — `x-openregister-flows` redefinition
- [ ] 4.1 Import path: a schema's `x-openregister-flows` seeds engine flows into `oc_openregister_flows` on register import, scoped to declaring app + schema.
- [ ] 4.2 Amend ADR-031: add `x-openregister-flows` to the extension table; record the action-list dialect as removed.

## Phase 5 — Shared UI (`@conduction/nextcloud-vue`, branch `beta`)
- [ ] 5.1 `useFlowStore` (from hermiq `graphEditor.js`), talking to `/api/flows`.
- [ ] 5.2 `CnFlowDetail` + `CnFlowSidebar` (from `GraphBuilder.vue`/`GraphSidebar.vue`) on `CnGraphCanvas`; config panes keyed by catalogue id.
- [ ] 5.3 `CnFlowIndexPage` — index over `/api/flows` with an `app` prop.
- [ ] 5.4 `CnFlowEditModal` — `NcDialog size="full"` wrapping the same `CnFlowDetail` internals.
- [ ] 5.5 Run/trace panel reads step rows, so a past run is inspectable per node.

## Phase 6 — Consumers
- [ ] 6.1 OpenRegister: `/flows` + `/flows/:id` pages, menu entry, no app filter.
- [ ] 6.2 OpenConnector: `/flows` + `/flows/:id` pages, menu entry, `app=openconnector`.
- [ ] 6.3 hermiq: `/graphs` → `/flows` on the shared components, `app=hermiq`, old route kept as alias.
- [ ] 6.4 OpenBuild: "Edit flows…" opens `CnFlowEditModal`.

## Phase 7 — Verification
- [ ] 7.1 `composer check:strict` green in openregister and hermiq.
- [ ] 7.2 Hydra gates green (spec-coverage, route-auth, no-admin-idor, modal-isolation, redundant-controller).
- [ ] 7.3 Live UI verification per surface in a fresh browser context — API-green is not UI-green.
- [ ] 7.4 Positive control on the node-dispatch fix: a flow authored from the palette must change observable state, proving the run is not a no-op.

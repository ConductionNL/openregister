---
kind: code
---

## Why

The fleet has **three** unrelated things called "flow", two engines that cannot run
each other's documents, and a builder whose runs are a silent no-op.

**1. Two engines.** `FlowEngine` (`lib/Service/Flow/`) walks a node/edge graph with
13 registry-driven node types, sub-flows, wait/suspension, flow state and persisted
runs. `FlowActionService` (787 lines) reads a schema's `x-openregister-flows` and
executes a flat `{ name, trigger, actions[] }` list. They share a namespace, a
directory and nothing else.

**2. A third engine in a leaf app.** hermiq ships `Service/Graph/GraphExecutor`
(727 lines) — a fourth node vocabulary, walking `agentflow` objects out of hermiq's
own register via its own `HermiqFlowResolver` and its own `GraphController`. Apps are
not supposed to own flow services (ADR-022).

**3. That leaf engine's runs do nothing, and report success.** hermiq's palette is
OpenRegister's node catalogue and *nothing else* (`GraphSidebar.vue:258`), so every
node placed on the canvas carries a namespaced id — `openregister.set-fields`,
`hermiq.agent-step`. `GraphExecutor::runNode()` switches on **bare** ids —
`condition`, `router`, `agent-step`, `object-write` — and strips no namespace. Every
palette node therefore falls to `default:`, is logged at `info` as "unknown node
type; skipped", and the walk continues. `POST /apps/hermiq/api/graph/run` returns 200
with a trace for a graph that executed zero steps. The same mismatch makes
`GraphBuilder`'s per-node config panes dead for every catalogue node.

**4. Flow definitions are the only part of the engine that is not a real table.**
Runs (`oc_openregister_flow_runs`), links (`_flow_links`) and state (`_flow_state`)
are already native. Only the definition is an OpenRegister object, which forces the
`IFlowResolver` indirection whose entire purpose is to paper over "flows live in a
different register per app".

**5. Execution history is not queryable.** A run's per-node detail is a `log` JSON
blob on the run row. "Which node failed, on which runs, with what output" cannot be
answered without loading and walking every blob, and nothing ever prunes them.

## What Changes

- **ADD** `oc_openregister_flows` as the single flow store — a native table, not an
  OpenRegister object, and not a register/schema abstraction. An `app` column scopes
  a flow to its owning Nextcloud app; `owner` and `limits` carry over from hermiq's
  `agentflow` (a flow with no owner MUST NOT dispatch; `maxNodes`/`maxIterations`
  bound a walk).
- **ADD** `oc_openregister_flow_run_steps` — one row per node execution, carrying
  node id, type, sequence, status, timing, output and error. Per-node results and
  failures become queryable instead of buried in a JSON blob.
- **ADD** flow-log retention: an **admin** app setting `flow_run_retention_days`
  (default 31), overridable per flow in either direction (shorter or longer), swept
  by a daily `FlowRunRetentionJob`.
- **ADD** `FlowService` — the PHP surface apps hook into directly, the way they
  already consume `ObjectService`. Apps contribute node types through the existing
  `RegisterFlowNodesEvent`; they do not own flow services, controllers or stores.
- **CHANGE** `x-openregister-flows` to mean flows of the **new** type. It becomes the
  declarative way an app ships flows in its register file, imported into
  `oc_openregister_flows`. ADR-031 is amended to document it (it lists six
  `x-openregister-*` extensions today and `-flows` is not among them — the key was
  introduced by `FlowActionService` without an ADR).
- **REMOVE** the old engine outright: `FlowActionService`, `FlowActionListener`,
  `EventCatalogListener`'s dispatch into it, `WorkflowEngine/RunFlowOperation`, and
  `CnEditFlowsModal`. Nothing runs in production, so no data migration is required.
- **REMOVE** hermiq's duplicate backend: `GraphExecutor`, `GraphController`,
  `HermiqFlowResolver` (+ listener), and the `AgentFlow`/`AgentFlowRun` schemas.
  `HermiqAgentNode`, `HermiqWorkloadNode` and `HermiqFlowNodeListener` stay — node
  contribution *is* the supported way to hook in.
- **REMOVE** the `IFlowResolver` / `FlowResolverRegistry` / `RegisterFlowResolversEvent`
  indirection. One store needs no resolution.
- **ADD** shared authoring UI to `@conduction/nextcloud-vue`, promoted from hermiq's
  builder (the fleet's most advanced): `CnFlowIndexPage`, `CnFlowDetail`,
  `CnFlowSidebar`, `useFlowStore`, and `CnFlowEditModal` (`NcDialog size="full"`)
  wrapping the same detail internals. The bare-vs-namespaced node id mismatch is
  fixed in the port.
- **ADD** consumers: OpenRegister `/flows` (all flows), OpenConnector `/flows`
  (`app=openconnector`), hermiq `/graphs` → `/flows` (`app=hermiq`, old route kept as
  a deep-link alias), and OpenBuild's "Edit flows…" opening `CnFlowEditModal`.

## Impact

**Supersedes `visual-flow-builder`.** That change builds a canvas on the dialect this
one deletes. Its Phase 1 artefacts (`CnFlowCanvas`, `CnFlowCanvasModal`) exist only on
the unmerged `codeberg/feat/vue-3` branch despite being ticked `[x]`; they must not be
merged to `beta`.

**Breaking, deliberately.** Any flow authored against the old dialect stops working.
Confirmed acceptable: the old engine is not in production.

**Security.** Moving definitions off OpenRegister objects drops per-object RBAC and
grants. Flows write objects and run agents, so the new table carries `owner` +
`organisation`, mapper queries are organisation-scoped, and `run`/`update`/`delete`
take an explicit per-flow guard. `FlowRunController::retry()` in this exact area was
an open IDOR (or#2290); this change does not repeat it.

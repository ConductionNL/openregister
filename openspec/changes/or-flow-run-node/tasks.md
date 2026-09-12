# Tasks: or-flow-run-node

**RN-1 DECIDED 2026-09-12 (design.md): Ruben chose (c)** — opt-in node
(`IFlowDirectlyInvokable`) AND the subject's existing object-RBAC `update`
permission, both required, `flow.run` consulted for neither half.
Implementation below.

- [x] 1. `IFlowDirectlyInvokable` marker interface in
  `lib/Service/Flow/IFlowDirectlyInvokable.php`, following the
  `IFlowNodeConfigKeys` / `IFlowNodeConfigForm` precedent (optional interface,
  not added to `IFlowNode` itself — see that interface's own docblock for why).
- [x] 2. `POST /api/flows/{flowId}/nodes/{nodeId}/run` route + controller
  method (`FlowNodeRunController::run()`, a controller separate from
  `FlowController` — its authorization shape has nothing in common with flow
  CRUD/catalogue): resolves the flow and node, 404s when the node type does
  not implement `IFlowDirectlyInvokable`, authorizes against the subject via
  `PermissionHandler::hasPermission()` (the same seam `object-op`
  patch/create already goes through), runs the one node via
  `FlowRunService::executeNode()` — the "run exactly one node, not from here
  to the end" mode this task asked for. It does NOT reuse `FlowEngine`'s
  `startAt`/graph walk at all: it dispatches the named step directly through
  `RegistryStepDispatcher`, the same way the engine would for that one hop,
  so routing to whatever the node points at in its authoring graph is
  structurally impossible rather than merely unauthorized (proven by
  `FlowRunServiceExecuteNodeTest::testExecuteNodeDoesNotRunDownstreamNodes`,
  a two-node graph with a real edge between them). Persists a `FlowRun`
  scoped to the one node either way (COMPLETED or FAILED).
  - unit tests (`FlowNodeRunControllerTest`, `FlowRunServiceExecuteNodeTest`):
    opted-out node 404s; opted-in node without subject permission 403s;
    opted-in node with subject permission runs and returns a `FlowRun`;
    `flow.run` alone (no subject permission) still 403s (this controller
    never consults `flow.run`/`FlowAccess` at all — a dedicated test pins
    that down so a future shortcut is caught); a node id not in the
    published graph fails the run; a suspending node fails the run
    (unsupported, stated); a non-QUEUED run is not double-executed.
  - mutation-checked: the 404 opt-in guard, the 403 subject-permission guard,
    the not-QUEUED guard, and the no-downstream-routing guarantee — each
    broken, confirmed the RIGHT test reddens, restored. See PR for the
    before/after runs.
- [x] 3. Expose the node's `configForm()` (when implemented) resolvable by
  `{flowId}/{nodeId}`, per RN-2 — `GET /api/flows/{flowId}/nodes/{nodeId}/run`
  (`FlowNodeRunController::form()`), gated on the same opt-in half of RN-1,
  not on subject permission (no subject on a GET).
  - unit test: a node with a `select` + `optionsFrom` field returns that
    field unchanged; a node with no `IFlowNodeConfigForm` returns a body with
    NO `configForm` key (existing "absent, not empty" convention from
    `FlowNodeRegistry::palette()`, not a new failure mode).
- [ ] 4. `composer check:strict` exits 0.
- [x] 5. `@spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md`
  on every changed/added method.

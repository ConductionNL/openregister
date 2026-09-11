# Tasks: or-flow-run-node

**BLOCKED on RN-1 (design.md): Ruben has not yet chosen the authorization
shape.** No task below is started. Do not begin implementation until RN-1 is
resolved — see design.md for the three options and the recommendation.

- [ ] 1. `IFlowDirectlyInvokable` marker interface in
  `lib/Service/Flow/IFlowDirectlyInvokable.php`, following the
  `IFlowNodeConfigKeys` / `IFlowNodeConfigForm` precedent (optional interface,
  not added to `IFlowNode` itself — see that interface's own docblock for why).
- [ ] 2. `POST /api/flows/{flowId}/nodes/{nodeId}/run` route + controller
  method: resolves the flow and node, 404s when the node type does not
  implement `IFlowDirectlyInvokable`, authorizes per RN-1's resolved shape,
  runs the one node via the engine (reusing `FlowEngine`'s `startAt` support
  from `or-flow-partial-run`, scoped to run only the named node rather than
  the whole downstream graph — needs an engine-level "run exactly one node,
  not from here to the end" mode, distinct from `startAt`'s "from here
  onward"), and persists a `FlowRun`.
  - unit tests: opted-out node 404s; opted-in node without subject permission
    403s; opted-in node with subject permission runs and returns a `FlowRun`;
    `flow.run` alone (no subject permission) still 403s.
  - mutation-check both the 404 guard and the 403 guard: break each,
    confirm the RIGHT test reddens, restore.
- [ ] 3. Expose the node's `configForm()` (when implemented) resolvable by
  `{flowId}/{nodeId}`, per RN-2.
  - unit test: a node with a `select` + `optionsFrom` field returns that
    field unchanged; a node with no `IFlowNodeConfigForm` returns an empty
    form (existing fallback behaviour, not a new failure mode).
- [ ] 4. `composer check:strict` exits 0.
- [ ] 5. `@spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md`
  on every changed/added method.

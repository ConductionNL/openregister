# Tasks: or-flow-preflight

- [x] `FlowNodePreflight` — structural flow recognition, per-edge type resolution
      against the live registry, ownership-based classification.
- [x] `FlowNodePreflightListener` on `ObjectCreatingEvent` / `ObjectUpdatingEvent`,
      registered ahead of the other object listeners in `Application.php`.
- [x] `POST /api/flow/validate` on `FlowController` + route.
- [x] Unit tests for the classifier, the recognition guard and the listener.
- [x] Regression test replaying or#2247 through real collaborators, with a
      positive control proving the same document saves once the node exists.
- [x] `IFlowNodeConfigKeys` — the optional contract by which a node states its
      whole config vocabulary, plus the unknown-key check in the preflight and
      the `$`-prefixed annotation exemption. Separate interface, not a new method
      on `IFlowNode`: openconnector and hermiq implement `IFlowNode` from their
      own repositories and would fatal on load.
- [x] `configKeys()` on all thirteen in-tree nodes, with a ratchet test that
      every registered OpenRegister node declares one and that every declared key
      appears in that node's own source.
- [x] `configKeys` served on `GET /api/flow/node-catalog`, so hydra's
      `scripts/test-flow-definitions.sh` can consume the vocabulary instead of
      keeping a second hand-maintained table.
- [ ] hydra: replace the `NODE_KEYS` table in `scripts/test-flow-definitions.sh`
      with the node-catalog payload. Out of scope here — the OpenRegister side is
      what makes it possible, and it is a change in another repository.
- [ ] Frontend: surface `blocking` / `warnings` in the flow editor before save.
      Out of scope here — the backend refusal is what stops a half-run.

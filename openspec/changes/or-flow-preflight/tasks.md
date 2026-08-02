# Tasks: or-flow-preflight

- [x] `FlowNodePreflight` — structural flow recognition, per-edge type resolution
      against the live registry, ownership-based classification.
- [x] `FlowNodePreflightListener` on `ObjectCreatingEvent` / `ObjectUpdatingEvent`,
      registered ahead of the other object listeners in `Application.php`.
- [x] `POST /api/flow/validate` on `FlowController` + route.
- [x] Unit tests for the classifier, the recognition guard and the listener.
- [x] Regression test replaying or#2247 through real collaborators, with a
      positive control proving the same document saves once the node exists.
- [ ] Frontend: surface `blocking` / `warnings` in the flow editor before save.
      Out of scope here — the backend refusal is what stops a half-run.

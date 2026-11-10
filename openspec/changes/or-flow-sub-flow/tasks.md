# Tasks: or-flow-sub-flow

- [x] `SubFlowNode` (`openregister.sub-flow`) — wait (run + return items) and
      fire-and-forget (queue + pass through) shapes.
- [x] Resolve the sub-flow through `FlowResolverRegistry`; refuse an unknown id.
- [x] Recursion guards — cycle (id on the run's flow stack) and depth ceiling.
- [x] Register the node on `RegisterFlowNodesEvent` (FlowNodeRegistrationListener).
- [x] `SubFlowNodeTest` — wait, fire-and-forget, unknown, cycle, depth, failed
      sub-run, validate, scope (8 tests).
- [x] phpcs clean; full flow node suite green (127 tests).
- [ ] Input mapping (explicit subject / mapped input to the sub-flow) — follow-up.

# Tasks: or-flow-triggers

- [x] `IFlowResolver` + `RegisterFlowResolversEvent` + `FlowResolverRegistry`
      (ask-each, first-wins, throwing-resolver-tolerant, id de-dup).
- [x] `FlowRunWorker::advance()` resolves flow + subject and executes; clear
      failure for a missing flow or subject; marking carrier for a subjectless run.
- [x] `FlowTriggerService::fire()` — queue per wired flow, never throw into the
      caller.
- [x] `FlowTriggerListener` — object create/update/delete, registered in
      Application.
- [x] Tests: fire queues per flow, no-wired-flow queues nothing, queue failure
      swallowed, id de-dup, first-owner resolution, throwing resolver tolerated.
- [x] Live-verified on 8080: fire -> queued (persisted) -> resolved -> executed
      -> completed with the flow's field on the output items.
- [ ] File / share / calendar / user / tag / schedule / webhook / manual
      triggers (each a listener + a fire() call).
- [ ] A resolver for OpenRegister's own flows, if OR gains a flow schema.

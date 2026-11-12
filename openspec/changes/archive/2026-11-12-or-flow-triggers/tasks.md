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
- [x] File / share / calendar / user / tag / schedule / webhook / manual (file, share, user and tag built in lib/Listener/NativeFlowTriggerListener.php (or-flow-native-triggers, or-flow-share-tag-triggers); schedule in lib/Service/Flow/Nodes/TriggerScheduleNode.php; manual in TriggerManualNode.php. Calendar and webhook triggers are not built and are not this change's)
      triggers (each a listener + a fire() call).
- [x] A resolver for OpenRegister's own flows, if OR gains a flow schema. (built by or-flow-native-store; the worker resolves the flow in lib/Service/Flow/FlowRunAdvancer.php:227)

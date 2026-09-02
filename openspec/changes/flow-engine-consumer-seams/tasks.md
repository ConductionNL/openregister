# Tasks: flow-engine-consumer-seams

## 1. The guarded signal seam

- [x] 1.1 `lib/Exception/FlowSignalRefused.php`: one typed refusal with reason
      constants (`run-not-found`, `not-assignee`, `not-suspended`), the
      refused actor and the run uuid readable by the caller.
- [x] 1.2 `lib/Service/Flow/FlowRunSignalService.php`: `signalAs(runUuid,
      payload, actorUid, nodeId?)` and `signalRunAs(run, …)` — resolve, apply
      `FlowRunAssignee` (group resolution included), audit a refusal with
      run/actor/assignee, deliver via `FlowRunService::signal()`. A refused
      signal touches nothing.
- [x] 1.3 `FlowRunAssignee`: optional `nodeId` on `recordedFor()` /
      `mayAnswer()` — an addressed held slot's assignee decides; a `nodeId`
      whose slot is not held falls back to the run-level rule.
- [x] 1.4 Migrate `FlowRunController::resume()` and `signalByKey()` onto
      `signalRunAs()`; delete `refuseUnlessAssignee()` and the local rule
      factory. Responses stay byte-identical.
- [x] 1.5 Document `FlowRunService::signal()` as the unguarded
      engine-internal primitive, pointing consumers at the seam.

## 2. Native runAs scoping

- [x] 2.1 `FlowRunService::RUN_AS_CONTEXT_KEY = 'runAs'`; migrate the
      in-tree literal reads (`baseContextFor`, `ObjectWriteNode`,
      `ObjectReadNode`, `FlowMessagingService`) onto it.
- [x] 2.2 `lib/Service/Flow/FlowRunAsScope.php`: validate (exists, enabled —
      refuse loudly otherwise) and apply `ObjectService::runAs()`; bare when
      the context names nobody.
- [x] 2.3 `lib/Service/Flow/IFlowSelfScopedNode.php`: the escape-hatch marker,
      with the obligations documented.
- [x] 2.4 `RegistryStepDispatcher`: execute contributed nodes inside the
      scope; engine-owned nodes and marker-declaring nodes untouched; wrap
      sits inside the #3325 signal scoping and the per-node budget check so
      neither moves.
- [x] 2.5 Wire the scope into all three dispatcher construction sites
      (`FlowRunService::execute()`, the #3310 stream walk, the
      `Application.php` factory).

## 3. Tests

- [x] 3.1 `FlowRunSignalServiceTest`: assignee passes, group member passes,
      stranger refused with nothing touched (the mutation check: skipping the
      guard reds it), anonymous refused, unassigned open, not-suspended and
      not-found reasons, nodeId addressing and its no-loosening fallback,
      refusal audited.
- [x] 3.2 `FlowRunAssigneeTest`: the nodeId addressing branches.
- [x] 3.3 `FlowRunAsScopeTest`: valid identity narrows via
      `ObjectService::runAs`, unknown refused, disabled refused, empty runs
      bare.
- [x] 3.4 `RegistryStepDispatcherRunAsTest`: contributed node wrapped as the
      run owner, unresolvable/disabled identity refused loudly, engine-owned
      node untouched, marker node untouched, scope-less dispatcher (the
      harness) runs bare, no-runAs context runs bare.
- [x] 3.5 Existing suites stay green: #3325 signal scoping
      (`RegistryStepDispatcherResumeTest`), #3310 stream walk
      (`FlowRunServiceAdvanceStreamTest`), the controller's resume tests.

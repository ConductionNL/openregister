# Tasks: flow-runs-honour-their-declaration

## 1. The resolver

- [x] 1.1 `FlowRunAuthorization`, consulted through `FlowService::assertRunnable()`, one method answering "may this principal run this flow", beside `FlowService::find()`.
- [x] 1.2 The rule: administrator, or owner, or the narrowable `flow.update` right; an unowned flow is refused to everyone.
- [x] 1.3 It fails closed without a session and without its collaborators.

## 2. The call sites

- [x] 2.1 `FlowService::run()`, which is what `FlowController::run()` calls.
- [x] 2.2 `FlowRunController::test()` and `retry()`, through the `refuseUnlessRunnable()` they already share.
- [x] 2.3 `FlowMcpToolProvider::runFlow()`.

## 3. The declaration

- [x] 3.1 At SCHEMA level, not inside the `authorization` block: an unknown
      key in that block is read as a VERB by `PermissionCatalogue` and refuses
      the schema at save — the `matrix` defect's exact shape. At schema level
      the importer may drop it, which costs nothing, because the FILE is what a
      reader reads.
- [x] 3.2 Corrected, with the untrue sentence quoted rather than deleted.

## 4. Tests

- [x] 4.1 The least privileged principal that should be refused: an ordinary signed-in colleague, in the same organisation, who neither owns the flow nor holds `flow.update`.
- [x] 4.2 Controls: the owner may, the administrator may, the `flow.update` holder may.
- [x] 4.3 An unowned flow is refused to all four, and the test asserts `canDispatch()` agrees, so the door and the engine cannot drift.
- [x] 4.4 Asserted structurally, naming each path in the failure message.

## 5. Named open

- [ ] 5.1 An invitation path. `private` means "owner, administrators and
      invited principals", and flows have no invitation mechanism — so
      `flow.update` stands in for "invited". The grant primitive of
      `object-level-sharing-and-private-scope` is keyed by object uuid and a
      flow is not an object, so this wants either a flow-shares table or the
      primitive widened. Named rather than approximated further.
- [ ] 5.2 An e2e over the refusal, which needs two accounts on an instance.

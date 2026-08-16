# Tasks

Sequenced so each stage is independently shippable. Mapping first because the map
node depends on consolidation; webhooks second because they turn flows into a
delivery surface; rule parity last because each node is separately useful and the
list is long.

## 1. Consolidate the mapping engine

- [ ] 1.1 Diff `openconnector/lib/Service/MappingService.php` against
      `openregister/lib/Service/MappingService.php` method by method and record
      every behavioural difference, not just the missing-method list. Two copies
      that drifted may differ inside methods with the same name.
- [ ] 1.2 Port `renderTemplateString` into OpenRegister's service.
- [ ] 1.3 For each difference found in 1.1, decide and record which behaviour is
      correct. A silent pick is a data-correctness bug with no audit trail.
- [ ] 1.4 Add a test that evaluates the same stored mapping through both call
      paths and asserts identical output. This must be observed FAILING against
      the two-copy state first, or it proves nothing.
- [ ] 1.5 Point OpenConnector's callers at OpenRegister's service.
- [ ] 1.6 Delete `openconnector/lib/Service/MappingService.php`.
- [ ] 1.7 Confirm no `MappingService` remains outside OpenRegister, including in
      DI registration and tests.

## 2. The mapping node

- [ ] 2.1 Add `openregister.map` implementing `IFlowNode`, resolving its mapping
      by id or slug.
- [ ] 2.2 Register it through `RegisterFlowNodesEvent` so every app's flows can
      use it.
- [ ] 2.3 Fail the step when the mapping cannot be resolved, naming the
      identifier. Do NOT pass items through unchanged.
- [ ] 2.4 Unit-test both paths, including the negative: assert the failure, and
      assert the items were not silently forwarded.
- [ ] 2.5 Add it to the node catalogue endpoint so the authoring palette offers it.

## 3. Webhook request binding

- [ ] 3.1 Bind path, query, headers and decoded body into the run context under
      one documented key.
- [ ] 3.2 Ensure a manual run with the same context behaves identically to a
      triggered one — no ambient request state.
- [ ] 3.3 Test a lookup by an id carried in the path.

## 4. Webhook authentication

- [ ] 4.1 Add an authentication declaration to the webhook trigger: `none`,
      shared secret, body signature.
- [ ] 4.2 Reject before queueing. A rejected call must create NO run row.
- [ ] 4.3 Fail closed: an unknown method or a missing secret rejects. `none` is
      reachable only by explicit choice, never as a default.
- [ ] 4.4 Negative-control the guard — assert a call WITHOUT credentials is
      refused, not only that a call with them succeeds. A guard only tested on
      the happy path is untested.
- [ ] 4.5 Verify the fail-closed default from a fresh install, not from a
      hand-built fixture.

## 5. Webhook responses

- [ ] 5.1 Let a flow declare its response: status, headers, body.
- [ ] 5.2 Refuse at SAVE time when a response-declaring flow is asynchronous,
      naming the conflict.
- [ ] 5.3 Return an error status when the flow fails, never a success with an
      empty body.
- [ ] 5.4 End-to-end test with a real HTTP call, asserting on the STATUS CODE as
      well as the body.

## 6. Rule/node parity

Each node: contributed via `RegisterFlowNodesEvent` by its owning app, added to
the catalogue, unit-tested including the failure path, and failing (never
silently completing) when it cannot do its work.

- [ ] 6.1 `openregister.extend-input`
- [ ] 6.2 `openregister.locking`
- [ ] 6.3 `openregister.audit-trail`
- [ ] 6.4 `openregister.write-file`
- [ ] 6.5 `openregister.javascript` — sandboxing decided and written down BEFORE
      implementation; this one executes caller-supplied code.
- [ ] 6.6 `openconnector.synchronization`
- [ ] 6.7 `openconnector.extend-external-input`
- [ ] 6.8 `openconnector.download`
- [ ] 6.9 `openconnector.fileparts-create` and `openconnector.filepart-upload`
- [ ] 6.10 Assert the engine still references no app id and no specific node type.
- [ ] 6.11 Re-run the vocabulary comparison from the proposal and confirm only
      `custom` remains without a node.

## 7. Verification

- [ ] 7.1 Playwright e2e over the flow surface, each assertion observed failing
      before it passes.
- [ ] 7.2 Confirm the mapping-parity test from 1.4 is still meaningful after
      consolidation — with one implementation left it can no longer compare two,
      so replace it with a fixture asserting the retained behaviours.

# Tasks: flow-trigger-transitions-and-changed-fields

## 1. Trigger node

- [x] 1.1 Add `object.transitioned` to `TriggerObjectNode::EVENTS`, and `transition` and `changedFields` to `configKeys()` and `validateConfig()` with the refusals of design D-2. Verify: `tests/Unit/Service/Flow/Nodes/TriggerObjectNodeTest.php` covers each refusal and each accepted shape.
      > 2026-11-13: `object.transitioned` joins `EVENTS`; `transition` and `changedFields` join `configKeys()` and are refused on the wrong event, as an empty or non-list value, or with a non-name entry. `tests/Unit/Service/Flow/Nodes/TriggerObjectNodeTest.php`; the vocabulary pin and the `TriggerNodes` golden are updated deliberately.
- [x] 1.2 Publish-time check that every `changedFields` entry is a property of the trigger's schema. Verify: a unit test publishes a flow naming an unknown property and reads the refusal naming it.
      > 2026-11-13: `FlowTriggerFieldsCheck`, run from `FlowVersionService::publish()` before anything is written, resolves the schema inside the trigger's register and refuses with reason `unknown-changed-field`, naming the step, the field and the schema (`FlowTriggerFieldsCheckTest`, `FlowVersionPublishWatchedFieldsTest`).

## 2. Matching

- [x] 2.1 `FlowTriggerListener::contextFor()` adds `changedFields` for `ObjectUpdatedEvent`, top-level keys only, `@self` left out. Verify: listener unit test with a real `ObjectUpdatedEvent` built from two `ObjectEntity` instances.
      > 2026-11-13: `contextFor()` adds `changedFields` for `ObjectUpdatedEvent`: changed, added and removed top-level keys, `@self` left out, empty without the old object (`FlowTriggerListenerChangedFieldsTest`, real event over two `ObjectEntity` instances).
- [x] 2.2 `FlowTriggerFilter::accepts()` and its call in `FlowTriggerService::fire()` before `queue()`; a legacy column flow is accepted unchanged. Verify: `tests/Unit/Service/Flow/FlowTriggerServiceTest.php` asserts no run is queued for a transition outside the filter, and one run for a matching one.
      > 2026-11-13: `FlowTriggerFilter::accepts()` runs in `FlowTriggerService` before `queueRun()`, over the flow's PUBLISHED graph (the index follows the published version, not a draft). Only `object.transitioned` and `object.updated` read the graph; a legacy column flow or a flow with no published graph is accepted as before (`FlowTriggerFilterTest`, `FlowTriggerServiceFilterTest`).
- [ ] 2.3 Schema rename warns when a published flow's `changedFields` names the old property. Verify: unit test on the rename path.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/flow-trigger-transition.spec.ts`: publish a flow on `object.transitioned` with `to: ["closed"]`, move a record to `closed` and to `open`, and assert one run. (live pass, decision 139)
- [ ] 3.2 Newman: an update that changes an unwatched field queues no run; one that changes a watched field queues one. (live pass, decision 139)
- [x] 3.3 Document both filters in `docs/` beside the object trigger.
      > 2026-11-13: `docs/features/flows.md`, beside the object trigger.

Acceptance:
- A converted flow can start on a state change.
- A flow that writes only its own output fields does not start itself again.

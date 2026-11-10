# Tasks: or-flow-nodes

## Investigation

- [x] Establish whether `OCP\WorkflowEngine\IOperation` can serve as the node
      contract. It cannot: `onEvent()` returns void, takes an `Event` and an
      `IRuleMatcher` rather than data, inverts control, and is entity-bound.
- [x] Compare discovery mechanisms — core's `RegisterOperationsEvent` versus
      OpenRegister's own `info.xml` + container-alias MCP provider scan. The
      event wins on both closeness-to-core and simplicity.

## Contract and discovery

- [x] `IFlowNode` — `IOperation`'s metadata methods, plus `validateConfig()`
      and `execute(items, config, context)`.
- [x] `RegisterFlowNodesEvent` — core's registration pattern.
- [x] `FlowNodeRegistry` — lazy single dispatch, duplicate-id refusal, scoped
      palette, throwing resolution.
- [x] `RegistryStepDispatcher` — so no consumer writes a dispatcher.

## Built-in nodes

- [x] `SetFieldsNode` — set / rename / remove / keepOnlySet, per item.
- [ ] Condition node on JSONLogic — deferred to #2069 with the dependency move.

## Nextcloud Flow bridge

- [x] Verified the bridge already exists (`WorkflowEngine\RegisterObjectEntity`
      + `WorkflowEngine\RunFlowOperation`, wired by
      `FlowEngineRegistrationListener`). An earlier draft of this change added a
      duplicate; it has been removed.
- [ ] Repoint the existing bridge from `FlowActionService` to `FlowEngine` —
      needs the run queue (#2076).

## Verification

- [x] Unit tests: contribution, single dispatch, duplicate refusal, unknown
      type, scoped palette, palette metadata, routing-only edge, dispatch
      routing.
- [x] Unit tests: per-item application, pairing, remove/rename/set ordering,
      keepOnlySet, binary passthrough, empty input, config validation.
- [x] Fix `FlowActionServiceTest`'s constructor call — 12 tests could not
      construct the service. The 9 behavioural failures underneath are filed
      as #2073 and deliberately not papered over.

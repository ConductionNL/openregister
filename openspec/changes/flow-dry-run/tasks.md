# Tasks: flow-dry-run

### Task 1: A flow can be tried against an object without effect
- **spec_ref**: `openspec/changes/flow-dry-run/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect`
- **files**: `lib/Service/Flow/FlowDryRunService.php`, `lib/Service/Flow/PreviewStepDispatcher.php`, `lib/Service/Flow/IFlowSideEffectFree.php`, `lib/Controller/FlowDryRunController.php`, `appinfo/routes.php`
- [x] Implement
- [x] Test

### Task 2: A node can say what it would do
- **spec_ref**: `openspec/changes/flow-dry-run/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do`
- **files**: `lib/Service/Flow/IFlowNodePreview.php`, `lib/Service/Flow/FlowMessagePreview.php`, `lib/Service/Flow/FlowRecipientAddresses.php`, `lib/Service/Flow/Nodes/ObjectWriteNode.php`, the three send nodes, `lib/Service/Flow/FlowMessagingService.php`
- [x] Implement
- [x] Test

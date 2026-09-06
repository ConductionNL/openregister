---
kind: code
depends_on: []
---

## Why

The node catalog on a live instance serves **64 step types as one flat list**: 21 from openregister, 18 from dossiq, 10 from openconnector, 4 from humaniq, 3 from hermiq, 2 from pipelinq. An author opening the palette to add a step is shown all of them, in whatever order the registry happened to collect them, with no grouping and nothing to say that "Wait for an answer" and "Ask a person" are two halves of one decision while "Apply a mapping" is a different kind of thing entirely.

`IFlowNode` declares `getId`, `getDisplayName`, `getDescription`, `getIcon`, `isAvailableForScope`, `validateConfig` and `execute`. There is no notion of a kind or a category anywhere, so there is nothing for a palette to group by and nothing for an exporter to read.

Two established taxonomies apply, and they answer different questions:

- **n8n** types every node architecturally — trigger, action, core, cluster — *and* groups the palette separately, with categories including "Human in the loop". The two axes are independent on purpose: the architecture decides how a node may be wired, the category decides where an author finds it.
- **BPMN** already defines the semantic taxonomy this domain uses: user task, service task, script task, business rule task, send task, receive task, manual task, gateway, event. `openspec/changes/flow-bpmn-interchange` is already in this repo, and an interchange cannot emit a BPMN element type it never asked the node for.

So the kind is not a new invention to maintain; it is a fact the BPMN work needs anyway, currently inferred nowhere.

## What Changes

- **`IFlowNode` gains `getKind()`**, returning a BPMN element kind: `userTask`, `serviceTask`, `scriptTask`, `businessRuleTask`, `sendTask`, `receiveTask`, `manualTask`, `gateway`, `event`, `subProcess`.
- **`IFlowNode` gains `getCategory()`**, returning a palette grouping: `triggers`, `human`, `objects`, `logic`, `messaging`, `ai`, `integrations`, `other`.
- **Both are defaulted, not required.** A node that implements neither is served as `serviceTask` / `other`, so no contributed node in any app breaks and no app is forced to release in step with this. **This is the whole reason the two are separate methods on the interface rather than a required constructor argument**: 43 of the 64 nodes are in repos this change cannot touch.
- **The catalog serves both**, and the palette groups by category with the categories in a fixed order rather than registration order.
- **openregister's own 21 nodes declare both.** The other apps' nodes are left to their owners, arriving as `other` until then, which is visibly worse than a wrong guess made on their behalf.
- **A gate refuses a new openregister node that declares neither**, so the flat list cannot quietly grow back.

## Capabilities

### New Capabilities
- `flow-node-taxonomy`: what a node's kind and category mean, how they default, how the catalog serves them, and which axis answers which question.

## Impact

| Area | Change |
|---|---|
| `lib/Service/Flow/IFlowNode.php` | two methods, both defaulted |
| `lib/Service/Flow/Nodes/*.php` | 21 nodes declare kind and category |
| `lib/Service/Flow/FlowNodeRegistry.php` | serves both, applies the defaults |
| `lib/Controller/FlowController.php` | node catalog response |
| nextcloud-vue palette | groups by category, fixed order |
| `.github/hydra-gates` | a gate for an openregister node declaring neither |

## Out of scope, deliberately

- **Assigning kinds to the 43 nodes owned by other apps.** Each app knows whether its step is a service task or a send task; guessing on their behalf would put a wrong answer into a BPMN export, which is worse than an honest `other`.
- **Emitting BPMN.** That is `flow-bpmn-interchange`. This change gives it the fact it needs; it does not do its job.

---
kind: code
depends_on: []
---

## Why

A flow step that asks a person names that person as **a string typed into a text box**, and nothing ever checks that the string names anybody.

This is not a theoretical gap. Measured on the demo instance, 2026-09-06:

- 24 flows carry **30 `dossiq.askPerson` nodes**. Their assignees are role names: `Beleidsadviseur`, `juridische-dienst`, `Afdelingshoofd`, `Gemeentesecretaris`, `Griffier`, `financieel-adviseur`, `teamleider`, `burgemeester`, `bezwaarcommissie`.
- `FlowRunAssignee::mayAnswer()` accepts an answer only when the answerer's uid **equals** the assignee string, or the answerer is in a **group named exactly** that string.
- Of those 30 nodes, **3 resolved. 27 could be answered by nobody on the instance** — not even by an administrator. The run suspends, the heartbeat re-reads forever, and nothing anywhere says why.
- The same role appears under two spellings in one instance (`Afdelingshoofd` / `afdelingshoofd`, `Gemeentesecretaris` / `gemeentesecretaris`). That is what a free-text box produces.

`validateConfig()` already refuses an *empty* assignee, with a good comment about why an unassigned task is a task anyone may complete. It cannot refuse a *non-resolving* one, because a bare string carries no type to resolve against.

The vocabulary for fixing this is half-built already and unreachable. `Task.performerType` accepts `user`, `group`, `agent` and `worker`; `UserTaskNode` exposes `assignee`, `candidateUsers`, `candidateGroups`, `candidateRole`, `routingStrategy` and `performerType`. Every one of them is declared `'type' => 'text'` in the node's config form, so the editor draws seven text boxes and the author is asked to remember uids. Meanwhile the kinds of performer a Dutch municipality actually routes to — a **position** in a body (decidiq), a **function** (hermiq) — cannot be named at all.

## What Changes

- **A performer is a typed reference, not a string.** `assignee` and the candidate fields accept `{type, id}` where `type` is one of `user`, `group`, `role`, `position`, `function` or `agent`. A bare string keeps working and is read as `{type: 'user', id: <string>}`, so no existing flow changes meaning.
- **`IPrincipalResolver`, contributed the way flow nodes already are.** OpenRegister owns `user` and `group`; a consuming app registers a resolver for its own type through a `RegisterPrincipalResolversEvent`, the same recipe as `RegisterFlowNodesEvent`. decidiq contributes `position`, hermiq contributes `function`, dossiq contributes its case `role`. OpenRegister never calls those apps directly (gate-27, ADR-022).
- **A reference that cannot resolve fails when it is authored, not when a person is needed.** `validateConfig()` refuses a reference whose *type* has no registered resolver. Task creation refuses a reference that resolves to **nobody**, loudly, instead of parking a task in the void — which is the defect above, converted from silence into a refusal the author can act on.
- **`mayAnswer()` asks the resolver.** It stops string-matching a uid and a group id, so a position or a function authorises an answer on the same terms a group does. **BREAKING for one edge case**: a task whose assignee string happens to equal a *group* name is authorised today by accident of spelling; after this it is authorised because the reference says `group`. A repair step migrates stored assignee strings to typed references by resolving them once, and reports every one it cannot.
- **A searchable picker replaces the seven text boxes.** A new `principal` field type in the node catalog, rendered by nc-vue as a multi-select that searches users and groups as you type (`shareTypes[]=0,1`) plus the types apps contribute. The existing `user` widget stays for `runAs`, which takes one uid and means something different.
- **Agent becomes a performer of this node.** `performerType: agent` gains a `prompt`, dispatched through the `AgentRunRequestedEvent` already designed in `flow-agent-action`, so an agent's answer is a task row with the same audit and the same handover to a person. `hermiq.agent-step` is untouched and stays the way to run a turn that is not a question.
- The node is renamed **"Ask a person or group"**, because it always could ask a group and never said so.

## Capabilities

### New Capabilities
- `flow-typed-principals`: what a typed performer reference is, how a resolver is contributed and consulted, when an unresolvable reference is refused, and how an answer is authorised through it.

### Modified Capabilities
- `flow-user-task-node`: the assignee vocabulary becomes typed; the node declares a `principal` field type; agent gains a prompt; the display name changes.
- `flow-node-config-forms`: the catalog gains the `principal` field type and its contributed option sources.
- `flow-tasks`: `mayAnswer` is resolver-backed rather than string-matched; task creation refuses an empty resolution.

## Impact

| Area | Change |
|---|---|
| `lib/Service/Flow/FlowRunAssignee.php` | `mayAnswer` consults the resolver registry instead of comparing strings |
| `lib/Service/Flow/PrincipalResolver/` | new — the registry, the `user` and `group` resolvers, the reference value object |
| `lib/Service/Flow/RegisterPrincipalResolversEvent.php` | new — the contribution seam |
| `lib/Service/Flow/Nodes/UserTaskConfig.php` | parses and validates typed references; refuses an unresolvable type |
| `lib/Service/Flow/Nodes/UserTaskNode.php` | `principal` field type, agent prompt, new display name |
| `lib/Service/Task/TaskBuilder.php` | stores the resolved performers alongside the reference |
| `lib/Migration/` | repair step migrating stored assignee strings to typed references, reporting what it cannot resolve |
| nextcloud-vue `CnFlowNodeEditModal.vue` | the `principal` widget |
| decidiq / hermiq / dossiq | one resolver each, in their own repos, after this lands |

## Out of scope, deliberately

- **Returning a task form's answers into the run.** `FlowTaskBridge::outcomeBagFor()` has no `answers` key while `PortalTaskNode` line 450 does, so a user-task form's values die on the task row. That is a real defect and it is a different surface; it belongs with the run's declared object set in its own change.
- **Categorising the node palette.** The catalog serves 64 node types as one flat list. Also real, also separate: it touches every node in the fleet and none of this.
- **Retiring `dossiq.askPerson`.** It depends on this landing first, it lives in another repo, and per ADR-032 a change that mixes an engine refactor with a cross-app migration is the shape that burns a cycle without shipping.

# Design: flow-tag-object-step

Read at openregister development c53dd0685c.

## D-1: the node

`lib/Service/Flow/Nodes/TagObjectNode.php` implements `IFlowNode`,
`IFlowNodeConfigKeys`, `IFlowNodeConfigForm` and `IFlowNodeTaxonomy`, like
`SendNotificationNode` (`lib/Service/Flow/Nodes/SendNotificationNode.php:44`),
type `openregister.tag-object`, available for `SCOPE_ADMIN` and `SCOPE_USER`
(the same answer the object-write node gives,
`lib/Service/Flow/Nodes/ObjectWriteNode.php:440-442`). It is registered in
`lib/Listener/FlowNodeRegistrationListener.php` beside the lock nodes (`:38`,
`:55`).

Config keys, validated in `validateConfig()`:

| key | meaning |
|---|---|
| `operation` | `add` or `remove`, required |
| `tag` | tag name, required, rendered per item with `FlowValueTemplate` |
| `color` | optional, six hex digits with or without `#`, stored without |
| `uuid` | optional template for the target object; default the item's `uuid` |
| `createIfMissing` | optional, default `true` for `add`; ignored for `remove` |

Target resolution copies `LockObjectNode::resolveTargets()`
(`lib/Service/Flow/Nodes/LockObjectNode.php:571-595`): the item's `uuid`, or the
rendered `uuid` template, and a step failure naming the item when neither
yields one.

## D-2: as the run identity

The acting identity is `context.runAs`, else `context.triggeredBy`, the rule
`UserTaskNode::actingIdentity()` uses (`lib/Service/Flow/Nodes/UserTaskNode.php:590-599`).
A run without one tags nothing and fails the step saying so, the rule the
object-write node follows (`ObjectWriteNode.php:18-24`). Inside
`ObjectService::runAs()` the node loads each target with RBAC and multitenancy
on, then requires `PermissionHandler::hasPermission(schema, 'update', userId,
object)` (`lib/Service/Object/PermissionHandler.php:414`). A target it cannot
load or may not update fails the step with the object's uuid; the engine's
`onError` policy decides what happens next.

## D-3: idempotent, so it cannot loop on itself

`TaggingHandler` gains `hasObjectTag(uuid, name)`. `add` on a tag the object
has, or `remove` on one it lacks, is a no-op and assigns or unassigns nothing,
so `TagAssignedEvent` and `TagUnassignedEvent` are not raised and a flow
triggered on `tag.assigned` (`lib/Listener/NativeFlowTriggerListener.php:140-144`)
does not start again. `removeObjectTag()` today throws when the tag does not
exist at all (`TaggingHandler.php:328-345`); for the node, a missing tag on
`remove` is the same no-op.

## D-4: colour

`TaggingHandler::findOrCreateTag()` (`:169-196`) gains an optional colour. On
create it calls `ISystemTagManager::createTag()` and then `updateTag()` with the
colour (Nextcloud 31 added the `$color` argument; `createTag()` has none). On an
existing tag it sets the colour only when `getColor()` is null, so a step never
recolours a tag an administrator chose a colour for. When `createIfMissing` is
false and the tag does not exist, `add` fails naming the tag. Nextcloud 31 may
refuse tag creation for a user who is not allowed to create tags
(`TagCreationForbiddenException`); the node reports that refusal as the step's
failure rather than creating the tag as the system.

## Declarative-vs-imperative decision

A flow node, not a schema annotation. ADR-031 would place "whenever a lead
matches X, label it" on the schema if the dialect had a labelling rule; it
does not, and pipelinq's matrix places the rule on its Flows pages, where the
user sets the condition. The flow engine is the declared place for "when this
happens, do that" rules a user authors (hydra ADR-065), and a tag is a side
effect, not a stored property, so a computed field cannot express it either.

## Risks

- Security (hydra ADR-005): `update` is required per object as the run
  identity; tagging is a change to how a record is shown and filtered.
- Loops: D-3 removes the self-trigger; a flow that toggles a tag on and off
  from two triggers is still possible and is the author's, as with any two
  flows writing one field.
- Performance: one tag lookup per distinct tag name per run, cached for the
  run, and one object load per item, which the object-write node already pays.

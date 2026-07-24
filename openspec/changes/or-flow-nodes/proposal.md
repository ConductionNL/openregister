# Proposal: or-flow-nodes

## Summary

Let any app contribute node types to the OpenRegister flow engine, so no app
ever needs a flow engine of its own again. Discovery uses Nextcloud's own
registration pattern, and the node contract is shaped after Nextcloud Flow's
`IOperation` everywhere it can be.

## Why

hermiq shipped `GraphExecutor` — its own graph engine — because there was no
way to contribute a step to anyone else's. That made it the sixth thing in this
fleet called "flow". Until contributing a node is easier than writing an
engine, apps will keep writing engines.

`FlowEngine` already has the seam (`FlowStepDispatcher`), but each consumer had
to implement it, and a dispatcher with a type switch in it *is* half an engine.

## Investigated first: can Nextcloud Flow be the node system?

No, and the reason is concrete. Core invokes an operation like this
(`apps/workflowengine/lib/AppInfo/Application.php:81`):

```php
$entity->prepareRuleMatcher($ruleMatcher, $eventName, $event);
$operation->onEvent($eventName, $event, $ruleMatcher);
```

1. `onEvent()` returns **void** — a node must return items; there is nowhere
   for output to go.
2. It takes an `Event` and an `IRuleMatcher`, never data. That is a listener
   signature, not a callable one.
3. Control is inverted: the operation asks the matcher which rules matched and
   acts alone. In a graph, the engine decides what runs.
4. An operation is bound to an entity and its events
   (`ISpecificOperation::getEntityId()`) — "when X happens, do Y" is a one-step
   rule, not a step that can sit anywhere in a graph.

Wrapping an `IOperation` as a step would mean synthesising an `Event` and an
`IRuleMatcher` it never asked for and discarding output that does not exist.

## So: our contract, Nextcloud's everything-else

- `IFlowNode` mirrors `IOperation`'s metadata methods verbatim
  (`getDisplayName`, `getDescription`, `getIcon`, `isAvailableForScope`) and
  uses Nextcloud's own `IManager::SCOPE_*` constants. It adds the two methods
  `IOperation` lacks: `validateConfig()` and `execute(items, config, context)`.
- Discovery is `RegisterFlowNodesEvent`, a direct copy of core's
  `RegisterOperationsEvent` pattern. An app writes the same listener it would
  write for Nextcloud Flow.
- The two systems **compose**: `RunFlowOperation` registers into Nextcloud Flow
  so a core rule can start an OpenRegister flow — the direction that works.
  Nextcloud Flow keeps what it is good at; a flow adds branching, joins, loops
  and data between steps, which core cannot express.

## Discovery: why the event, not the fleet's other pattern

OpenRegister already has a second discovery mechanism — MCP tool providers,
found by probing each app's `info.xml`, building container aliases
(`OCA\OpenRegister\Mcp\IMcpToolProvider::<appId>`), resolving them, and then
caching the resolution map with two invalidation mechanisms to stay affordable.

That complexity exists because it scans for something apps never announce.
Apps *do* announce a listener, so none of it is needed. The event is both
closer to Nextcloud and simpler than the fleet's alternative — the two goals
did not conflict here.

## What Changes

- `IFlowNode`, `RegisterFlowNodesEvent`, `FlowNodeRegistry`.
- `RegistryStepDispatcher` — the `FlowStepDispatcher` every consumer gets free.
- `SetFieldsNode` — the first built-in and the reference implementation.
- `RunFlowOperation` + `NcFlowOperationListener` — the Nextcloud Flow bridge.

## Out of scope (this change)

- **Running the bridged flow.** `RunFlowOperation` recognises a matching rule
  but does not execute inline: a Flow operation runs inside the dispatch of the
  event that triggered it, often a file write, and an arbitrary graph does not
  belong on that critical path. It needs the run queue, which waits on run
  persistence (#2070).
- **The JSONLogic condition node** — belongs with the expression work (#2069),
  which owns moving `jwadhams/json-logic-php` off openconnector.
- **Migrating hermiq** onto this (hermiq#35).

# Design: flow-trigger-transitions-and-changed-fields

Read at openregister development 555af7212.

## Context

- `TriggerObjectNode::EVENTS` (`lib/Service/Flow/Nodes/TriggerObjectNode.php:80-84`)
  is a closed list of `object.created`, `object.updated` and `object.deleted`,
  and `configKeys()` (`:169-171`) returns `event`, `register` and `schema`.
  `validateConfig()` (`:189-220`) refuses any other event.
- The engine already fires more than that. `FlowTriggerListener::eventIdFor()`
  (`lib/Listener/FlowTriggerListener.php:215-240`) maps
  `ObjectTransitionedEvent` to `object.transitioned`, and `contextFor()`
  (`:180-199`) puts `action`, `from`, `to` and `automatic` on the run context.
  `EventCatalogService` lists `object.transitioned` (`:59`).
- `FlowLocator::flowsForTrigger()` (`lib/Service/Flow/FlowLocator.php:185`)
  asks the derived trigger index first. For a flow that has trigger nodes, the
  nodes decide entirely. So a converted flow can never wire to
  `object.transitioned`: its trigger node refuses the event. Only a flow still
  on the legacy trigger column can.
- The index (`lib/Db/FlowTriggerMapper.php:73`) matches on event, register and
  schema only. Nothing filters on which transition or which fields.
- `ObjectUpdatedEvent` carries `getOldObject()` and `getNewObject()`
  (`lib/Event/ObjectUpdatedEvent.php:80-91`), so the changed keys can be
  computed where the event is heard.

## D-1: the transition is an event of the object trigger, not a new node

`object.transitioned` joins `TriggerObjectNode::EVENTS`. The index already
carries the event column, so matching stays one indexed lookup. A second node
type for the same subject would split one palette entry into two for no gain.

## D-2: filters are node config, applied after the index lookup

Two optional config keys join `configKeys()`:

- `transition`: `{ "actions": [..], "from": [..], "to": [..] }`, valid only with
  `event: object.transitioned`. Each list, when present, must contain the
  value from the event context. An empty object means every transition.
- `changedFields`: a list of top-level property names, valid only with
  `event: object.updated`. At least one must appear in the context's
  `changedFields`.

`validateConfig()` refuses a filter on the wrong event, an empty list, and a
name that is not a string, naming the key. The schema's own property names are
checked when the flow is published, where the schema is known.

`FlowTriggerService::fire()` keeps its per-flow loop. Before `queue()`, it asks
a new `FlowTriggerFilter::accepts(flow, event, context)` whether any of the
flow's trigger nodes for this event accepts the context. A flow on the legacy
column has no filter and is accepted, as today. The index stays the coarse
match; the filter is a cheap in-memory check on the few flows it returns.

## D-3: changed fields are computed once, on the listener

`FlowTriggerListener::contextFor()` gains a branch for `ObjectUpdatedEvent`:
the top-level keys whose values differ between the old and new object, with
`@self` metadata left out. The list goes on the context as `changedFields`, so
a condition in the flow can read it too. An update event with no old object
yields an empty list, and a `changedFields` trigger then does not start. That
is the safe side: without the old object nothing proves a watched field
changed.

## D-4: a flow's own write does not loop

A flow that writes back only its output fields changes none of the fields it
watches, so D-2 already stops the loop buildiq describes. No run-origin
marker is added.

## Risks

- A maker who lists a field that the schema later renames gets a trigger that
  never starts. The publish check in D-2 catches it at publish time only.
  Task 2.2 adds the check to the schema-rename path as a warning.

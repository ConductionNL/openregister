# Proposal: or-flow-per-item-routing

## Summary

Per-item routing — n8n's If / Switch that splits an item stream across branches,
each item taking the branch its own data selects. This closes the last named gap
in #2067. It is additive: a step whose items carry no routing tag distributes
exactly as before, so no existing flow changes behaviour.

## Why

The existing `SwitchNode` branches the *whole batch*: the engine picks one
outgoing edge by its condition and every item follows it. That is a real routing
mode, but it is not what n8n's If/Switch do — those decide per item, and a flow
that (say) sends approved records one way and the rest another needs exactly
that.

## What Changes

- **The item gains an optional `output` tag** (`FlowItems::OUTPUT`), naming the
  branch it is routed to. It is present only when a routing node sets it, so an
  ordinary item keeps its exact `{json, binary, pairedItem}` shape.
- **`FlowEngine::advanceItems` distributes per output**: an item tagged for an
  output goes only to that output place; an untagged item is broadcast to every
  output — the ordinary behaviour, and what a parallel split relies on. The tag
  is stripped as the item lands, so it never lingers to misroute a later step. A
  step whose items carry no tag routes exactly as before: additive, not a change
  to the firing rule.
- **`RouterNode`** (`openregister.route`, "Route items") tags each item. Its
  rules are tried in order; the first whose JSONLogic condition holds wins and
  tags the item for that rule's output. An item matching nothing takes the
  `default` output when set, and is otherwise dropped — the same as a Switch case
  with no match and no default.

## Relationship to SwitchNode

Both stay. `SwitchNode` + edge conditions is whole-batch routing (any node can
branch, the decision is on the edge). `RouterNode` is per-item routing (the
decision is in the node, on each item). They are the two branch kinds n8n has;
an author picks by whether the split is of the path or of the items.

## Out of scope

The builder affordance for drawing a router's outputs and labelling them.

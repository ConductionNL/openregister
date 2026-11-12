# Proposal: or-flow-merge

## Summary

Make the item data channel per-place, so parallel branches carry their own
items and a join can combine them. Adds a Merge node and a Loop (batch) node.

## Why

The engine threaded a single global `$items` list — whatever the last step
produced. That was subtly wrong for parallel branches: after a split, the
second branch to run read the FIRST branch's output, not the items from the
split point. And a join had no way to see both branches' items at all.

## What Changes

- **Per-place item buffers in the engine.** Items belong to the PLACES a token
  sits on, not to the run globally. A split hands each branch the items from the
  split point; a join reads the concatenation of every incoming branch's items.
  Seeded from the current marking, so suspend/resume still lands the stored
  items on the place that holds the token. Moved in lock-step with the marking:
  the token and the items advance together, and consumed inputs are cleared so a
  loop re-reads fresh.

  A condition now evaluates against the items on ITS candidate transition's
  input place — the data that branch would actually carry — rather than one
  global list.

- **`MergeNode`.** Sits at a join. The Petri net already holds the transition
  until every input place is marked (wait-for-all is free), so the node only
  decides HOW to combine: `append` (keep all), `mergeByKey` (group and
  shallow-merge, later branch wins a field), or `unique` (dedupe on a key).

- **`LoopNode`.** Splits an item list into fixed-size batches, one output item
  per batch. NOT for processing a collection — a node already acts once per
  item — but for a downstream step that must be handed a bounded slice at a
  time (an API page limit, a bulk-write cap).

## Design decisions

- **The Petri net does the join timing.** wait-for-all is the default because a
  join transition is not enabled until all its input places are marked. The
  Merge node never has to wait; it only combines what has already arrived.
- **`mergeByKey`: later branch wins.** Enrichment as the record flows through —
  the same shape as `SetFields`.
- **Loop is batching, not iteration.** The item model already iterates; a
  separate "for each item" node would be redundant.

## Out of scope (this change)

- **Sub-flows** — calling a flow from a flow. Needs the run queue to suspend the
  parent while the child runs; a follow-up.
- **Per-item routing** — sending each item down a different branch. The
  per-place buffers make this newly possible, but it is its own change.

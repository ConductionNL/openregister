## Why

Several steps can converge on one node without a declared `join` (an OR-merge):
the node fires once per arriving token. `FlowItemPlacement::advanceItems()`
ASSIGNED each firing's items to its output place, so when two predecessors fired
before the consumer did, the second replaced the first's items. The token count
added up; the items did not.

Measured on the Rotterdam stack (opencatalogi's publiccode harvest, run
5f222a8b): 24 shard pages converged on one `hits` node and the tail processed 3
pages. The run reported `completed`. OpenCatalogi works around it with one tail
per shard; the fix belongs in the engine.

## What Changes

- `advanceItems()` appends a firing's items to what its output place already
  holds instead of replacing them.
- Unchanged: a firing with no items still places nothing; an exit the token did
  not take is still cleared; the consumed input places are still cleared; a
  `join` still reads every branch's place.

## Impact

- Only a place that already holds unconsumed items behaves differently, and
  there the old behaviour was data loss. A flow that never converges without a
  join sees no change.

## 1. Engine

- [x] 1.1 `FlowItemPlacement::advanceItems()` appends to the output place (`array_merge` with what is there) instead of assigning.

## 2. Verification

- [x] 2.1 `tests/Unit/Service/Flow/FlowOrMergeTest.php`: three parallel shards converge on one node without a join, through the real FlowEngine, FlowDefinitionBuilder and Symfony marking store; the node receives each shard's item exactly once. Plus the placement rule on its own. Both fail on the old code (the node received only shard 3).
- [x] 2.2 The whole Flow unit suite stays green (1,495 tests).
- [ ] 2.3 Live: opencatalogi's harvest with shards converging on one tail (lane oc can drop its one-tail-per-shard workaround after merge). (live pass, decision 139)

# Tasks: or-flow-merge

- [x] Per-place item buffers in `FlowEngine` (`seedPlaceItems`, `itemsForTransition`,
      `advanceItems`), seeded from the marking so resume still works.
- [x] Conditions evaluate against the candidate transition's input place.
- [x] `MergeNode` — append / mergeByKey / unique.
- [x] `LoopNode` — fixed-size batching.
- [x] Register both through the contribution event.
- [x] Tests: parallel branches isolated, join reads both, merge modes, loop
      batching. 105 flow tests green.
- [x] Live-verified on 8080: a split→join flow merges both branches' items;
      palette lists all seven built-ins.
- [ ] Sub-flows — needs run-queue wiring (follow-up).
- [ ] Per-item routing — now possible on the per-place buffers (follow-up).

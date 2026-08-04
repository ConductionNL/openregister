# Tasks: or-flow-per-item-routing

- [x] Item `output` tag (FlowItems::OUTPUT), present only when set; normalise
      preserves it; `outputOf()` reads it.
- [x] `FlowEngine::advanceItems` distributes per output (untagged broadcasts,
      tagged goes to its output only, tag stripped on delivery) via
      `itemsForOutput()`. run() CC unchanged at baseline 12.
- [x] `RouterNode` (`openregister.route`) — rules in order, first match wins,
      `default` fallback, unmatched dropped; registered on the node event.
- [x] Tests: engine split + no-regression broadcast (FlowEngineTest);
      RouterNode tag/first-match/drop/validate/scope (RouterNodeTest). 142 flow
      tests green; phpcs clean; phpmd run() at baseline.
- [x] Live-verified on 8080: `openregister.route` in the palette; a real
      RegistryStepDispatcher run split 3 seed items into high=1 / low=2 branches.
- [ ] Builder affordance for router outputs — follow-up.

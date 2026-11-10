# Tasks: or-flow-logic

- [x] Conditional edge selection in `FlowEngine` (`selectTransition()`), with
      default-edge and dead-end handling.
- [x] `SwitchNode` — the pass-through palette anchor.
- [x] `FlowStop` + `StopNode` — deliberate end, plain or error.
- [x] Palette resilience: one bad node is skipped, not fatal.
- [x] Register both new nodes through the contribution event.
- [x] Tests: branch selection, default edge, first-match-beats-default,
      dead-end completes, stop, error-stop, switch pass-through, stop throws.
- [x] Live-verified on 8080: n=42 → high branch, n=3 → low branch; palette
      lists all five built-ins.
- [ ] Merge / join item semantics — needs per-place item storage (follow-up).
- [ ] Loop Over Items batching (follow-up).
- [ ] Sub-flows — needs the run-queue wiring (follow-up).

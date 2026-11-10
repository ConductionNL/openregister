# Tasks: or-flow-pins

- [x] `FlowEngine.run` — pin check before dispatch; pinned output used, side
      effect skipped, traced as `pinned`.
- [x] Pins from `context.pins` then `flow.pins`; run pins win.
- [x] Pinned step short-circuits stop/suspend/fail.
- [x] Held `run()` cyclomatic complexity at the baseline (12) by extracting the
      error-policy decision into `outcomeForFailedStep()`.
- [x] FlowEngineTest — pinned not executed, flow-level pin, run overrides flow,
      pin past a failing step (4 tests; 20 engine tests total). phpcs clean.
- [ ] Partial execution / run-from-here (seed the marking mid-graph) — follow-up.
- [ ] Builder/endpoint that captures and supplies pins — follow-up.

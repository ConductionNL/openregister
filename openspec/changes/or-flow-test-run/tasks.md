# Tasks: or-flow-test-run

- [x] `FlowRunController::test` + route `POST /api/flow-runs/test`.
- [x] Resolve flow via FlowResolverRegistry (404 unknown, 400 no flowId).
- [x] startAt + pins (on context) + seedItems (normalised) → queue + execute.
- [x] Persist as trigger `test`; return the finished run.
- [x] FlowRunControllerTest — bad request, unknown flow, synchronous run passes
      startAt through, pins land on the context (4 tests). phpcs clean.
- [x] Live-verified on 8080: real queue+execute+persist with startAt + a pinned
      step; run reloads from the DB completed.
- [ ] Builder "Execute" button + pin/inspect UI — follow-up.

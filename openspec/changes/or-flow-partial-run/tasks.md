# Tasks: or-flow-partial-run

- [x] `FlowEngine::run` optional `startAt` overriding `initial` (via
      `withStartNode()`, holding run() CC at baseline 12).
- [x] Unknown start node fails the run (builder validation).
- [x] `FlowRunService::execute` threads `startAt`, dropping it on resume.
- [x] FlowEngineTest — run-from-here skips upstream, unknown start fails, empty
      start ignored (3 tests; 23 engine tests total). phpcs clean; phpmd baseline.
- [x] Live-verified on 8080: run-from a mid node ran only the downstream step;
      unknown start failed.
- [ ] Interactive endpoint / MCP tool (startAt + seed + pins, synchronous) —
      follow-up.

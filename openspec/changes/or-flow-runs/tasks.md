# Tasks: or-flow-runs

- [x] `FlowRun` entity + statuses (incl. `suspended`) + `isTerminal()`.
- [x] `FlowRunMapper` — findByUuid, findAllRuns, findQueued, findDue, pruneBefore.
- [x] Migration `Version1Date20260724120000` with indexes for the worker's two
      hot reads, per-flow history, retention, and the sub-flow parent link.
- [x] `FlowRunMarkingStore` — marking on the run, not the subject.
- [x] `FlowSuspension` + `FlowEngine::STATUS_SUSPENDED`, caught before the
      `onError` policy.
- [x] `FlowRunService` — queue / execute / resume / persist.
- [x] `FlowRunWorker` background job + retention, registered in info.xml.
- [x] Tests: queue does not execute, suspend leaves it resumable, marking stays
      put, resume carries stored items, resumeAt cleared, log accumulates,
      terminal never re-executes, malformed flow fails, marking round-trip.
- [ ] Resolve the flow document + subject in the worker — needs the
      flow-document store.

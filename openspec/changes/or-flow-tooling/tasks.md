# Tasks: or-flow-tooling

- [x] `FlowRunService::retry()` — new run, original untouched, terminal-only,
      never re-executes.
- [x] `FlowRunController` — index / show / retry, with paging and status codes.
- [x] Routes.
- [x] Tests: retry queues fresh + original untouched, every terminal status
      retriable, every non-terminal status refused.
- [x] Live-verified on 8080: list returns runs, show returns the full log +
      items, retry 201s a fresh queued run with a distinct uuid.
- [ ] Pin / mock data (follow-up — needs an editor surface + engine override).
- [ ] Partial execution (follows pin/mock).

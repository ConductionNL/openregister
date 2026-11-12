# Tasks: or-flow-queue-fairness

- [x] `FlowRunMapper::findQueued()` — divide the batch between the flows that
      have queued work (`ceil(limit/flowCount)` each, oldest-first within a
      flow); never exceed `limit`; identical to the old behaviour for one flow.
- [x] `FlowRunMapper::flowsWithQueuedRuns()` — distinct waiting flows ordered by
      their oldest queued run, so the rotation needs no stored cursor.
- [x] `FlowRunMapper::queuedForFlow()` — one flow's queued runs, oldest first.
- [x] `FlowRunMapper::expireQueuedBefore()` — fail queued runs older than a
      cut-off, capped, re-checking status per row before writing.
- [x] `FlowRunWorker::expireStaleQueued()` — runs before the queue is drained;
      a throw is logged and never costs the pass its drain.
- [x] `flow_run_queued_ttl_hours` app config (default 24, `0` disables).
- [x] Tests: a single run is not starved behind a 9,000-run backlog; the batch
      is shared between flows and never exceeded; one waiting flow is unchanged;
      more flows than slots still claims work; stale runs are failed with a
      readable reason; fresh runs are untouched; expiry executes nothing;
      `0` disables expiry; the configured window reaches the query; a throwing
      expiry still drains.
- [x] The 9,644-run backlog on the dev instance is resolved BY the TTL, not by a
      migration or a one-off `DELETE` — same outcome, auditable row by row, and
      the next bulk burst self-heals too.

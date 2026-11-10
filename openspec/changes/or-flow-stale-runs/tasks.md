# Tasks: or-flow-stale-runs

- [x] `FlowRunMapper::findStale()` — `running` runs older than a cut-off, oldest first.
- [x] `FlowRunWorker::reapStale()` — first step of each pass; marks abandoned runs
      `failed` with a reason naming the window and pointing at retry.
- [x] `flow_run_stale_minutes` app config (default 15, `0` disables).
- [x] Tests: an abandoned run is failed with a readable reason; it is NEVER
      requeued or re-executed; the window is configurable and reaches the query
      as a cut-off; `0` switches the reaper off entirely.
- [x] Fixed pre-existing findings in the touched file while here: the
      fully-qualified `FlowResolverRegistry` and `\stdClass` now have imports,
      and `FlowItems::item` carries a reasoned StaticAccess suppression.
- [ ] Backfill the 68 rows already stuck on the dev instance — deliberately NOT
      a migration: the reaper fails them on its next pass, which is the same
      outcome with none of the risk of a data migration over live rows.

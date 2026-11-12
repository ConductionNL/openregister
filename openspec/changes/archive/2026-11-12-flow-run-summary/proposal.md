---
kind: code
---

# Proposal: flow-run-summary

## Summary

A flow list shows, per flow, how its last run ended and how many runs it had
this week. Today nothing answers that without paging through every run. This
change adds `GET /api/flow-runs/summary?flowIds=a,b&since=<ISO 8601>`, which
returns per flow the newest visible run (uuid, status, error, created, updated)
and the number of visible runs created since the moment, and lets the run
history read narrow to several flows (`flowIds`) and a start moment (`since`)
so a "runs this week" page can list exactly the runs the summary counted.

## Why

The shared flows index in `@conduction/nextcloud-vue` (board PqFlows, used by
every app's Flows page) draws "Laatste uitvoering" and "Uitvoeringen deze
week" columns, a "Met fouten" filter and a "Uitvoeringen deze week" tab. The
run history read returns at most 200 rows with no time filter, so a client
deriving those numbers either undercounts or loads every run log.

## Scope

- `FlowRunMapper::summarizeByFlow()`, `countSinceByFlow()`,
  `latestRunsByFlow()`; `findAllRuns()` gains `since` and `flowIds`.
- `FlowRunController::summary()` and the `since`/`flowIds` parameters on
  `index()`, scoped exactly like `index()`.
- Route `flowRun#summary`, declared above the `{uuid}` route.

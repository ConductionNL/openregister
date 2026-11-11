---
kind: code
depends_on: [flow-task-entity]
---

# Proposal: tasks-progress-report

## Summary

A team lead opens a flow and sees how its work is going: per flow and per step,
how many items are open, how many are done, how many are overdue, and how long
a step takes on average. The same numbers come from one API, so buildiq, dossiq
or a dashboard can show them without counting tasks themselves. From a number a
lead can click through to the tasks behind it in the task inbox. The report
counts at the source, over a bounded time window, and never lists rows it does
not need.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| buildiq | logic-task-deadline-warning | Get warned when a workflow task is about to miss its deadline, and report on workflow progress. | partial |

Row `logic-task-deadline-warning` in buildiq's matrix, owned here because
`built.owner` is ConductionNL/openregister. The first half, the deadline
warning, shipped in buildiq#937 on Open Register's derived `overdue` and
`daysUntilDue`. This change closes the second half, workflow progress
reporting.

Demand rows:

- tender, VGGM wens W4, https://www.tenderned.nl/aankondigingen/overzicht/310787

Competitor yes cells, quoted from the packet:

- Mendix: "docs-only: https://docs.mendix.com/refguide/user-task/ (2026-09-26):
  user tasks have a due date, timer boundary events fire after a duration or at
  a set time to escalate, and Workflow Commons dashboards report tasks completed
  within deadline Reached on: Studio Pro workflow editor; Workflow Commons
  dashboards". Evidence: https://docs.mendix.com/refguide/user-task/

## Why

The pieces a progress report needs are all stored, and nothing adds them up:

- Overdue is derived once, from `COALESCE(due_at, expires_at)`, in
  `lib/Service/Task/TaskTemporalProjection.php:69-100` and
  `TaskMapper::countOverdueOpen()` (`lib/Db/TaskMapper.php:864-879`).
- `TaskMetricsProvider` publishes one number for the whole instance,
  `tasks_overdue_total`, with no labels
  (`lib/Service/Task/TaskMetricsProvider.php:69-81`). It cannot say which flow
  or step is behind.
- `TaskInboxService::row()` puts `overdue` and `daysUntilDue` on each row
  (`lib/Service/Task/TaskInboxService.php:153-168`), which is a list, not a
  report. The inbox criteria filter on a run but not on a flow or a step
  (`lib/Db/TaskInboxCriteria.php:118-133`).
- A task knows its run and node (`lib/Db/Task.php` `runUuid`, `nodeId`); a run
  knows its flow (`lib/Db/FlowRun.php:190`) and status (`:110-143`); every node
  execution is a `FlowRunStep` row with `flowId`, `nodeId`, `status`,
  `started`, `finished` and `durationMs` (`lib/Db/FlowRunStep.php:108-160`).
- The flow routes list and inspect runs one at a time (`appinfo/routes.php:2062-2077`)
  and there is no aggregate anywhere.

buildiq's matrix records the result: "no buildiq page reports workflow
progress".

## What changes

- `GET /api/flows/{id}/progress` returns, for one flow over a window
  (default the last 90 days, at most 366): run counts by status, the average
  run duration, and per step the open, done and overdue task counts, the
  average task duration, and for automatic steps the executions, failures and
  average duration.
- `GET /api/flows/progress` returns the same totals per flow for all flows the
  caller may read, paginated.
- The task inbox gains `flow` and `node` filters, so every number links to the
  tasks behind it.
- `TaskMetricsProvider` publishes open and overdue task gauges labelled by
  flow, capped in cardinality, for operators who chart in Grafana.
- A Workflow progress page in Open Register at `/flows/:id/progress`, linked
  from the flow detail sidebar.

## Consumers

- buildiq (logic-task-deadline-warning): a progress card on a built app's
  dashboard reading `GET /api/flows/{id}/progress`. The card is buildiq's.
- dossiq and decidiq run their case and decision flows on the same engine and
  can read the same endpoint. Neither row is closed here.

## ADRs

- hydra ADR-058 (bounded object queries): aggregates only, a required window, a
  capped page.
- hydra ADR-006 (metrics): the gauges follow the metrics provider contract.
- hydra ADR-022: apps read one report API instead of counting tasks.
- hydra ADR-031: the aggregation is imperative, see design.
- hydra ADR-065: one flow engine, so one report over it.
- openregister ADR-002 (organisation tenancy): runs and tasks are counted
  inside the caller's organisation.

## Impact

- New capability `flow-progress-report`.
- Affected code: a new `lib/Service/Flow/FlowProgressService.php`, two routes
  on `FlowController` (or a new `FlowProgressController`), `TaskMapper` and
  `FlowRunStepMapper` aggregate queries, `TaskInboxCriteria` and
  `TaskInboxService` (two filters), `TaskMetricsProvider`, a migration adding
  `(flow_id, created)` on `openregister_flow_runs`, `src/views/flows/FlowProgress.vue`,
  `src/manifest.json`, `src/registry.js`, `src/views/flows/FlowDetailSidebar.vue`.
- Backwards compatible: new endpoints, new optional filters, new gauges.
- Size: M.

## Out of scope

- The deadline warning itself, shipped in buildiq#937.
- Service-level norms per step and alerts when a step breaches one. That is a
  threshold over these numbers and belongs with `saved-view-count-alert` style
  alerting, not in the report.
- Business-hours durations. The report measures wall-clock time;
  `the-engine-measures-elapsed-business-hours` owns business time.
- buildiq's dashboard card.

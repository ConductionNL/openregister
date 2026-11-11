# Design: tasks-progress-report

Read at openregister development c53dd0685c.

## D-1: one service, three sources

A new `lib/Service/Flow/FlowProgressService.php` answers
`forFlow(Flow $flow, DateTimeInterface $from, DateTimeInterface $to): array`
and `forFlows(array $flowIds, ...)`. It reads three tables that already exist
and adds nothing to them:

| number | source | rule |
|---|---|---|
| runs by status | `openregister_flow_runs` (`lib/Db/FlowRun.php:190`, `:214`) | `created` in the window, grouped by `status` |
| run duration | `openregister_flow_run_steps` (`lib/Db/FlowRunStep.php`) | per completed run, `MAX(finished) - run.created`; runs whose steps retention already pruned are counted in `durationUnknown`, not averaged as zero |
| per step, human | `openregister_tasks` joined to runs on `run_uuid` (index `or_tasks_run`, `lib/Migration/Version1Date20260831120000.php:274`) | grouped by `node_id`: open (`is_terminal = false`), done (terminal in the window), overdue (open and `COALESCE(due_at, expires_at) < now`), average of `completed_at - created` for done |
| per step, automatic | `openregister_flow_run_steps` (index `or_flowstep_flow_idx` on `flow_id, id`) | grouped by `node_id`: executions, `status = failed`, average `duration_ms` for `status = ok` |

Overdue uses the one rule: the aggregate calls `TaskMapper::applyOverdue()`,
the same private helper `countOverdueOpen()` uses (`lib/Db/TaskMapper.php:836`, called at `:868`),
so the report cannot disagree with the inbox's `overdue` flag
(`lib/Service/Task/TaskTemporalProjection.php:69-100`).

Each step row carries the node's display name from the flow definition, so a
reader sees "Legal review", not `node-7`.

## D-2: two routes, the same access as reading the flow

- `GET /api/flows/{id}/progress?from=&to=` resolves the flow through
  `FlowService::find()` (`lib/Service/Flow/FlowService.php:190`), which refuses
  a flow outside the caller's active organisation. That is exactly the access
  `GET /api/flows/{id}` gives today (`lib/Controller/FlowController.php:743-752`,
  no action right), so a person who can open the flow can see its progress.
- `GET /api/flows/progress?from=&to=&_page=&_limit=` returns per-flow totals
  (no per-step rows) for the flows `GET /api/flows` lists for the caller,
  `_limit` at most 50.

It does not use `flow.read`. That right is checked by `denyUnless()` for BPMN
export and versions (`FlowController.php:599`, `:1054`) but has no entry in
`lib/actions.seed.json`, and an action with no entry denies. Guarding the report
on it would refuse every team lead who is not an administrator, on every
instance, while the same lead can read the whole flow definition.

Both refuse a window longer than 366 days or with `from` after `to` with 400
naming the parameter. Registered above `/api/flows/{id}` in `appinfo/routes.php`
(the `{id}` route is at `:862`), so `progress` is never read as a flow id.

## D-3: from a number to the tasks

`TaskInboxCriteria` (`lib/Db/TaskInboxCriteria.php:118-133`) gains `flowId` and
`nodeId`, applied in `TaskMapper::applyFilters()` (`lib/Db/TaskMapper.php:785`) through the run join, and
`GET /api/flow-tasks` reads `flow` and `node`. The report returns, per step, a
`tasksHref` such as `/api/flow-tasks?scope=all&flow={id}&node={nodeId}&overdue=true`.
The inbox's own scope rules decide what the reader then sees: a lead who may
not see a task sees it counted and not listed, which is what an aggregate is.

## D-4: gauges for operators

`TaskMetricsProvider` (`lib/Service/Task/TaskMetricsProvider.php:69-81`) keeps
`tasks_overdue_total` and adds two gauges, `tasks_open_by_flow` and
`tasks_overdue_by_flow`, labelled `flow`. To keep label cardinality bounded,
only the 100 flows with the most open tasks get a series; the rest are summed
under `flow="other"`. The declaration goes in `src/manifest.json` beside the
existing provider entry, as the class docblock requires for `METRIC_NAME`.

## D-5: where a person sees it

A custom page `FlowProgress` at `/flows/:id/progress`, registered in
`src/registry.js` beside `FlowDetailSidebar` (`:86`) and in `src/manifest.json`.
It shows a small run summary and one table, a row per step, with the counts as
links (D-3) and durations in hours and days. `src/views/flows/FlowDetailSidebar.vue`
renders a "Workflow progress" link under `CnFlowSidebar`. The page reads only
the API, so a leaf app's card and this page cannot show different numbers.

## Declarative-vs-imperative decision

Imperative. ADR-031 prefers a declared aggregation, and the declarative metric
filter compares one column to a literal, which cannot express the two-column
effective deadline or the clock; `TaskMetricsProvider` records the same reason
(`:6-15`). The report also joins tasks to runs to steps, which no schema
aggregation reaches because none of these tables are OpenRegister objects.

## Risks

- Performance (hydra ADR-058): every query is a grouped aggregate over an
  indexed path, inside a window. A new index `(flow_id, created)` on
  `openregister_flow_runs` keeps the window filter off a scan of every run the
  flow ever had; the existing `or_flowrun_flow_idx` is `(flow_id, id)`
  (`lib/Migration/Version1Date20260724120000.php:101`). A test asserts the
  query count per call is constant in the number of runs.
- Multitenancy (openregister ADR-002): runs are filtered on the caller's
  organisation as well as the flow, so a flow shared across organisations never
  reports another organisation's work.
- Disclosure: counts only. The report carries no titles, assignees or
  subjects, so a person who may read a flow learns volume, not content.

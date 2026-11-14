# Workflow progress

## Overview

A team lead opens a flow and sees how its work is going: per step, how many
tasks are open, how many are overdue, how many were completed and how long a
step takes on average. The same numbers come from one API, so another app or a
dashboard can show them without counting tasks itself. From a number you click
through to the tasks behind it in the task inbox.

The report counts at the source, over a time window of at most 366 days (the
last 90 by default), and returns counts and durations only: never task titles,
assignees or subjects.

## Where you see it

Open a flow's overview (**Flows**, then a row) and choose **Workflow progress**.
The page at `/flows/{id}/progress` shows:

- **Runs**: the runs created in the window, by status, and the average duration
  of a completed run (from its start to the end of its last step). A completed
  run whose step history was already removed by retention counts as unknown; it
  is not averaged as zero.
- **Steps**: a row per step of the flow, with **Open**, **Overdue** and
  **Completed** tasks and their **Average duration** for a step a person does,
  and **Runs** and **Failed** for an automatic step.

Each task count links to the task inbox filtered to that flow and step.

## The API

| Request | Answers |
| --- | --- |
| `GET /api/flows/{id}/progress?from=&to=` | One flow: runs by status, run duration, a row per step |
| `GET /api/flows/progress?from=&to=&_page=&_limit=` | Per flow the caller may read: runs by status, open and overdue tasks; `_limit` at most 50 |

`from` and `to` take a date or a date and time; a bare `to` date counts to the
end of that day. A window longer than 366 days, or with `from` after `to`, is
refused with 400 and a message naming `from`.

Access is the same as reading the flow with `GET /api/flows/{id}`: the
`flow.read` right, and the flow in your active organisation. Another
organisation's flow answers 404. Only runs and tasks of your organisation are
counted.

Each step row carries `tasksHref`, the task inbox read that lists its overdue
tasks:

```
/api/flow-tasks?scope=all&flow={flow}&node={node}&overdue=true&isTerminal=false
```

and `openTasksHref` and `doneTasksHref` for the open and completed ones. The
inbox (`GET /api/flow-tasks`) takes `flow` and `node` as filters; its own scope
and visibility rules still decide which tasks you see, so a step can count a
task you are not allowed to list.

Overdue is the inbox's own rule: an open task whose effective deadline (its due
date, else its expiry) has passed.

## For operators

`GET /api/metrics` publishes two gauges labelled `flow`:

- `openregister_tasks_open_by_flow`: open tasks per flow.
- `openregister_tasks_overdue_by_flow`: overdue open tasks per flow.

To keep label cardinality bounded, the 100 flows with the most open tasks each
get a series and the rest are summed under `flow="other"`.

# flow-progress-report

## ADDED Requirements

### Requirement: A flow reports its progress per step

`GET /api/flows/{id}/progress` SHALL return, for one flow and a window of at
most 366 days (default the last 90), the number of runs per status, the
average run duration, and for each step: open, done and overdue task counts
and the average task duration for human steps, and executions, failures and
average duration for automatic steps. Overdue SHALL use the same effective
deadline as the task inbox. A run whose step rows were pruned SHALL be counted
as duration unknown, not averaged as zero.

#### Scenario: a team lead sees where work is stuck

- **GIVEN** a team lead, not an administrator, in the organisation that owns a permit flow with steps "Intake" and "Legal review"
- **AND** in the last 90 days three runs, two of them waiting at "Legal review" with one task past its due date
- **WHEN** the lead calls `GET /api/flows/{id}/progress`
- **THEN** the response is 200 and the "Legal review" step reads open 2, overdue 1
- **AND** the step carries a `tasksHref` that lists exactly that overdue task for an administrator
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/flow-progress.spec.ts}

#### Scenario: a window that is too long is refused

- **GIVEN** the same lead
- **WHEN** they call `GET /api/flows/{id}/progress?from=2025-01-01&to=2026-09-01`
- **THEN** the response is 400 and its message names `from`
- @e2e exclude {specified only; task 2.1 adds the controller test, task 4.2 adds tests/e2e/ci/flow-progress.spec.ts}

### Requirement: The report respects organisations and shows no content

The progress endpoints SHALL give the same access as reading the flow with
`GET /api/flows/{id}`, SHALL answer 404 for a flow outside the caller's active
organisation, and SHALL count only runs
and tasks of the caller's organisation. They SHALL return counts and durations
only, never task titles, assignees or subjects.

#### Scenario: another organisation's flow is not found

- **GIVEN** a user in organisation A and a flow owned by organisation B
- **WHEN** the user calls `GET /api/flows/{id}/progress` for that flow
- **THEN** the response is 404
- @e2e exclude {specified only; task 2.1 adds the controller test, task 4.2 adds tests/e2e/ci/flow-progress.spec.ts}

### Requirement: All flows can be compared on one list

`GET /api/flows/progress` SHALL return, per flow the caller may read, the run
counts by status and the open and overdue task totals, paginated with
`_page` and `_limit` (at most 50).

#### Scenario: a manager compares flows

- **GIVEN** a manager in an organisation with 60 flows
- **WHEN** they call `GET /api/flows/progress?_limit=50`
- **THEN** the response lists 50 flows with their totals and `total` 60
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/flow-progress.spec.ts}

### Requirement: A person sees the report on the flow

Open Register SHALL show a Workflow progress page at `/flows/:id/progress`,
linked from the flow detail sidebar, with a row per step whose counts link to
the matching tasks in the task inbox.

#### Scenario: from the flow to the overdue tasks

- **GIVEN** the team lead on the permit flow's detail page
- **WHEN** they choose "Workflow progress" and then the overdue count on "Legal review"
- **THEN** the task inbox opens filtered to that flow and step, showing the overdue task
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/flow-progress.spec.ts}

### Requirement: Operators can chart open and overdue work per flow

The metrics endpoint SHALL publish open and overdue task gauges labelled by
flow, for at most 100 flows, with the remainder summed under one label.

#### Scenario: label cardinality stays bounded

- **GIVEN** an instance with open tasks in 120 flows
- **WHEN** an administrator reads `GET /api/metrics`
- **THEN** `openregister_tasks_open_by_flow` has 101 series, the last labelled `flow="other"`
- @e2e exclude {specified only; task 3.1 adds TaskMetricsProviderTest, task 4.2 adds tests/e2e/ci/flow-progress.spec.ts}

# Tasks: tasks-progress-report

## 1. Aggregates

- [ ] 1.1 Migration adding `(flow_id, created)` on `openregister_flow_runs`; grouped aggregate methods on `FlowRunMapper`, `FlowRunStepMapper` and `TaskMapper` reusing `applyOverdue()`. Verify: mapper tests asserting counts on seeded rows and a constant query count for 10 and 1,000 runs.
- [ ] 1.2 `FlowProgressService` composing D-1, including `durationUnknown` for pruned steps and node display names. Verify: `tests/Unit/Service/Flow/FlowProgressServiceTest.php`.

## 2. API

- [ ] 2.1 `GET /api/flows/{id}/progress` and `GET /api/flows/progress` with the same organisation-scoped access as `GET /api/flows/{id}` and window validation. Verify: controller tests for 200 for a non-admin organisation member, 404 for another organisation's flow, 401 without a session, 400 for a 400-day window.
- [ ] 2.2 `flow` and `node` filters on `TaskInboxCriteria` and `GET /api/flow-tasks`, and `tasksHref` on each step. Verify: `TaskInboxServiceTest` and a mapper test for the join.

## 3. Metrics

- [ ] 3.1 `tasks_open_by_flow` and `tasks_overdue_by_flow` in `TaskMetricsProvider` with the 100-flow cap, declared in `src/manifest.json`. Verify: `TaskMetricsProviderTest` with 120 flows yields 101 series.

## 4. Page, tests and docs

- [ ] 4.1 `FlowProgress.vue` at `/flows/:id/progress`, registered in `src/registry.js` and `src/manifest.json`, linked from `FlowDetailSidebar.vue`; text through `t()` in en and nl. Verify: `npm run lint` and a component test rendering a step row.
- [ ] 4.2 Add `tests/e2e/ci/flow-progress.spec.ts`: run a two-step flow three times, leave one task overdue, open the progress page and the API, follow the overdue link to the inbox.
- [ ] 4.3 Document the report in `docs/features/`, with a screenshot of the progress page.

Acceptance:

- The overdue count for a step equals the number of rows `GET /api/flow-tasks?scope=all&flow=&node=&overdue=true` returns to an administrator.

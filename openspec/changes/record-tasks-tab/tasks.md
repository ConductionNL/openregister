# Tasks: record-tasks-tab

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Cross-repo prerequisite

- [ ] 1.1 nextcloud-vue: `CnTasksTab` gains `source: "flow-tasks"` (design D-1, D-2, D-4), on top of `cn-tasks-entity-source`. Bump `@conduction/nextcloud-vue` to that release.

## 1b. Backend: the per-row `can` list

- [x] 1.2 `lib/Service/Task/TaskInboxService.php` `row()`: add `can`, built by calling `TaskAuthorizationService::assertMay` per verb (`claim`, `unclaim`, `assign`, `reassign`, `delegate`, `offer`, `resolve`, `complete`, `cancel`), catching `TaskAccessDeniedException`, then dropping verbs the state refuses (terminal: none; `claim`, `offer` need no assignee; `unclaim`, `delegate` need one) (design D-4). The caller's groups are resolved once per request. `TaskController::show` returns the same `can`. Verify: `tests/Unit/Service/Task/TaskInboxServiceCanTest.php` covers assignee, requester, pool member, admin, a stranger and a terminal task.
  - Built: `lib/Service/Task/TaskVerbList.php` asks `TaskAuthorizationService::assertMay` per verb and catches only `TaskAccessDeniedException`, then applies the state rules. `TaskInboxService::row()` adds `can` whenever the row is read for someone (`InboxListing::$caller`: the inbox passes its criteria uid, `TaskController::show` its caller via `enrich(task, caller)`); a row read for nobody (notification, job) carries no `can` key, so their payloads are unchanged. `tests/Unit/Service/Task/TaskInboxServiceCanTest.php` (9 tests) derives each expected list from `TaskAuthorizationService::RULES`.
  - Groups: not a second cache. Every membership question goes through `IGroupManager`, whose per-request user-group cache answers the repeated `isInGroup`/`isAdmin` calls a page of rows makes.
- [ ] 1.3 Newman: `GET /api/flow-tasks?objectUuid=` as assignee and as requester; assert each row's `can`, then run one listed verb and one unlisted verb and assert 200 and 403.

## 2. Record page

- [ ] 2.1 `src/views/object/ObjectDetails.vue`: a Tasks tab with `CnTasksTab source="flow-tasks"` and the record's register, schema and uuid; badge with the open count; empty state pointing to the Integrations tab for personal reminders (D-3). Verify: `ObjectDetails.spec.js` renders the tab with the flow-task source and a create body with the flat keys of design D-2.
- [ ] 2.2 `tests/e2e/ci/record-tasks-tab.spec.ts`: create a task for another user with a due date, see it on the tab and in that user's `/flow-tasks` inbox; complete it as the assignee; a non-assignee sees no Complete.

## 3. Close

- [ ] 3.1 `docs/`: "Put a task on a record".
- [ ] 3.2 `@spec` tags; `openspec validate record-tasks-tab --strict`; set row `rec-tasks` to built once 2.2 passes.

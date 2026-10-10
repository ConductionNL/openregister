# Tasks: flow-task-search-provider

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 5. -->

## 1. Query

- [ ] 1.1 `lib/Db/TaskInboxCriteria.php` gains `term`; the inbox query matches title and description per D-2. Verify: `TaskInboxServiceTest` with an accent and a case difference, and the admin narrowing of D-1.

## 2. Provider

- [ ] 2.1 `lib/Search/FlowTasksProvider.php` (`IFilteringProvider`, id `openregister_flow_tasks`): results per D-3 and D-4, cursor paging; registered in `lib/AppInfo/Application.php` beside `ObjectsProvider`. Verify: `tests/Unit/Search/FlowTasksProviderTest.php` for a match, a refusal and a deep-link override.
- [ ] 2.2 `tests/e2e/ci/flow-task-search.spec.ts`: create a task for the test user, search it in the top bar, open it. (live pass, decision 139)

## 3. Close

- [ ] 3.1 `docs/`: tasks in the search bar. Tell dossiq the change name for row 9.12.
- [ ] 3.2 `@spec` tags; `openspec validate flow-task-search-provider --strict`.

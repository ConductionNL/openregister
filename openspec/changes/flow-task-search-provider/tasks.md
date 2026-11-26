# Tasks: flow-task-search-provider

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 5. -->

## 1. Query

- [x] 1.1 `lib/Db/TaskInboxCriteria.php` gains `term`; the inbox query matches title and description per D-2. Verify: `TaskInboxServiceTest` with an accent and a case difference, and the admin narrowing of D-1.

## 2. Provider

- [x] 2.1 `lib/Search/FlowTasksProvider.php` (`IFilteringProvider`, id `openregister_flow_tasks`): results per D-3 and D-4, cursor paging; registered in `lib/AppInfo/Application.php` beside `ObjectsProvider`. Verify: `tests/Unit/Search/FlowTasksProviderTest.php` for a match, a refusal and a deep-link override.
- [ ] 2.2 `tests/e2e/ci/flow-task-search.spec.ts`: create a task for the test user, search it in the top bar, open it. (live pass, decision 139)
  - 2026-11-25: spec written and run on a sandbox Nextcloud 34 on MariaDB. On main it fails before reaching this provider: `GET /ocs/v2.php/search/providers` answers 500 ('Filter name is already used', `ObjectsProvider` redefines a built-in filter), so the header search hangs on 'Searching …' for every app. With openregister#11164 applied it passes (1 passed): the hit under the task title links to `/apps/openregister/flow-tasks/{uuid}` and opens the task page. Tick when #11164 is on main.

## 3. Close

- [x] 3.1 `docs/`: tasks in the search bar. Tell dossiq the change name for row 9.12. Docs written (`docs/features/search-and-faceting.md`, `docs/features/deep-link-registry.md#task-routes`); dossiq registers its task route with `taskUrlTemplate`.
- [x] 3.2 `@spec` tags; `openspec validate flow-task-search-provider --strict`.
  - 2026-11-24: every method the build added or changed names a requirement of this change's spec (the provider's methods pointed only at `tasks.md#task-2.1`; the term filter in `TaskMapper`, `TaskInboxCriteria`/`TaskInboxFilter`, the registrar and the task route in the deep-link registry had none). `--strict` refused the one requirement as over 500 characters; it is split into three (what the search finds, what a result carries and links to, never more than the inbox), each with its scenario. `openspec validate --strict`: valid.

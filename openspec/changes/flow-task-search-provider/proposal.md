---
kind: code
depends_on: []
---
# Proposal: flow-task-search-provider

## Summary

A caseworker types "terugbellen Jansen" in Nextcloud's search bar and expects the task "Terugbellen mevrouw Jansen" that is waiting for them. Flow engine tasks have a page of their own (`/apps/openregister/flow-tasks/{uuid}`) but no search provider, so the search bar never finds one and no app can link a search hit to a task. This change registers a unified-search provider for flow tasks.

## Halves this closes

dossiq row 9.12 (dossiq #3341): a search hit on a human task must open it. dossiq consumes OpenRegister's task store and its task page, so the provider is OpenRegister's. No row in OpenRegister's matrix: this is the platform half of a dossiq row.

## What is there

- `GET /flow-tasks/{uuid}` serves the task page (`appinfo/routes.php:2139`, `lib/Controller/TaskController.php:170`), linked from the inbox (`src/manifest.json:228-232`).
- `TaskInboxService::inbox()` (`lib/Service/Task/TaskInboxService.php:115`) answers "what may this user see" in one query with scopes assigned, pooled, watched and all (`lib/Db/TaskInboxCriteria.php:48-63`); it takes no text term.
- OpenRegister registers `ObjectsProvider` and `TimelineEntriesProvider` (`lib/AppInfo/Application.php:1321,1325`).

## What changes

- `TaskInboxCriteria` gains a `term`, matched case and accent insensitively against the task's title and description, inside the inbox query.
- A provider `lib/Search/FlowTasksProvider.php` (id `openregister_flow_tasks`, name "Tasks") returns the tasks the user may see that match: title, a subline with state, due date and the subject's title, the task page as link, open tasks first.
- An app that owns the task's subject may claim the link through the deep link registry, so a dossiq task opens in dossiq's own task view when dossiq registers one; otherwise the OpenRegister task page.

## ADRs

- ADR-098: tasks are OpenRegister's, so is their search.
- ADR-005: the provider shows exactly what the inbox would show the user, through the same query.
- hydra ADR-058: one query, paged with a cursor like `ObjectsProvider`.

## Impact

- Extends `unified-search-provider`.
- Affected code: `lib/Db/TaskInboxCriteria.php`, the inbox query, `lib/Search/FlowTasksProvider.php`, `lib/AppInfo/Application.php`.
- Backwards compatible.
- Size: S.

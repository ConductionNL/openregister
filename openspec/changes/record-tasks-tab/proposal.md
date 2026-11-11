---
kind: code
depends_on: []
---
# Proposal: record-tasks-tab

## Summary

A caseworker opens a record, adds a task "Bel aanvrager terug" for a colleague due Friday, and sees on the same tab every open task on that record with its assignee, due date and state. OpenRegister's task store has carried an assignee, a due date and an anchor on an object since `flow-task-entity`. The record page does not use it: its tasks come from the old VTODO integration, which has no real assignee, and no page creates a task on a record.

## Row this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-tasks | Attach tasks with a due date and an assignee to a record. | partial |

Delivered change: `flow-task-entity` (archived 2026-10-05). Spec round part 2 re-rated the row to specified.

## What is there

- `openregister_tasks` with `assignee`, `due_at` and the anchor `object_uuid`, `register_id`, `schema_id` (`lib/Migration/Version1Date20260831120000.php:196,211,224`).
- `GET /api/flow-tasks?objectUuid=` filters on the anchor (`lib/Controller/TaskController.php:197,227`); `POST /api/flow-tasks` creates, refused with the object's own 404 when the creator may not read it (`:369`); the lifecycle verbs claim, assign, reassign, complete, cancel (`appinfo/routes.php:2139-2153`).
- The task inbox page `/flow-tasks` (`src/manifest.json:229`) and the task detail `src/views/task/FlowTaskDetail.vue`.
- The record page's Integrations tab (`src/views/object/ObjectDetails.vue:413`, `CnIntegrationWidget`) shows the VTODO tasks of `TasksController` (`appinfo/routes.php:1507-1510`).

## What changes

- The record page gets a Tasks tab listing the flow tasks anchored on the record: title, assignee, due date (overdue in red), state, newest open first, completed ones folded under "Done".
- The tab's form creates a task on the record with title, assignee (a user or a group), due date and an optional description, through `POST /api/flow-tasks`.
- Each row offers the verbs the current user may run (claim, complete, reassign, cancel), read from a new per-row `can` list on `GET /api/flow-tasks` (design D-4), and its title opens `FlowTaskDetail`.
- The create form sends the task entity's own flat keys, as `TaskBuilder::fromData` reads them (design D-2).
- The tab badge counts the open tasks.
- The tab is nextcloud-vue's `CnTasksTab` in a flow-task mode (cross-repo, design D-1).

## ADRs

- ADR-098: one task store for the fleet; a record's tasks are flow tasks, not a second kind.
- ADR-022: OpenRegister owns tasks; the tab is a view on them.
- ADR-005: the list shows only tasks the user may see; the verbs are the ones the backend allows.

## Impact

- Extends `flow-tasks`.
- Affected code: `src/views/object/ObjectDetails.vue`. One backend addition: the per-row `can` list in `lib/Service/Task/TaskInboxService.php` and on the single-task read in `lib/Controller/TaskController.php`. Asked for by nextcloud-vue PR #1374 (`tasks-tab-flow-task-source`, design D3).
- The VTODO tasks stay in the Integrations tab; see design D-3.
- Size: S.

## Out of scope

- Task templates and checklists on the tab (they exist on the task detail page).
- Tasks on records in leaf apps' own case pages: those place the same nextcloud-vue tab through their manifest.

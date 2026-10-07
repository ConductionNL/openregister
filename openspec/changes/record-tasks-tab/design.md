# Design: record-tasks-tab

No design board draws OpenRegister's record page; OpenRegister is not one of the canvas apps. The tab follows the task rows of the `/flow-tasks` inbox, so a task looks the same in both places.

## D-1: one tab component, two sources

nextcloud-vue's `CnTasksTab` (`src/components/CnObjectSidebar/CnTasksTab.vue`) reads VTODO tasks from `/api/objects/{register}/{schema}/{id}/tasks`. nextcloud-vue's open change `cn-tasks-entity-source` adds a `tasks` entity source for flow tasks to index pages and widgets. The tab needs the same source: `CnTasksTab` gains `source: "flow-tasks"`, which lists `GET /api/flow-tasks?objectUuid={id}`, creates with `POST /api/flow-tasks` carrying the anchor, and runs the lifecycle verbs. OpenRegister passes `source="flow-tasks"`. No local tab is built.

## D-2: what the form sends

`{title, description, assignee: {type: "user"|"group", id}, dueAt, object: {uuid, register, schema}}`. The assignee picker searches users and groups through Nextcloud's sharee API. A 404 from create (the user lost access to the record) shows "You can no longer open this record" and reloads.

## D-3: the VTODO tasks stay where they are

`flow-task-inbox-projections` projects a flow task into the assignee's calendar as a VTODO and writes completion back. A VTODO created in the Tasks app on a record is a personal reminder, not a flow task. Showing both lists in one tab would show the projected tasks twice. So the Tasks tab shows flow tasks only, and the Integrations tab keeps the Nextcloud Tasks provider for personal reminders. The tab's empty state says where personal reminders live.

## D-4: verbs

Each row shows only the verbs that the task's state and the user's role allow, from the task read. A verb the backend refuses shows the refusal on the row and leaves the row as it was.

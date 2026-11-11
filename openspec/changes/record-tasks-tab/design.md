# Design: record-tasks-tab

No design board draws OpenRegister's record page; OpenRegister is not one of the canvas apps. The tab follows the task rows of the `/flow-tasks` inbox, so a task looks the same in both places.

## D-1: one tab component, two sources

nextcloud-vue's `CnTasksTab` (`src/components/CnObjectSidebar/CnTasksTab.vue`) reads VTODO tasks from `/api/objects/{register}/{schema}/{id}/tasks`. nextcloud-vue's open change `cn-tasks-entity-source` adds a `tasks` entity source for flow tasks to index pages and widgets. The tab needs the same source: `CnTasksTab` gains `source: "flow-tasks"`, which lists `GET /api/flow-tasks?objectUuid={id}`, creates with `POST /api/flow-tasks` carrying the anchor, and runs the lifecycle verbs. OpenRegister passes `source="flow-tasks"`. No local tab is built.

## D-2: what the form sends

The body uses the task entity's own keys, the ones `GET /api/flow-tasks` returns and `TaskBuilder::fromData` (`lib/Service/Task/TaskBuilder.php:69`) reads. Flat, no nesting:

| Key | For a user | For a group |
|---|---|---|
| `title` | required | required |
| `description` | optional | optional |
| `dueAt` | optional, ISO 8601 | optional, ISO 8601 |
| `objectUuid` | the record's uuid | the record's uuid |
| `registerId`, `schemaId` | the record's register and schema ids | same |
| `assignee` | the user's uid | absent |
| `performerType` | absent (the builder defaults to `user`) | `"group"` |
| `candidateGroups` | absent | `["<gid>"]` |

A group task is a pool until someone claims it (`flow-tasks` main spec, "A group task has no assignee until someone claims it"). The form never sends `requester` or `state`: `TaskService::create` pins `requester` to the caller and refuses a terminal state for every non-admin (`lib/Service/Task/TaskService.php:246-252`). `registerId` and `schemaId` are optional to the server, which looks them up from `objectUuid` when missing (`TaskSubjectLocator::withLocation`); the form sends them because the page has them and it saves that lookup.

The nested shape this design sketched before (`assignee: {type, id}`, `object: {uuid, register, schema}`) is not read by `fromData`. It would drop the anchor (no `objectUuid`, so no subject access check and no row on the tab) and cast the assignee object to a string. nextcloud-vue's `tasks-tab-flow-task-source` design D2 already uses the flat keys; both now agree.

The assignee picker searches users and groups through Nextcloud's sharee API. A 404 from create (the user lost access to the record) shows "You can no longer open this record" and reloads.

## D-3: the VTODO tasks stay where they are

`flow-task-inbox-projections` projects a flow task into the assignee's calendar as a VTODO and writes completion back. A VTODO created in the Tasks app on a record is a personal reminder, not a flow task. Showing both lists in one tab would show the projected tasks twice. So the Tasks tab shows flow tasks only, and the Integrations tab keeps the Nextcloud Tasks provider for personal reminders. The tab's empty state says where personal reminders live.

## D-4: verbs come from the row's `can` list

Each row of `GET /api/flow-tasks` carries `can`: the lifecycle verbs the caller may run on that task now. The tab shows exactly those verbs and derives nothing from state, assignee or requester. Without the list the tab would have to copy the rules of `TaskAuthorizationService` and still guess wrong on pool membership, delegation and supervision, which only the server knows.

The list is computed in `TaskInboxService::row` by asking `TaskAuthorizationService::assertMay` once per verb and catching the refusal. That service deliberately has no boolean twin, so a verb lands in `can` only when the very check the verb's endpoint runs would pass. On top of authorization the row applies the state rules the verbs enforce: a terminal task lists nothing, `claim` and `offer` need no assignee, `unclaim` and `delegate` need one. The verbs considered are the ones with a route: `claim`, `unclaim`, `assign`, `reassign`, `delegate`, `offer`, `resolve`, `complete`, `cancel`. Group membership is read once per request, not once per row.

`can` is advice for the screen, not a grant. A verb the backend still refuses (a race, a membership that changed) shows the refusal on the row and leaves the row as it was.

# Put a task on a record

## Overview

A record's page has a **Tasks** tab. It lists the tasks that hang on that
record and lets you add one: "call the applicant back", "check the
attachment", for a colleague or for a team. These are the same tasks as in the
**Tasks** inbox (`/api/flow-tasks`), so a task you add here shows up in the
assignee's inbox, and a task a flow creates on the record shows up here.

The number on the tab is how many tasks on the record are still open.

## Adding a task

Open the record, choose **Tasks** and fill in:

- **Title** (required): what has to be done.
- **Assignee**: a person, or a group. A task for a group is a pool: it has no
  assignee until a member of the group claims it.
- **Due date** (optional): an open task past its due date is marked overdue.
- **Description** (optional).

The task is created with the record as its anchor. You are recorded as the one
who asked; a new task always starts open.

If you can no longer open the record when you save (your access changed), the
tab says so and the task is not created.

## Working on a task

Open tasks come first; finished ones are folded away below them. Each task
offers only the actions you may take on it right now: claim it from a group
pool, give it back, hand it to someone else, complete it or cancel it. Which
actions those are is decided by the server for each task, so a button you do
not see is an action you are not allowed to take, and nothing on the page
guesses at the rules.

An action that is refused anyway (someone else claimed the task a moment
earlier, or your group membership changed) shows the refusal on the task and
leaves it as it was.

## Tasks and personal reminders

The Tasks tab shows work handed to people. A to-do you keep for yourself in the
Nextcloud Tasks app and link to a record is a personal reminder: it stays in the
record's **Integrations** tab. A task that is also projected into the assignee's
calendar appears once, here.

## API

```
GET  /api/flow-tasks?objectUuid={uuid}&scope=all   The tasks on a record, each with a `can` list
POST /api/flow-tasks                               Create a task (title, description, dueAt,
                                                   objectUuid, registerId, schemaId, and
                                                   assignee or performerType=group with
                                                   candidateGroups)
```

Each listed task carries `can`: the actions the caller may run on it now
(`claim`, `unclaim`, `assign`, `reassign`, `delegate`, `offer`, `resolve`,
`complete`, `cancel`). It is advice for the screen; the action's own endpoint
still checks.

## Related Features

- [Flows](flows.md): the `openregister.user-task` step that creates tasks from a
  flow, and the task inbox

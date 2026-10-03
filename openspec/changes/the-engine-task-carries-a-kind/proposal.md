---
kind: capability
---

# Proposal: the-engine-task-carries-a-kind

## Why

Dossiq's `case-reminder-as-task` asks for a reminder you set by hand, for a
named colleague, on a date, and asks for the Tasks index to be able to pick
reminders out of everything else. A reminder is an ordinary engine task, so
the only thing missing is a way to say what sort of work a task is.

There is no field for that today. `metadata` looks like one and is not: the
entity documents it as carried and never interpreted, no lifecycle,
authorization or inbox rule may read it, and it is JSON, so it cannot be
indexed. Writing `kind` into it would be silently dropped from every filter
the consuming app then wrote, which is the failure that looks exactly like
success.

## What changes

- `openregister_tasks` gains `kind`, a short nullable label, indexed with
  `is_terminal` because every question asked of it is about open work.
- `Task` carries it, serialises it, and `TaskBuilder` reads it from the
  create payload.
- `GET /api/flow-tasks?kind=reminder` filters on it, through
  `TaskInboxCriteria` and the same `applyFilters` every other filter uses.

The engine attaches no behaviour to any value. A kinded task moves through
the same lifecycle, is authorized by the same rules, and is notified the
same way.

## Impact

`lib/Db/Task.php`, `lib/Db/TaskInboxCriteria.php`, `lib/Db/TaskMapper.php`,
`lib/Service/Task/TaskBuilder.php`, `lib/Controller/TaskController.php`, one
migration. No existing row changes: null is the ordinary value and it means
work, not unknown.

## Capabilities

- Modified: `flow-tasks`: a task says what sort of work it is, and the inbox
  can be asked for one sort.

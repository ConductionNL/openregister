# Proposal: flow-task-edit

## Why

A task's title, description, due date and priority were fixed at creation: the engine had no way to change them. Leaf
apps draw an Edit action on a task (dossiq DqTaak "Bewerken") and had nothing to call.

## What changes

- `PATCH /api/flow-tasks/{uuid}` changes one or more of `title`, `description`, `dueAt`, `priority`.
- New verb `edit` in `TaskAuthorizationService`: the assignee or a member of the candidate pool; an administrator
  passes as on every verb. Refused on an external task, like the routing verbs.
- Authorization runs before anything is written. A denial is audited and leaves the task as it was: 403 for a caller
  who may read the task, 404 for one who may not (the controller's existing rule, so a uuid cannot be probed).
- The values are validated as on creation: unknown fields, an emptied title, a non-ISO due date, an off-scale priority
  and a due date after the expiry are 400s. The change and its `edit` audit entry, naming the changed fields, are
  written in one transaction.

## Scope

Additive: one route, one verb, one builder method.

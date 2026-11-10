# flow-tasks Delta

**Status**: proposed
**Scope**: openregister
**OpenSpec changes**:

- [flow-task-edit](../../)

## ADDED Requirements

### Requirement: A task's own fields can be edited by the people working it

`PATCH /api/flow-tasks/{uuid}` SHALL change the `title`, `description`, `dueAt` and `priority` it is given and nothing
else. The caller SHALL be the assignee, a member of the candidate pool or an administrator, checked before anything is
written; any other caller SHALL be refused (403 when they may read the task, 404 otherwise) with the task unchanged and
the denial audited. An unknown field, an emptied title, a due date that is not an ISO date or lies after the expiry, and
a priority on no known scale SHALL be refused with 400. A change SHALL be audited as `edit`, naming the changed fields,
in the same transaction.

#### Scenario: The assignee moves the due date

- **GIVEN** a task assigned to alice
- **WHEN** alice sends `{ "dueAt": "2026-10-28", "priority": "high" }`
- **THEN** the task has that due date and priority, and its trail has an `edit` entry naming both fields

#### Scenario: A stranger is refused

- **GIVEN** a task assigned to alice in the pool `reviewers`
- **WHEN** mallory, in neither, sends `{ "title": "Mine now" }`
- **THEN** nothing is written and the denial is audited

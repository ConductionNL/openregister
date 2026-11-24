# Proposal: object-task-reader

## Why

A consuming app that runs an approval chain on OpenRegister's task store has to
show, in its own audit export, who decided each step, on whose behalf and under
which mandate. shillinq's `purchasing-approval-delegation` (task 3.1: decider,
on-behalf-of and mandate columns on the purchase order audit export) is blocked
because OpenRegister publishes no PHP contract to read an object's tasks; the
REST inbox is caller-scoped and not reachable from a server-side export.

## What changes

- `OCA\OpenRegister\Contract\ObjectTaskReaderInterface::tasksOnObject(objectUuid)`,
  published next to `ObjectFileReaderInterface` and bound in `ServiceRegistrar`.
- `OCA\OpenRegister\Contract\ObjectTaskRecord`: uuid, title, workflow step,
  state, terminal, outcome, assignee, decidedBy (`completedBy`), onBehalfOf,
  mandate, decidedAt, createdAt. No entity leaves the app.
- `TaskMapper::findByObjectUuid()`: every task on one object, oldest first.

Like `ObjectFileReaderInterface`, the read applies no rights of its own: the
caller checks its user may see the object before it asks.

## Consumers

- shillinq `purchasing-approval-delegation` task 3.1.

---
kind: code
---

# Proposal: a-task-may-wait-on-another-task

Decision 137 (9 Oct, Ruben, answer to Q-openregister-2). Asked by dossiq's
`task-dependencies-and-the-next-planned-action` tasks 3.1 to 3.3 (its design
D-7 and D-8, requirement REQ-TDP-02). Owner openregister, because the task,
its inbox and its close event are the engine's.

## Why

A caseworker sees tasks in their list that they cannot start yet. The advice
cannot be written before the site visit is done. A list full of tasks that
wait on something else is noise, and noise makes people stop reading it.

dossiq has no task table since `remove-casetask`. Its task page is the
engine's inbox. So dossiq cannot keep a row out of a list it does not build,
and it cannot derive a state on a record it does not own.

## What is actually there

- `Task` has `blockedReason`, a free text, and the legacy state `blocked`
  maps onto `active` plus that reason (`TaskState::LEGACY`). Nothing derives
  it and nothing releases it.
- `relation-types-with-inverses` gives `blocks` and `blocked by` between
  objects. Tasks are not objects.
- `TaskTerminalEvent` fires after the commit of every terminal transition
  (`TaskService::transactional()`). That is the release point.

## What changes

- A task may name the task it waits on: `blockedBy`, a task uuid, accepted
  on create and returned on every read.
- `blocked` is derived on read: the named blocker exists and is not terminal.
  It is never stored and never accepted from a caller.
- The inbox leaves blocked tasks out, in the badge total as well. A read
  anchored to an object or a run still lists them, with the flag, so the
  case shows what waits and on what. `includeBlocked=true` brings them back
  in any inbox read.
- When the blocker reaches a terminal state, each task it blocked gets an
  audit entry `released` and is announced again, so the notification and the
  calendar entry follow without anybody editing the task.
- Create refuses a task that blocks itself, a blocker that does not exist, a
  chain that loops back, and a write of `blocked` or of the state `blocked`.

## Out of scope

- Changing a blocker after creation. There is no task update verb today.
- Refusing work on a blocked task. A blocked task can still be claimed and
  completed by somebody who opens it directly: the block hides it from the
  list, it does not lock it.
- More than one blocker per task.

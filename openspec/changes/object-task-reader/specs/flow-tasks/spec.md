# flow-tasks Delta: object-task-reader

## ADDED Requirements

### Requirement: A consuming app reads the tasks on its object

OpenRegister SHALL publish `ObjectTaskReaderInterface::tasksOnObject(objectUuid)`
in `OCA\OpenRegister\Contract`, answering every task on that object, open and
finished, oldest first, as `ObjectTaskRecord` values carrying the task's uuid,
title, workflow step, state, whether it is finished, its outcome, its current
assignee, who finished it, the original performer of a delegated task
(`onBehalfOf`), the mandate the delegation relied on, and when it was finished
and created. The read SHALL apply no rights of its own (its caller checks the
object), and an object without tasks, or a blank uuid, SHALL answer an empty
list.

#### Scenario: An app exports a delegated approval

- **GIVEN** purchase order `po-040` whose team lead step Karin decided on behalf of Jan under the mandate "Absence of the team lead, mandate 2026-07"
- **WHEN** the app asks `tasksOnObject("po-040")`
- **THEN** the record of that step has `decidedBy` karin, `onBehalfOf` jan and that mandate, and the facility manager step decided directly by Pieter has no `onBehalfOf`

#### Scenario: An open step has no decision yet

- **GIVEN** an open step held by Lars
- **THEN** its record is not terminal, names Lars as assignee and has no `decidedBy`, `decidedAt` or outcome

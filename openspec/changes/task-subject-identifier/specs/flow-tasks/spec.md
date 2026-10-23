# flow-tasks Delta

**Status**: proposed
**Scope**: openregister
**OpenSpec changes**:

- [task-subject-identifier](../../)

## ADDED Requirements

### Requirement: The task subject context carries the subject's identifier

A task row's `subject` SHALL carry `identifier`, the subject object's own `identifier` property when that is a
non-empty string or number, and null otherwise. It SHALL be read in the same batch as the subject's title.

#### Scenario: A case task

- **GIVEN** a task on a case whose `identifier` is "2026-0074"
- **WHEN** the inbox lists the task
- **THEN** its `subject.identifier` is "2026-0074"

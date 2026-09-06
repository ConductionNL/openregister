## MODIFIED Requirements

### Requirement: The outcome is written onto every item, not only onto the run

The outcome bag a user-task step places on its items SHALL include the
performer's form answers, under the key `answers`, taken from the task's
recorded responses.

An empty or absent set of responses SHALL yield an empty `answers`, never a
missing key. A downstream expression reading `answers.reason` must fail the
same way whether the form was skipped or the step declared none, rather than
failing differently and teaching authors to guard for both.

This closes a gap rather than adding a feature: a step could already declare
a form, the values were already validated against the subject schema and
already stored on the task, and the portal-task node already places them on
its bag. Only this node's bag omitted them, so every answer a person typed
was collected and then discarded.

#### Scenario: A form answer reaches the following step

- **GIVEN** a user-task step declaring the fields `reason` and `amount`
- **WHEN** the performer completes it, filling both
- **THEN** the items leaving the step MUST carry `answers.reason` and
  `answers.amount`
- **AND** a Switch on `answers.reason` MUST be able to route on it

#### Scenario: A step with no form yields an empty answers set

- **GIVEN** a user-task step declaring no form
- **WHEN** it is completed
- **THEN** the bag MUST carry `answers` as an empty set, not omit it

---

### Requirement: A step may attach its task to a declared subject

A user-task step SHALL accept an `attachTo` naming a subject role of its run.
When it is given, the created task SHALL be anchored to that entry through the
task's existing object fields.

Naming a role the run does not hold SHALL fail the step, per the subject
capability. It SHALL NOT create an unattached task: a task attached to nothing
is exactly the task an author believed was attached to the case.

A step naming no `attachTo` SHALL create the task with no object anchor, as it
does today.

The anchor SHALL use the task row's existing object fields rather than a new
one, so the subject-anchored inbox read, the case sidebar and the portal
visibility rule all keep working with no change.

#### Scenario: A go is recorded against the case, the run and the person

- **GIVEN** a run holding a case under the role `case`
- **AND** a user-task step with `attachTo: case`
- **WHEN** the step creates its task
- **THEN** the task MUST carry the run uuid, the node id, and the case's
  register, schema and uuid
- **AND** the task MUST appear in the case's subject-anchored task list

#### Scenario: Attaching to a role the run never recorded fails the step

- **GIVEN** a run holding no `case` entry
- **AND** a step with `attachTo: case`
- **WHEN** the run reaches it
- **THEN** the step MUST fail naming the role
- **AND** no task MUST be created

# flow-tasks

## ADDED Requirements

### Requirement: A record's page lists and creates the flow tasks anchored on it

OpenRegister's record page SHALL have a Tasks tab that lists the flow tasks whose anchor is the record, with title, assignee, due date, state and an overdue mark, open tasks first and finished ones folded away. The tab SHALL create a task on the record with a title, an assignee that is a user or a group, an optional due date and an optional description, through the task create route with the record as anchor. Each row SHALL offer only the lifecycle verbs the current user may run on that task, and the tab SHALL show the number of open tasks as its badge.

#### Scenario: a caseworker hands a callback to a colleague

- **GIVEN** a caseworker who may read a record and a colleague `jan`
- **WHEN** the caseworker adds a task "Bel aanvrager terug" for `jan`, due Friday, on the record's Tasks tab
- **THEN** a flow task exists with that assignee, due date and the record as anchor
- **AND** it appears on the tab and in `jan`'s task inbox
- @e2e exclude {specified only; task 2.2 adds tests/e2e/ci/record-tasks-tab.spec.ts}

#### Scenario: an overdue task stands out

- **GIVEN** an open task on the record whose due date has passed
- **WHEN** a user opens the Tasks tab
- **THEN** the task's due date is marked overdue
- @e2e exclude {specified only; covered by the component test in nextcloud-vue}

#### Scenario: a verb the user may not run is not offered

- **GIVEN** a task assigned to another user, which the current user may see but not complete
- **WHEN** the current user opens the Tasks tab
- **THEN** the row offers no Complete action
- @e2e exclude {specified only; task 2.2 covers it}

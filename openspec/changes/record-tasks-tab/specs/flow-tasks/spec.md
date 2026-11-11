# flow-tasks

## ADDED Requirements

### Requirement: A record's page lists and creates the flow tasks anchored on it

OpenRegister's record page SHALL have a Tasks tab that lists the flow tasks whose anchor is the record, with title, assignee, due date, state and an overdue mark, open tasks first and finished ones folded away. The tab SHALL create a task on the record with a title, an assignee that is a user or a group, an optional due date and an optional description, through `POST /api/flow-tasks` with the task entity's own flat keys: `title`, `description`, `dueAt`, `objectUuid`, `registerId`, `schemaId`, and either `assignee` (a uid) or `performerType: "group"` with `candidateGroups`. It SHALL NOT send `requester` or `state`. Each row SHALL offer exactly the verbs in that row's `can` list, and the tab SHALL show the number of open tasks as its badge.

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

#### Scenario: a task for a group is a pool

- **GIVEN** a caseworker on a record's Tasks tab
- **WHEN** the caseworker adds a task for the group `backoffice`
- **THEN** the create body carries `performerType: "group"`, `candidateGroups: ["backoffice"]`, no `assignee`, and the record's `objectUuid`, `registerId` and `schemaId`
- **AND** the task appears on the tab with no assignee, and a member of `backoffice` sees Claim on it
- @e2e exclude {specified only; task 2.2 adds tests/e2e/ci/record-tasks-tab.spec.ts}

### Requirement: Each task row says which verbs the caller may run on it

Every row that `GET /api/flow-tasks` returns SHALL carry `can`, a list of the lifecycle verbs (`claim`, `unclaim`, `assign`, `reassign`, `delegate`, `offer`, `resolve`, `complete`, `cancel`) the caller may run on that task now. A verb SHALL be listed only when `TaskAuthorizationService::assertMay` admits the caller for it and the task's state admits the verb: a terminal task SHALL list none, `claim` and `offer` SHALL require no assignee, `unclaim` and `delegate` SHALL require one. `GET /api/flow-tasks/{uuid}` SHALL carry the same list. The list SHALL NOT change what the verb endpoints decide: a listed verb the endpoint refuses SHALL still be refused.

#### Scenario: the assignee may complete

- **GIVEN** an open task assigned to `jan`, requested by `annemarie`
- **WHEN** `jan` lists `/api/flow-tasks?objectUuid=<record>`
- **THEN** the row's `can` contains `complete`, `unclaim` and `delegate` and does not contain `cancel` or `reassign`
- @e2e exclude {backend list; covered by a unit test on TaskInboxService::row and the Newman request in task 1.3}

#### Scenario: the requester may cancel and reassign

- **GIVEN** the same task
- **WHEN** `annemarie` lists it
- **THEN** the row's `can` contains `cancel`, `reassign` and `assign` and does not contain `complete`
- @e2e exclude {backend list; covered by a unit test on TaskInboxService::row}

#### Scenario: a pool member may claim

- **GIVEN** an open task with `candidateGroups: ["backoffice"]` and no assignee
- **WHEN** a member of `backoffice` lists it
- **THEN** the row's `can` is `["claim"]`
- @e2e exclude {backend list; covered by a unit test on TaskInboxService::row}

#### Scenario: a finished task offers nothing

- **GIVEN** a completed task
- **WHEN** its requester or an administrator lists it
- **THEN** the row's `can` is `[]`
- @e2e exclude {backend list; covered by a unit test on TaskInboxService::row}


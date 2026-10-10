## ADDED Requirements

### Requirement: A task may wait on another task, and waits out of sight

A task SHALL accept an OPTIONAL `blockedBy` on create: the uuid of the task
it waits on. `blockedBy` SHALL be returned on every task read.

Every task read SHALL carry `blocked`: true while the named blocker exists and
is not terminal, false otherwise. `blocked` SHALL be derived on read and SHALL
NOT be stored. A create that carries `blocked`, or a state of `blocked`, SHALL
be refused.

A create SHALL be refused when `blockedBy` names the task itself, names no
existing task, or closes a loop of blockers.

An inbox read SHALL leave out every blocked task, in its results and in its
total. A read anchored to an object or a run SHALL still list blocked tasks,
carrying `blocked` and `blockedBy`. A read with `includeBlocked=true` SHALL
list them in any scope.

When a blocker reaches a terminal state, every open task it blocked SHALL be
released with no person acting: it SHALL get an audit entry `released` naming
the blocker, it SHALL be announced again to the projections, and it SHALL
return to the inbox.

#### Scenario: A blocked task stays out of the due list
@e2e exclude unit; TaskBlockedByTest covers the predicate, the flag and the total

- **GIVEN** a task assigned to Anna, blocked by an open task
- **WHEN** Anna opens her inbox
- **THEN** the blocked task SHALL NOT be listed
- **AND** the total SHALL NOT count it

#### Scenario: The case shows what waits and on what
@e2e exclude unit; TaskBlockedByTest covers the anchored read

- **GIVEN** the same pair, both anchored to one case
- **WHEN** the case's tasks are read
- **THEN** the blocked task SHALL be listed with `blocked` true and `blockedBy` naming the blocker

#### Scenario: Closing the blocker releases the task
@e2e exclude unit; TaskBlockerReleaseListenerTest covers the release, TaskBlockedByTest the flag

- **GIVEN** the same pair
- **WHEN** the blocking task is completed
- **THEN** the blocked task SHALL get an audit entry `released`
- **AND** its next read SHALL carry `blocked` false
- **AND** it SHALL be back in Anna's inbox without anybody editing it

#### Scenario: The blocked state cannot be set by hand
@e2e exclude unit; TaskBlockedByTest

- **GIVEN** a task with no blocker
- **WHEN** a create sets `blocked` to true, or its state to `blocked`
- **THEN** the create SHALL be refused

#### Scenario: A task cannot wait on itself or on a loop
@e2e exclude unit; TaskBlockedByTest

- **GIVEN** task A blocked by task B
- **WHEN** a task is created blocked by itself, by a uuid no task has, or as B's blocker with A's chain leading back to it
- **THEN** the create SHALL be refused

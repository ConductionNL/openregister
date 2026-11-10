# flow-tasks

## ADDED Requirements

### Requirement: A task may name the mandate its performer needs

A task SHALL accept an optional `requiredMandate` naming one permission from
the instance's permission catalogue. Creating a task, over the API or from a
user-task node, with a verb the catalogue does not hold SHALL be refused with
the verb named.

#### Scenario: an unknown mandate verb is refused at creation

- **GIVEN** a caseworker who may create tasks on a case
- **WHEN** they call `POST /api/flow-tasks` with `requiredMandate: "decidee"`
- **THEN** the response is 400 and its message names `decidee`
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/task-delegation-mandate.spec.ts}

### Requirement: A delegate must hold the task's mandate

Delegating a task SHALL check that the delegate holds the task's
`requiredMandate` on the task's subject object, using the same per-user
permission check the object endpoints use. A task without a `requiredMandate`
SHALL require the delegate to be able to read the subject. A delegate who
fails the check SHALL be refused, the task SHALL be unchanged, and the refusal
SHALL name the verb. A successful delegation SHALL record, on the task and its
audit entry, the verb and the rule that granted it.

#### Scenario: delegation to a colleague without the mandate is refused

- **GIVEN** a task on a permit case with `requiredMandate: "decide"`, assigned to caseworker Anna
- **AND** colleague Bram who can read the case but holds no `decide` grant
- **WHEN** Anna calls `POST /api/flow-tasks/{uuid}/delegate` with `delegate: "bram"` and a mandate sentence
- **THEN** the response is 422 and its message names `decide` and `bram`
- **AND** the task is still assigned to Anna and its audit has no `delegate` entry
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/task-delegation-mandate.spec.ts}

#### Scenario: delegation to a mandated colleague records the evidence

- **GIVEN** the same task and colleague Chris who holds `decide` on the permit schema until 2026-12-31
- **WHEN** Anna delegates the task to Chris
- **THEN** the response is 200, the task's assignee is `chris` and `onBehalfOf` is `anna`
- **AND** `GET /api/flow-tasks/{uuid}/audit` shows a `delegate` entry whose `mandateEvidence` names `decide`, the schema rule and `until` 2026-12-31
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/task-delegation-mandate.spec.ts}

### Requirement: A task goes to the stand-in of an absent assignee

When a task's user assignee has a Nextcloud absence in effect that names a
replacement, the task SHALL be assigned to that replacement on every path that
sets an assignee and when the absence starts, provided the replacement holds
the task's mandate. The task SHALL name the absent person in `onBehalfOf`, and
the audit SHALL carry a `substitute` entry naming the absence period. Pool
routing SHALL NOT pick a member whose absence is in effect while a present
member is available.

#### Scenario: a new task reaches the stand-in

- **GIVEN** caseworker Anna with an absence from 2026-10-05 to 2026-10-16 naming Chris as replacement, and today is 2026-10-07
- **WHEN** a flow creates a task assigned to Anna
- **THEN** the task's assignee is `chris` and `onBehalfOf` is `anna`
- **AND** the task's audit has a `substitute` entry with actor `absence:<id>` naming 2026-10-05 to 2026-10-16
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/task-delegation-mandate.spec.ts}

#### Scenario: a stand-in without the mandate is not used

- **GIVEN** the same absence and a task with `requiredMandate: "decide"` that Chris does not hold
- **WHEN** the task is assigned to Anna
- **THEN** the task stays assigned to Anna and its audit has a `substitute-refused` entry naming `decide`
- **AND** the task row in `GET /api/flow-tasks` carries `assigneeAbsent: true` and `absentUntil` 2026-10-16
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/task-delegation-mandate.spec.ts}

### Requirement: Untouched tasks return when the absence ends

When an absence ends, a task substituted for it that the stand-in has not acted
on SHALL return to the original assignee with a `substitute-return` audit
entry. A task the stand-in has acted on SHALL stay with the stand-in.

#### Scenario: the stand-in keeps work they started

- **GIVEN** two tasks substituted from Anna to Chris for one absence, and Chris has added a checklist tick on the first
- **WHEN** the absence ends and the substitution job runs
- **THEN** the first task stays with Chris and the second is assigned to Anna again with a `substitute-return` audit entry
- @e2e exclude {specified only; task 3.3 adds TaskSubstitutionJobTest, task 4.1 adds tests/e2e/ci/task-delegation-mandate.spec.ts}

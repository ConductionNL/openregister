# task-expiry-and-outcomes

## ADDED Requirements

### Requirement: A task declares its timeout and reject behaviour in one vocabulary

A task SHALL carry two optional behaviours, `onTimeout` and `onReject`, each
one value of the reserved outcome vocabulary (`skip`, `error`,
`dead_letter`). Intake SHALL refuse a value outside the vocabulary by name,
and SHALL refuse `onTimeout` on a task without an `expiresAt`. Both
behaviours SHALL appear in the task's JSON serialization and in the
task-form description.

#### Scenario: an outcome outside the vocabulary is refused

- **GIVEN** a task creation payload with `onTimeout: 'explode'`
- **WHEN** the task is built
- **THEN** the build is refused with a message naming the value and the vocabulary
- @e2e exclude {intake validation is a service boundary; covered by unit tests}

#### Scenario: a timeout behaviour with no deadline is refused

- **GIVEN** a task creation payload with `onTimeout: 'error'` and no `expiresAt`
- **WHEN** the task is built
- **THEN** the build is refused naming both fields
- @e2e exclude {intake validation is a service boundary; covered by unit tests}

### Requirement: The timer sweep enforces a declared task expiry

The existing sweep pass SHALL include a bounded, index-backed range scan of
non-terminal tasks whose `expires_at` has passed and whose `on_timeout` is
declared, and SHALL apply each hit's declared behaviour through the existing
timer-outcome path. The scan SHALL be bounded by the pass's batch limit,
SHALL report work performed and truncation, and a failure on one task SHALL
NOT stop the pass. No second scheduler SHALL be introduced.

#### Scenario: a task past its expiry is closed with its declared behaviour

- **GIVEN** a non-terminal task with `expiresAt` in the past and `onTimeout: 'dead_letter'`
- **WHEN** the sweep pass runs
- **THEN** the task ends in state `disabled` with outcome `dead_letter`, audited with the sweep as actor
- @e2e exclude {background sweep with a past deadline is not driveable from the UI; covered by unit tests}

#### Scenario: a task without a declared behaviour is left alone

- **GIVEN** a non-terminal task with `expiresAt` in the past and no `onTimeout`
- **WHEN** the sweep pass runs
- **THEN** the task is not selected and keeps its state
- @e2e exclude {absence of background mutation is only observable in a unit test}

### Requirement: A non-enforcing expiry timer falls back to the task's declared behaviour

When an expiry timer fires for a task subject and the timer itself carries
no enforcing outcome, the task's own declared `onTimeout` SHALL be applied.
An enforcing timer's own `onExpiry` SHALL take precedence over the task's
declared behaviour.

#### Scenario: the task's behaviour applies when the timer cannot enforce

- **GIVEN** a task declaring `onTimeout: 'error'` with a fired, non-enforcing expiry timer
- **WHEN** the timer's outcome is applied
- **THEN** the task ends in state `terminated` with outcome `failed`
- @e2e exclude {timer firing is a background path; covered by unit tests}

### Requirement: A rejecting completion honours the task's declared reject behaviour

A rejecting completion of a task declaring `onReject: 'dead_letter'` SHALL
record the dead-letter outcome through the same outcome mapping the timer
path uses, preserving the mandatory comment and auditing the original
rejecting outcome. Declared `onReject` values `error` and `skip` SHALL be
stored and serialized without changing the completion itself.

#### Scenario: a rejection routes to the dead letter state

- **GIVEN** a task declaring `onReject: 'dead_letter'`
- **WHEN** its assignee completes it with outcome `rejected` and a comment
- **THEN** the task ends in state `disabled` with outcome `dead_letter`, and the audit names the original rejection
- @e2e exclude {reject routing is a service-boundary rule; covered by unit tests}

#### Scenario: a rejection without a declared behaviour stays a rejection

- **GIVEN** a task with no declared `onReject`
- **WHEN** its assignee completes it with outcome `rejected` and a comment
- **THEN** the task ends in state `completed` with outcome `rejected`
- @e2e exclude {existing behaviour retained; covered by unit tests}

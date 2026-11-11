# object-lifecycle

## ADDED Requirements

### Requirement: Every lifecycle action reached leaves an outcome record

A declared lifecycle action MAY carry a `key`. For every declared action the executor reaches on a transition, the system SHALL write one automation record with kind `lifecycle-action`, the key, the action name, the transition, the schema, the object uuid, the time, an outcome `succeeded`, `failed` or `skipped` and a message. A false condition SHALL record `skipped`; a handler that throws SHALL record `failed` with its message and the executor SHALL still fail the transition; a transition that rolls back SHALL NOT leave a `succeeded` record. These records SHALL be readable through `GET /api/automation-records` with `kind=lifecycle-action` under the same filters and access rule as notification records.

#### Scenario: a set-fields action succeeded

- **GIVEN** a transition `indienen` with an action `set-fields` declared with `key` `aut-91c0`
- **WHEN** an object makes that transition
- **THEN** one record with key `aut-91c0`, action `set-fields`, transition `indienen` and outcome `succeeded` exists for the object
- @e2e exclude {backend; task 3.1 adds a Newman case}

#### Scenario: a failing action is recorded and still fails

- **GIVEN** a transition whose second action throws
- **WHEN** an object attempts the transition
- **THEN** the transition is refused as today
- **AND** the first action's record is not `succeeded`, and the second's is `failed` with the handler's message
- @e2e exclude {backend; covered by a unit test on LifecycleActionExecutor}

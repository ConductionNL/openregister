# declared-actions

## ADDED Requirements

### Requirement: A declared action may carry inputs, a precondition, surfaces and one body

A declared action under `x-openregister-action` MAY carry `label`, `icon`, `surfaces`, `inputs` (a JSON Schema
object), `when` (JSONLogic over `object`, `user` and `inputs`) with `whenMessage`, `confirm`, `mode` (`sync` or
`async`), `next`, `elevate` and `reversible`. It SHALL carry at most one body: `transition` (a key of the same
schema's `x-openregister-lifecycle`), `flow` (a macro binding; `macro: true` remains accepted as part of it) or
`steps` (an inline list of flow nodes). Schema save SHALL refuse the following, naming the action and the reason:

- more than one body;
- a `transition` the lifecycle does not declare;
- `inputs` that are not a valid JSON Schema object;
- a `when` that is not valid JSONLogic;
- `surfaces` outside `header`, `row`, `bulk`, `detail-menu`;
- `bulk` on an action whose inputs default from `@object`.

An action without a body SHALL remain valid and SHALL keep its current meaning: a grantable verb that is never
listed as runnable.

#### Scenario: an action with two bodies is refused

- **GIVEN** a schema declaring action `publish` with both `transition` and `steps`
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming `publish` and saying an action has one body
- @e2e exclude {schema validation, covered by validator unit tests}

#### Scenario: an existing macro declaration keeps working

- **GIVEN** a schema declaring `close-and-notify` with `macro: true` and `flow`
- **WHEN** the schema is saved and the action is invoked
- **THEN** the save succeeds and the action runs the flow exactly as before
- @e2e exclude {backwards compatibility, covered by ObjectActionsControllerTest}

#### Scenario: a bodiless action is grantable but not runnable

- **GIVEN** a schema declaring `sendMail` with only `name` and `description`
- **WHEN** a user granted `sendMail` lists the actions on an object
- **THEN** `sendMail` is not in the list
- **AND** its authorization grant still imports
- @e2e exclude {listing rule, covered by unit tests}

### Requirement: A register may declare actions its schemas inherit

A register's `configuration['x-openregister-action']` SHALL be inherited by every schema in that register. A schema
entry with the same key SHALL replace the inherited entry entirely. Inheritance SHALL be resolved when the schema's
actions are read and SHALL NOT be copied into the schema. An inherited action SHALL be runnable on a schema only
under that schema's own authorization rules.

#### Scenario: a register-wide publish reaches every schema

- **GIVEN** a register declaring `publish` and two schemas that declare no actions
- **WHEN** the actions of an object in either schema are listed by a user that schema authorises for `publish`
- **THEN** `publish` is listed on both
- @e2e exclude {inheritance resolution, covered by unit tests}

#### Scenario: a schema overrides an inherited action

- **GIVEN** a register declaring `publish` with one step and a schema declaring `publish` with two
- **WHEN** `publish` runs on that schema's object
- **THEN** the schema's two steps run and the register's step does not
- @e2e exclude {override rule, covered by unit tests}

### Requirement: Elevation is declared, scoped and attributed

An action MAY declare `elevate` as a list of `{register, schema, verbs}`. Schema save SHALL refuse:

- an `elevate` containing `*` in any field;
- an `elevate` on an action that no authorization rule grants;
- an `elevate` saved by a caller who is not an administrator.

At run time, a write inside the action's run that the person's own rights refuse and that matches an `elevate` entry
SHALL be retried once as the system. Any other refused write SHALL stay refused. Every elevated write SHALL be audited
with the action, the person as `triggeredBy` and `runAs: system`.

#### Scenario: a handler publishes into a register they cannot write

- **GIVEN** action `publish` elevating `create` on the publication schema, and a handler with no create right there
- **WHEN** the handler runs `publish` on their case
- **THEN** the publication is created
- **AND** its audit entry names `publish`, the handler as `triggeredBy` and `runAs: system`
- @e2e exclude {elevation path, covered by unit tests with a refusing permission handler}

#### Scenario: an unlisted write is not elevated

- **GIVEN** the same action, and a step that also updates an object in a schema not listed in `elevate`
- **WHEN** the handler lacks the right for that update
- **THEN** the step fails with the permission refusal and nothing is written there
- @e2e exclude {elevation scope, covered by unit tests}

#### Scenario: a wildcard elevation is refused at save

- **WHEN** an administrator saves an action with `elevate: [{"register": "*", "schema": "*", "verbs": ["create"]}]`
- **THEN** the save fails with HTTP 422 naming the action
- @e2e exclude {schema validation, covered by validator unit tests}

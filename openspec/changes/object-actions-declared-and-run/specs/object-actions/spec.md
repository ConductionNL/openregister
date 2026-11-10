# object-actions

## ADDED Requirements

### Requirement: The actions a user may run on an object are listed in one place

`GET /api/objects/{register}/{schema}/{id}/actions` SHALL answer every runnable declared action of the object's
schema (including inherited ones) and every lifecycle transition `TransitionEngine::availableActions()` offers. Each
entry SHALL carry `key`, `kind` (`transition`, `flow` or `steps`), `label`, `description`, `icon`, `surfaces`,
`available`, `inputs` (JSON Schema), `confirm`, `mode` and `next`.

An action the caller has no right to SHALL be left out. An action whose `when` does not hold SHALL be listed with
`available: false` and `reason` set to its `whenMessage`. A transition named by a declared action SHALL be listed
once, under the declared action's key. The caller SHALL need `read` on the object, or the answer SHALL be 403.

#### Scenario: a handler sees what they can do and why one is greyed out

- **GIVEN** a case with declared actions `claim` (when `assignee` is empty) and `publish` (when `status` is `decided`), and lifecycle transition `finish`
- **AND** the case is claimed and in status `in-behandeling`, and the caller holds all three rights
- **WHEN** the caller lists the case's actions
- **THEN** `claim` is listed with `available: false` and its reason
- **AND** `publish` is listed with `available: false` and its reason
- **AND** `finish` is listed with `kind: transition` and `available: true`
- @e2e exclude {list composition, covered by ObjectActionsControllerTest; the host half is e2e-tested in nextcloud-vue}

#### Scenario: an action the user may not run is absent

- **GIVEN** a schema granting `publish` only to group `redactie`
- **WHEN** a user outside `redactie` lists an object's actions
- **THEN** `publish` is not in the list
- @e2e exclude {authorization filter, covered by unit tests}

#### Scenario: transitions from a provider lifecycle are listed

- **GIVEN** a schema whose lifecycle is in `provider` mode
- **WHEN** an object's actions are listed
- **THEN** the provider's transitions appear with `kind: transition` and their inputs as JSON Schema
- @e2e exclude {provider merge, covered by unit tests with a fake provider}

### Requirement: Running an action validates inputs, checks the right and the precondition, then executes its body

`POST /api/objects/{register}/{schema}/{id}/actions/{action}` with body `{"inputs": {…}}` SHALL, in this order:

1. resolve the action, or answer 404;
2. check the action's right on this object, or answer 403;
3. evaluate `when`, or answer 409 with `error: action-precondition-unmet` and the reason;
4. validate `inputs` against the declared schema after resolving defaults, or answer 422 with errors per field;
5. execute the body.

For each body:

- `transition` SHALL call `TransitionEngine::transition()` with the inputs as its data, so its guards, condition
  and transition authorization apply.
- `flow` SHALL call `FlowService::run()` with the object as subject and `context.inputs`.
- `steps` SHALL do the same with the lowered flow.

The run SHALL execute as the caller. Nothing SHALL be written before step 5.

#### Scenario: inputs reach the steps

- **GIVEN** action `publish` with input `from` (a date) and a step setting `publicatiedatum` to `{{inputs.from}}`
- **WHEN** a permitted user runs it with `{"inputs": {"from": "2026-11-01"}}`
- **THEN** the object's `publicatiedatum` reads `2026-11-01`
- @e2e exclude {interpolation, covered by unit tests; the dialog round trip is e2e-tested in nextcloud-vue}

#### Scenario: invalid inputs write nothing

- **GIVEN** the same action
- **WHEN** it is run with `{"inputs": {"from": "tomorrow-ish"}}`
- **THEN** the response is 422 naming `from`
- **AND** no run is queued and the object is unchanged
- @e2e exclude {validation order, covered by unit tests}

#### Scenario: a precondition refusal is distinct from a permission refusal

- **GIVEN** a permitted user and an object for which `when` does not hold
- **WHEN** the user runs the action
- **THEN** the response is 409 with `error: action-precondition-unmet` and the declared message
- @e2e exclude {status mapping, covered by unit tests}

#### Scenario: an action over a transition keeps the transition's guards

- **GIVEN** action `finish` with `transition: finish`, whose lifecycle `requires` a guard that refuses
- **WHEN** a permitted user runs `finish`
- **THEN** the response is 422 with the guard's message and the status is unchanged
- @e2e exclude {guard passthrough, covered by unit tests}

### Requirement: Inline steps are a straight line lowered to the one flow engine

`steps` SHALL be lowered by `FlowDefinitionBuilder` to a manual trigger, the steps in order, and an end node carrying
`next`. The lowered definition SHALL be stored once per content hash, and each run SHALL record it as its flow and
version. Schema save SHALL refuse a step whose type the node registry does not know, a step whose config the node
refuses, and any node that branches or suspends (`switch`, `route`, `wait`, `await-signal`, `user-task`,
`portal-task`), naming the step and saying to bind a flow instead. `flow-powerful-steps-need-a-right` SHALL apply to
the saver of the schema.

#### Scenario: a branching step is refused with the way out

- **WHEN** a schema is saved with an action whose `steps` include `openregister.user-task`
- **THEN** the save fails with HTTP 422 naming the step and saying to declare a flow and bind it with `flow`
- @e2e exclude {lowering refusal, covered by FlowDefinitionBuilder unit tests}

#### Scenario: a steps action leaves a run that can be opened

- **WHEN** a steps action runs
- **THEN** the answer carries a run uuid
- **AND** `/api/flow-runs/{uuid}` shows each step with its outcome
- @e2e exclude {run record, covered by unit tests}

### Requirement: An action may run asynchronously and report its run

An action declaring `mode: async` with a `flow` or `steps` body SHALL answer 202 with the run uuid, `status: queued`
and a link to `/api/flow-runs/{uuid}`. A sync action whose flow suspends SHALL answer 202 with `status: suspended`
and SHALL NOT report the run as completed. A `transition` action SHALL always run sync.

#### Scenario: a long export answers at once

- **GIVEN** action `export-dossier` with `mode: async`
- **WHEN** a permitted user runs it
- **THEN** the response is 202 with a run uuid whose status can be read until it is `completed`
- @e2e exclude {async path, covered by unit tests}

#### Scenario: a suspended sync flow does not claim completion

- **GIVEN** a sync action bound to a flow that creates a user task and waits
- **WHEN** the action runs
- **THEN** the response is 202 with `status: suspended`
- @e2e exclude {suspension reporting, covered by unit tests}

### Requirement: Selections run per object, and large selections become a bulk job

`POST /api/object-actions/{register}/{schema}/{action}` with `{ids, inputs}` SHALL run the action per object through
the single-object path. Up to 100 ids, it SHALL answer a per-object summary as it does today. Above 100, it SHALL
create a bulk job of the bulk action `openregister:run-action`, with the preview, progress, retry and reversibility
rules of `bulk-action-jobs`, and answer 202 with the job. Only actions whose `surfaces` include `bulk` SHALL be
accepted. `GET /api/object-actions/{register}/{schema}` SHALL list them.

#### Scenario: a selection of 250 becomes a job

- **GIVEN** action `assign-to-me` offered on `bulk`
- **WHEN** it is run on 250 ids
- **THEN** the response is 202 with a bulk job whose preview counts 250 members
- @e2e exclude {bulk handoff, covered by unit tests}

#### Scenario: an action not offered on bulk is refused for a selection

- **WHEN** an action whose surfaces omit `bulk` is run on a selection
- **THEN** the response is 400 naming the action
- @e2e exclude {surface rule, covered by unit tests}

### Requirement: Every run is audited once and the object's action history can be read

Every run of an action SHALL write one audit entry on the subject with `action: action.run` and context
`declaredAction`, `kind`, `run` (null for a transition), `inputsDigest`, `changes` (as `macro-run-changes` defines
them) and `outcome`. Inputs marked `writeOnly` SHALL be excluded from the digest's stored source and from the run's
stored context. Existing `action.macro` entries SHALL NOT be rewritten.
`GET /api/objects/{register}/{schema}/{id}/actions/history` SHALL list `action.run` and `action.macro` entries, newest
first, each with its run link, to callers with `read` on the object.

#### Scenario: the history names what the last action did

- **GIVEN** `hold` ran on a case and wrote a field and sent a notification
- **WHEN** the case's action history is read
- **THEN** the newest entry names `hold`, its run, and two changes, a `write` and a `message`
- @e2e exclude {audit context, covered by unit tests}

#### Scenario: old macro entries stay readable

- **GIVEN** a case with an `action.macro` entry from before this change
- **WHEN** its action history is read
- **THEN** that entry is listed beside the `action.run` entries
- @e2e exclude {legacy read, covered by unit tests}

### Requirement: Two built-in nodes cover transitions and linked objects

The node registry SHALL offer `openregister.transition` (config `action`, optional `data`, optional `object`), which
moves the subject through `TransitionEngine::transition()` as the run's identity and fails the step with the engine's
message on refusal. It SHALL also offer `openregister.link-object` (config `schema`, `register`, `data`, `relation`,
or `handoff`), which creates an object and writes the relation on the subject, or runs the subject schema's declared
`x-openregister-handoff`. When the handoff's implementer is absent, the step SHALL fail with `not-available` and SHALL
NOT succeed silently. Both nodes SHALL declare their kind and category as `flow-node-taxonomy` requires.

#### Scenario: a hand-over without its target app fails visibly

- **GIVEN** action `hand-over` whose step runs handoff `convert-to-case`, and the case app is not installed
- **WHEN** the action runs
- **THEN** the run fails at that step with `not-available`
- **AND** the action answers 422 naming it
- @e2e exclude {node failure path, covered by node unit tests}

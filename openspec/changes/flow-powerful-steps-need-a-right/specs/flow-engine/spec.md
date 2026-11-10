# flow-engine

## ADDED Requirements

### Requirement: A flow step type can require a named right

A node type SHALL be able to declare, per step configuration, a right from
Open Register's action matrix that an author needs to use it, and an
administrator SHALL be able to mark any node type as requiring a right or lift
a node's own declaration. The administrator's choice SHALL take precedence.
The built-in steps that send e-mail, notifications or Talk messages, and the
object-write step when it deletes, SHALL declare rights.

#### Scenario: an administrator restricts another app's step

- **GIVEN** an administrator on the action rights settings screen
- **WHEN** they mark node type `openconnector.source-call` as requiring `flow.node.source-call` and grant it to group `integration-builders`
- **THEN** `GET /api/flow/node-catalog` for a planner outside that group lists the source-call step with `locked: true` and `requiresRight: "flow.node.source-call"`
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/flow-node-rights.spec.ts}

### Requirement: A flow with a restricted step is saved only by someone who holds its right

Creating, importing, updating, drafting, publishing or adopting a flow SHALL be
refused with 403 when the new definition, or the stored definition of the flow
being changed, contains a step whose right the caller does not hold. The
refusal SHALL name the step type and the right. Administrators SHALL pass.
Running a stored flow SHALL NOT be affected.

#### Scenario: a planner cannot save an e-mail step without the right

- **GIVEN** a planner who holds `flow.create` but not `flow.node.send-email`
- **WHEN** they call `POST /api/flows` with a flow containing an `openregister.send-email` step
- **THEN** the response is 403 and its message names `openregister.send-email` and `flow.node.send-email`, and no flow is stored
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/flow-node-rights.spec.ts}

#### Scenario: editing a powerful flow is refused too

- **GIVEN** a stored flow with an e-mail step, built by an administrator
- **WHEN** the same planner calls `PUT /api/flows/{id}` changing only its name
- **THEN** the response is 403 naming `flow.node.send-email`, and the flow is unchanged
- @e2e exclude {specified only; task 2.1 adds FlowControllerTest, task 4.1 adds tests/e2e/ci/flow-node-rights.spec.ts}

### Requirement: The action rights are administered and published

An administrator SHALL be able to read and change which groups hold each of
Open Register's action rights, including node rights, through
`/api/settings/action-rights` and a settings screen. A right a node declares
SHALL be added to the matrix, granted to administrators, when absent, without
changing any existing entry. `GET /api/permissions` SHALL list every action
right with its app, its description and the node types that require it.

#### Scenario: an upgrade keeps an administrator's choices

- **GIVEN** an instance where an administrator narrowed `flow.create` to group `flow-authors`
- **WHEN** Open Register is upgraded to the release with node rights
- **THEN** `GET /api/settings/action-rights` shows `flow.create` still granted to `flow-authors`, and `flow.node.send-email` granted to administrators
- @e2e exclude {specified only; task 3.1 adds the repair test, task 4.1 adds tests/e2e/ci/flow-node-rights.spec.ts}

#### Scenario: the catalogue shows a node right

- **GIVEN** any signed-in user
- **WHEN** they call `GET /api/permissions`
- **THEN** `actions` contains `flow.node.send-email` with app `openregister` and node type `openregister.send-email`
- @e2e exclude {specified only; task 3.3 adds PermissionsControllerTest, task 4.1 adds tests/e2e/ci/flow-node-rights.spec.ts}

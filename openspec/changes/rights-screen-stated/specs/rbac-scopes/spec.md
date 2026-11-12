# rbac-scopes (delta)

## ADDED Requirements

### Requirement: Rights are managed in a permission matrix screen (REQ-RSS-001)

OpenRegister's administrator settings SHALL include a permission matrix with one row per register and per schema and one column per action (read, create, update, delete) plus a public column. Each cell SHALL show the groups that hold the action and whether the grant is set on the schema itself or inherited from its register. An administrator SHALL be able to add or remove a group on a cell, and to assign a role to a group across a whole register in one step, and the change SHALL be saved to the schema's or register's `authorization` through the same save path as a schema edit. The matrix SHALL be shown in the administrator settings only, and a save by a caller who may not manage the schema or register SHALL be refused.

#### Scenario: An administrator grants a group read on a schema
- **GIVEN** an administrator on the permission matrix and a schema `melding` whose read cell is empty
- **WHEN** the administrator adds the group `wijkteam` to the read cell and saves
- **THEN** the schema's `authorization.read` holds `wijkteam`
- **AND** a member of `wijkteam` can list `melding` objects
- @e2e exclude {task 2.1 adds tests/e2e/ci/permission-matrix.spec.ts}

#### Scenario: An inherited grant is told apart
- **GIVEN** a register that grants `read` to `medewerkers` and a schema in it with no read rule of its own
- **WHEN** the administrator opens the matrix
- **THEN** the schema's read cell shows `medewerkers` marked as inherited from the register
- @e2e exclude {task 2.1}

#### Scenario: A caller who may not manage the schema cannot save
- **GIVEN** a user who may not manage schema `melding`
- **WHEN** the user sends the save the matrix makes
- **THEN** the response is 403 and the authorization is unchanged
- @e2e exclude {asserted by the controller unit test in task 2.2}

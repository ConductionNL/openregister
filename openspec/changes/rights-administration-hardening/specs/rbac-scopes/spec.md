---
status: proposed
---

# rbac-scopes

## ADDED Requirements

### Requirement: An administrator exports the full authorisation matrix with provenance (REQ-RAH-001)

`GET /api/permissions/matrix` SHALL be administrator only and SHALL accept `format` (`csv` or `json`) and `level` (`group` or `user`). It SHALL list one row per subject (group, or user with their groups), register, schema and action from the permission catalogue, with `granted` true or false and the deciding rule as REQ-PPD-004 names it (register default, schema rule, role, object grant, ancestor or deny). Per-object grants SHALL be listed as their own rows. The export SHALL carry `generatedAt`, `generatedBy`, the instance id, the row count and a SHA-256 of the rows, as CSV header comment lines or JSON fields, SHALL be streamed, and SHALL write one administrative audit row naming the export's hash.

#### Scenario: an auditor receives the matrix
- **GIVEN** two registers, five schemas, groups `woo-coordinator` and `redactie`, and a deny on `update` for `redactie` on schema `besluit`
- **WHEN** a functional administrator downloads the matrix as CSV from the permissions page
- **THEN** every group, schema and action pair is a row, `redactie` and `besluit` and `update` reads `granted false` with the deny as rule, and the header names who generated it, when, the row count and the hash

#### Scenario: a non-administrator cannot export
<!-- @e2e exclude Covered by PHPUnit PermissionMatrixControllerTest::testANonAdministratorGets403; hydra semantic-auth gate. -->

- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they call the export
- **THEN** the response is 403

#### Scenario: the hash proves the file
<!-- @e2e exclude Covered by PHPUnit PermissionMatrixExporterTest::testTheHashCoversTheRows. -->

- **GIVEN** an exported matrix
- **WHEN** a row is altered afterwards
- **THEN** the SHA-256 of the rows no longer matches the header and the audit row

### Requirement: Nobody grants a right they do not hold (REQ-RAH-002)

Every write that grants a right (schema and register authorization blocks and role matrix, object shares carrying permissions, organisation roles, derived grant rules, API tokens) SHALL call one `GrantCeiling` check before storing. For a caller who is not a Nextcloud administrator, a grant SHALL be refused with 403 `{error: "grant-exceeds-granter", excess: [{register, schema, action}]}` when it gives an action on a register or schema the caller does not hold there by effective scope, and `manage` SHALL never be grantable. Nothing SHALL be stored on refusal. A grant within the caller's rights SHALL be stored as today.

#### Scenario: a functional administrator cannot hand out more than they have
- **GIVEN** a delegated administrator who holds `read` and `update` on schema `publicatie` and is not a Nextcloud administrator
- **WHEN** they edit the schema's authorization to give group `redactie` `delete`
- **THEN** the save is refused naming `delete` on `publicatie`, and the block is unchanged

#### Scenario: every grant path is guarded
<!-- @e2e exclude Covered by PHPUnit GrantCeilingCoverageTest::testEveryGrantWriteCallsTheCeiling, which lists the grant writing actions and asserts each calls GrantCeiling, failing on a new one without it. -->

- **GIVEN** the grant writing actions of the app
- **WHEN** the suite runs
- **THEN** each calls `GrantCeiling` before it stores

#### Scenario: a share cannot carry more than its sharer holds
<!-- @e2e exclude Covered by PHPUnit GrantCeilingTest::testAShareBeyondTheSharersRightsIsRefused through ObjectSharingController::createShare. -->

- **GIVEN** a user with `read` only on an object
- **WHEN** they share it with `update`
- **THEN** the share is refused naming `update`

### Requirement: A product-owned group's rights are restored to what the product declares (REQ-RAH-003)

The configuration import SHALL store, per app, the rights its configuration declares for each declared group. `GroupReconciler` SHALL, for declared groups only, restore a declared right missing from a live authorization block and remove a right present in a live block but not declared, SHALL write one administrative audit row per change naming the group, the register or schema, the action, the direction and the app that declares it, and SHALL log it. It SHALL run on `GroupReconcilerJob` and at the end of every configuration import. It SHALL NOT change group membership, and SHALL NOT change entries for groups the app does not declare.

#### Scenario: an edited product group is reverted on upgrade
- **GIVEN** opencatalogi declares group `woo-redactie` with `update` on schema `publicatie`, and an administrator removed that right and added `delete`
- **WHEN** opencatalogi is upgraded and its configuration imports
- **THEN** `woo-redactie` again holds `update` and no longer holds `delete` on `publicatie`, and two audit rows name the reverts

#### Scenario: the organisation's own groups are left alone
<!-- @e2e exclude Covered by PHPUnit GroupReconcilerRightsTest::testAnUndeclaredGroupIsNotTouched. -->

- **GIVEN** an organisation group `team-noord` added to the same schema
- **WHEN** the reconciler runs
- **THEN** `team-noord`'s entries are unchanged

#### Scenario: membership is the organisation's
<!-- @e2e exclude Covered by PHPUnit GroupReconcilerRightsTest::testMembershipIsNeverChanged. -->

- **GIVEN** five users added to `woo-redactie` by an administrator
- **WHEN** the reconciler runs
- **THEN** the five are still members

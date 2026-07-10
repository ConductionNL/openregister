# authorization-rbac Specification

## Purpose
TBD - created by archiving change restore-register-schema-rbac-enforcement. Update Purpose after archive.
## Requirements
### Requirement: Register and schema mutations enforce role-based permission

Creating, updating, or deleting a Register or a Schema SHALL enforce
`verifyRbacPermission()` for the corresponding action in addition to
`verifyOrganisationAccess()` tenant scoping. Membership of an organisation
SHALL NOT by itself grant the right to mutate that organisation's registers or
schemas; the caller SHALL also hold the role permitting the action.

#### Scenario: Org member without write role is denied

- **WHEN** an authenticated user is a member of an organisation but lacks the
  register-write role
- **AND** they attempt to create, update, or delete a register in that org
- **THEN** the operation is rejected with HTTP 403
- **AND** no register row is written

#### Scenario: Role-holder succeeds

- **WHEN** an authenticated user holds the schema-write role for the organisation
- **AND** they update a schema in that org
- **THEN** the operation succeeds

#### Scenario: Internal bypass is explicit, not global

- **WHEN** a system/internal code path must skip RBAC (e.g. a repair/seed step)
- **THEN** it passes an explicit `_rbac: false` at the call site with a comment
- **AND** the mapper's default posture for all other callers remains RBAC-enforced

### Requirement: No dormant Solr-era RBAC bypass on the read path

Read-path RBAC on registers and schemas SHALL be enforced by default. The
previously-disabled "solr hotfix" bypass SHALL be removed, since the external
Solr backend no longer exists (ADR-007).

#### Scenario: Read RBAC is active

- **WHEN** a caller reads a register/schema via `find()` with default arguments
- **THEN** `verifyRbacPermission('read', ...)` is evaluated
- **AND** no `@todo remove this hotfix for solr` bypass remains in the mapper


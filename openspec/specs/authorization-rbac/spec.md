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

### Requirement: Authorization resolution fails closed

An authorization resolver that CANNOT determine the effective permissions for a
register or schema SHALL deny access. It SHALL NOT report the failure as an
absence of rules.

"No authorization is configured" (`null` / `[]`) and "the authorization could not
be determined" are DISTINCT outcomes and SHALL NOT share a representation. The
former MAY mean open; the latter SHALL mean deny.

A resolution failure SHALL be logged at `error` level. A resolution failure
SHALL NOT be cached: a transient error SHALL NOT be frozen into a persistent
verdict.

Logging a fail-open does not satisfy this requirement. The denial is required,
not merely the diagnostic.

#### Scenario: Unresolvable authorization denies every action

- **WHEN** the register cascade cannot be resolved (mapper unavailable, register
  lookup throws)
- **THEN** every action (`read`, `create`, `update`, `delete`, `list`) is DENIED
- **AND** the failure is logged at `error` level
- **AND** the RBAC SQL filter is clamped to the impossible predicate rather than
  bypassed

#### Scenario: A resolution failure is not cached as an answer

- **WHEN** an authorization resolution fails for a register
- **AND** a later resolution in the same request would succeed
- **THEN** the resolver re-resolves rather than replaying the failure
- **AND** the register's real rules are honoured

#### Scenario: A schema with no register remains open

- **WHEN** a schema legitimately belongs to no register
- **THEN** authorization resolves to "none configured"
- **AND** the schema is NOT denied by the fail-closed path

### Requirement: Declared seed data is planted

Seed objects declared by a register descriptor SHALL be planted by an engine.
The canonical, engine-backed seed location is `components.objects` (or top-level
`objects`), consumed by the configuration importer.

A register SHALL NOT declare seed data in a location no engine reads.

#### Scenario: MDM trust rules are planted

- **WHEN** the trust-configuration register is imported
- **THEN** its 6 trust rules are declared at `components.objects`
- **AND** each carries an `@self` identity (`register`, `schema`, `slug`) that
  resolves against the descriptor's own declarations
- **AND** the importer plants them

### Requirement: The annotation vocabulary contains only engine-backed keys

`ANNOTATION_VOCABULARY` is the registry of supported declarative dialects. A key
in the vocabulary is a promise that an engine consumes it.

An `x-openregister-*` key SHALL be in the vocabulary IF AND ONLY IF an engine
reads it. Round-tripping through the configuration column SHALL NOT be treated
as evidence that a capability works — "not dropped" is not "consumed".

- A key in the vocabulary with NO engine is a phantom: it persists, looks
  supported to every app that declares it, and no-ops forever. It SHALL be
  removed so declaring it fails loudly via the dropped-key warning.
- A key an engine READS but the vocabulary omits is silently dropped: the engine
  never receives its input. It SHALL be added.

#### Scenario: An engine-read key reaches its engine

- **WHEN** a schema declares `x-openregister-processing` with `logReads: true`
- **THEN** the key survives the `setConfiguration()` round-trip
- **AND** the value read by `ProcessingLogService::ANNOTATION_KEY` is the value
  declared
- **AND** per-schema AVG read-logging can be enabled

#### Scenario: An engine-less key is rejected loudly

- **WHEN** a schema declares `x-openregister-seed`, which no engine reads
- **THEN** the key is dropped from the configuration
- **AND** the key is recorded in the dropped-key buffer so the declaration is
  reported rather than silently accepted

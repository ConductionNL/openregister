## ADDED Requirements

### Requirement: Register-level authorization cascade
The system SHALL support authorization configuration on Register entities. When a Schema has no `authorization` block (null or empty), the system SHALL fall back to the parent Register's `authorization` block for permission evaluation. If neither Register nor Schema has authorization configured, all authenticated users SHALL have full CRUD access (preserving current behavior).

#### Scenario: Schema without authorization inherits register authorization
- **WHEN** a schema has no `authorization` block AND its parent register has `authorization`: `{ "read": ["public"], "create": ["behandelaars"], "update": ["behandelaars"], "delete": ["admin"] }`
- **THEN** permission checks on the schema SHALL use the register's authorization rules
- **AND** a user in group `behandelaars` SHALL be able to create objects in that schema
- **AND** an unauthenticated user SHALL be able to read objects in that schema

#### Scenario: Schema authorization overrides register authorization
- **WHEN** a schema has its own `authorization` block AND the parent register also has authorization
- **THEN** the schema's authorization SHALL be used exclusively
- **AND** the register's authorization SHALL NOT be merged or combined with the schema's

#### Scenario: Neither schema nor register has authorization
- **WHEN** a schema has no `authorization` AND its parent register has no `authorization`
- **THEN** all authenticated users SHALL have full CRUD access (current behavior preserved)
- **AND** unauthenticated users SHALL NOT have access unless `public` group is explicitly configured

### Requirement: Named role definitions on registers
The system SHALL support named role definitions stored in the Register entity's `configuration` field under a `roles` key. Each role SHALL have a `name`, `description`, and `actions` array listing permitted CRUD actions. Role names MAY be used in authorization blocks as shorthand for action groups.

#### Scenario: Define roles on a register
- **WHEN** a register's configuration contains `{ "roles": [{ "name": "viewer", "description": "Read-only access", "actions": ["read"] }, { "name": "editor", "description": "Full edit access", "actions": ["read", "create", "update"] }] }`
- **THEN** the roles SHALL be retrievable via the Register API
- **AND** role names SHALL be usable in authorization blocks

#### Scenario: Role-based authorization in schema
- **WHEN** a schema has authorization: `{ "roles": { "viewer": ["public"], "editor": ["behandelaars"] } }`
- **THEN** group `public` SHALL have `read` permission (from viewer role)
- **AND** group `behandelaars` SHALL have `read`, `create`, and `update` permissions (from editor role)
- **AND** permissions not covered by any assigned role SHALL be denied

#### Scenario: Role expansion coexists with direct action authorization
- **WHEN** a schema has both `roles` and direct action entries: `{ "roles": { "viewer": ["public"] }, "read": ["extra-groep"] }`
- **THEN** group `public` SHALL have `read` permission (from role)
- **AND** group `extra-groep` SHALL also have `read` permission (from direct entry)
- **AND** both formats SHALL be evaluated together

### Requirement: Delegation via manage action
The system SHALL support a `manage` action type in authorization blocks. Users with `manage` permission on a register SHALL be able to edit authorization configuration for schemas within that register. Users with `manage` permission on a schema SHALL be able to assign groups to existing roles on that schema.

#### Scenario: Register manager edits schema authorization
- **WHEN** user is in group `register-beheerders` AND the register authorization grants `manage` to `register-beheerders`
- **THEN** the user SHALL be able to update the `authorization` field on any schema within that register
- **AND** the user SHALL NOT need to be a Nextcloud admin

#### Scenario: Schema manager assigns groups to roles
- **WHEN** user has `manage` permission on a schema
- **THEN** the user SHALL be able to modify which groups are assigned to roles in that schema's authorization
- **AND** the user SHALL NOT be able to create new roles (roles are defined at register level)

#### Scenario: User without manage permission cannot edit authorization
- **WHEN** user does NOT have `manage` permission on the register or schema
- **THEN** attempts to update the `authorization` field SHALL be rejected with a 403 response
- **AND** the rejection SHALL include a descriptive error message

### Requirement: Register authorization cache
The system SHALL cache register authorization lookups within a single request to avoid repeated database queries when checking permissions for multiple schemas in the same register.

#### Scenario: Multiple schema checks within same register use cached authorization
- **WHEN** permission checks are performed for 10 schemas in the same register within a single API request
- **THEN** the register SHALL be loaded from the database at most once
- **AND** subsequent checks SHALL use the cached authorization data

## MODIFIED Requirements

### Requirement: Scope Model Hierarchy (Register > Schema > Object > Property)
The RBAC scope model SHALL follow a four-level hierarchy: register-level scopes govern access to an entire register and serve as defaults for schemas without their own authorization, schema-level scopes control CRUD operations per schema (zaaktype/objecttype), object-level scopes apply to individual records via conditional matching, and property-level scopes restrict visibility and mutability of specific fields. Each level MUST be independently configurable via the `authorization` JSON structure. Register-level authorization SHALL cascade to schemas that do not define their own authorization block. Named roles defined at register level SHALL be expandable in authorization blocks at any level.

#### Scenario: Schema-level authorization defines CRUD scopes
- **GIVEN** schema `bezwaarschriften` has authorization: `{ "read": ["juridisch-team"], "create": ["juridisch-team"], "update": ["juridisch-team"], "delete": ["admin"] }`
- **WHEN** OAS is generated for the register containing this schema
- **THEN** the scopes `juridisch-team` and `admin` MUST appear in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`
- **AND** the GET endpoints MUST list `juridisch-team` in their `security` requirements
- **AND** the DELETE endpoint MUST list `admin` in its `security` requirements

#### Scenario: Register authorization cascades to OAS generation for unconfigured schemas
- **GIVEN** a register has authorization `{ "read": ["medewerkers"], "create": ["medewerkers"] }` AND a schema in that register has no authorization block
- **WHEN** OAS is generated for that register
- **THEN** the schema's endpoints SHALL use the register's authorization for scope generation
- **AND** the scopes `medewerkers` and `admin` SHALL appear in the OAS security definitions

#### Scenario: Property-level authorization contributes additional scopes
- **GIVEN** schema `inwoners` has property `bsn` with authorization: `{ "read": [{ "group": "bsn-geautoriseerd" }], "update": [{ "group": "bsn-geautoriseerd" }] }`
- **AND** schema-level authorization allows group `kcc-team` to read
- **WHEN** `OasService::extractSchemaGroups()` processes this schema
- **THEN** `readGroups` MUST include both `kcc-team` and `bsn-geautoriseerd`
- **AND** `updateGroups` MUST include `bsn-geautoriseerd`
- **AND** both groups MUST appear as OAuth2 scopes in the generated OAS

#### Scenario: Schema with no authorization produces no extra scopes
- **GIVEN** schema `tags` has no `authorization` block (null or empty) AND its parent register also has no authorization
- **WHEN** `OasService::extractSchemaGroups()` processes this schema
- **THEN** `createGroups`, `readGroups`, `updateGroups`, and `deleteGroups` MUST all be empty arrays
- **AND** the schema's endpoints MUST NOT have operation-level `security` overrides

#### Scenario: Scope hierarchy is flattened for OAS (no nesting)
- **GIVEN** a register with 3 schemas, each having different group rules at schema-level and property-level
- **WHEN** OAS is generated
- **THEN** all unique group names across all schemas and properties MUST be collected into a single flat `scopes` object in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`
- **AND** duplicate group names MUST be deduplicated (each group appears only once)

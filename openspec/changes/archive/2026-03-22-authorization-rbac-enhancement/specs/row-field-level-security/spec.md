## ADDED Requirements

### Requirement: Row-level security performance target
The system SHALL ensure that row-level security filtering via `MagicRbacHandler` adds less than 50ms overhead to typical list queries (up to 1000 results). Performance SHALL be measured as the difference between a query with RBAC enabled vs disabled on the same dataset.

#### Scenario: List query with RBAC filtering within performance target
- **WHEN** a user queries a schema with 10,000 objects and RBAC filtering is enabled
- **THEN** the additional latency from RBAC filtering SHALL be less than 50ms for a result set of up to 1000 objects
- **AND** the RBAC conditions SHALL be applied at the SQL level (not post-query filtering)

#### Scenario: Row-level security with conditional organization matching
- **WHEN** a schema has authorization with conditional match `{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }`
- **THEN** the SQL query SHALL include a WHERE clause filtering by organisation
- **AND** only objects matching the user's active organisation SHALL be returned
- **AND** the filtering SHALL happen in the database, not in PHP

### Requirement: Field-level visibility configuration via schema properties
The system SHALL support field-level visibility configuration through the existing property-level authorization mechanism in `PropertyRbacHandler`. Sensitive fields (e.g., BSN, financial data) SHALL be hideable from users without specific group membership. The filtering SHALL apply consistently across all access methods (REST, GraphQL, MCP).

#### Scenario: Sensitive field hidden from unauthorized user
- **WHEN** schema `inwoners` has property `bsn` with authorization `{ "read": [{ "group": "bsn-geautoriseerd" }] }`
- **AND** a user NOT in group `bsn-geautoriseerd` retrieves an object
- **THEN** the `bsn` field SHALL NOT appear in the response
- **AND** all other fields without property-level authorization SHALL appear normally

#### Scenario: Authorized user sees sensitive field
- **WHEN** a user in group `bsn-geautoriseerd` retrieves the same object
- **THEN** the `bsn` field SHALL appear in the response with its full value

#### Scenario: Field-level authorization in GraphQL responses
- **WHEN** a GraphQL query requests a field with property-level authorization
- **AND** the user does NOT have the required group membership
- **THEN** the field SHALL return `null` in the GraphQL response
- **AND** no error SHALL be raised (graceful degradation)

### Requirement: Register authorization cascade in MagicRbacHandler
The `MagicRbacHandler` SHALL support register-level authorization cascade when building SQL-level RBAC conditions. When a schema has no authorization block, the handler SHALL look up the parent register's authorization for building row-level filter conditions.

#### Scenario: SQL RBAC uses register authorization for unconfigured schema
- **WHEN** a schema has no authorization AND its register has `{ "read": [{ "group": "medewerkers", "match": { "_organisation": "$organisation" } }] }`
- **THEN** `MagicRbacHandler::applyRbacFilters()` SHALL use the register's authorization to build SQL conditions
- **AND** objects SHALL be filtered by the user's organisation at the database level

#### Scenario: Schema with own authorization ignores register in SQL RBAC
- **WHEN** a schema has its own authorization block
- **THEN** `MagicRbacHandler::applyRbacFilters()` SHALL use only the schema's authorization
- **AND** the register's authorization SHALL NOT influence the SQL conditions

## MODIFIED Requirements

### Requirement: Permission Types (read, create, update, delete, list)
The system MUST support six distinct permission types in authorization rules: `read` (get a single object), `create` (post a new object), `update` (put/patch an existing object), `delete` (remove an object), `list` (query a collection, currently treated as `read`), and `manage` (edit authorization configuration). The `manage` type is new and controls delegation of authorization management. Each permission type except `manage` MUST map to the corresponding HTTP method in the generated OAS security requirements. The `manage` type SHALL be enforced only on authorization-editing API endpoints.

#### Scenario: Manage permission controls authorization editing
- **WHEN** a user with `manage` permission attempts to update a schema's authorization
- **THEN** the update SHALL be permitted
- **AND** the `manage` permission SHALL NOT grant any CRUD access to objects

#### Scenario: Manage permission in OAS generation
- **WHEN** OAS is generated for a schema with `manage` in its authorization
- **THEN** the `manage` groups SHALL NOT appear in CRUD endpoint security blocks
- **AND** the `manage` groups SHALL appear only on authorization-management endpoints if they are defined in the OAS

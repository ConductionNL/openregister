## ADDED Requirements

### Requirement: Authorization evaluation supports register cascade
The `PermissionHandler::hasPermission()` method SHALL support register-level authorization cascade. When evaluating permissions for a schema with no authorization block, the handler SHALL retrieve the parent register and use its authorization block. The register lookup SHALL be cached per-request to avoid repeated database queries.

#### Scenario: PermissionHandler falls back to register authorization
- **WHEN** `hasPermission()` is called for a schema with null authorization
- **THEN** the handler SHALL load the schema's parent register via `RegisterMapper`
- **AND** the handler SHALL use the register's authorization for permission evaluation
- **AND** subsequent calls for schemas in the same register SHALL use the cached register data

#### Scenario: PermissionHandler uses schema authorization when present
- **WHEN** `hasPermission()` is called for a schema with its own authorization block
- **THEN** the handler SHALL use the schema's authorization directly
- **AND** the register's authorization SHALL NOT be consulted

### Requirement: Role expansion in permission evaluation
The `PermissionHandler` SHALL expand named role references found in authorization blocks. When an authorization block contains a `roles` key mapping role names to group arrays, the handler SHALL resolve the role's actions from the parent register's configuration and grant the mapped groups those action permissions.

#### Scenario: Role-based authorization grants correct permissions
- **WHEN** a schema has authorization `{ "roles": { "viewer": ["public"], "editor": ["behandelaars"] } }`
- **AND** the register defines roles `[{ "name": "viewer", "actions": ["read"] }, { "name": "editor", "actions": ["read", "create", "update"] }]`
- **THEN** calling `hasPermission(schema, "read")` for a user in group `public` SHALL return true
- **AND** calling `hasPermission(schema, "create")` for a user in group `public` SHALL return false
- **AND** calling `hasPermission(schema, "create")` for a user in group `behandelaars` SHALL return true

#### Scenario: Unknown role name is ignored
- **WHEN** a schema references role name `archiver` but the register has no such role definition
- **THEN** the unknown role SHALL be ignored with a warning log entry
- **AND** other valid role mappings in the same authorization block SHALL still be evaluated

### Requirement: Manage permission enforcement on API endpoints
The authorization-editing API endpoints (updating `authorization` field on registers and schemas) SHALL check for `manage` permission before allowing changes. The `manage` check SHALL use the same `PermissionHandler` infrastructure as CRUD checks.

#### Scenario: API rejects authorization update without manage permission
- **WHEN** a user without `manage` permission sends a PUT/PATCH request that modifies the `authorization` field on a schema
- **THEN** the API SHALL return HTTP 403 with error message "User does not have permission to manage authorization for this schema"
- **AND** the authorization field SHALL NOT be modified

#### Scenario: Admin bypasses manage check
- **WHEN** an admin user modifies the authorization field on any schema or register
- **THEN** the update SHALL be permitted regardless of `manage` configuration
- **AND** the admin bypass SHALL follow the existing admin group check pattern in `PermissionHandler`

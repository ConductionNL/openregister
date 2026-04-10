## ADDED Requirements

### Requirement: Permission matrix admin UI
The system SHALL provide a permission matrix admin UI component (`PermissionMatrix.vue`) that displays all authorization assignments across registers and schemas. The matrix SHALL show a tree view of registers containing their schemas, with columns for each CRUD action plus `manage`. Each cell SHALL indicate which groups have the corresponding permission, with visual distinction between directly assigned and inherited (cascaded from register) permissions.

#### Scenario: Admin views permission matrix
- **WHEN** an admin navigates to the authorization management section
- **THEN** the UI SHALL display all registers in a tree structure
- **AND** each register SHALL expand to show its schemas
- **AND** each schema row SHALL show permission indicators for read, create, update, delete, and manage actions

#### Scenario: Matrix shows effective permissions with cascade indication
- **WHEN** a schema has no authorization and inherits from its register
- **THEN** the matrix SHALL show the inherited permissions with a visual indicator (e.g., italic text, different color, or cascade icon)
- **AND** hovering over an inherited permission SHALL show a tooltip indicating the source register

#### Scenario: Admin toggles a group permission
- **WHEN** an admin clicks a permission cell to add group `behandelaars` to the `update` action on a schema
- **THEN** the system SHALL update the schema's authorization JSON via the API
- **AND** the matrix SHALL refresh to reflect the change immediately
- **AND** an activity log entry SHALL be created for the change

#### Scenario: Non-admin users cannot access permission matrix
- **WHEN** a user without admin or `manage` permission navigates to the authorization section
- **THEN** the section SHALL NOT be visible in the navigation
- **AND** direct URL access SHALL show an access denied message

### Requirement: Bulk authorization management
The system SHALL support bulk authorization operations from the permission matrix UI. Administrators SHALL be able to apply a role to multiple schemas within a register in a single action.

#### Scenario: Apply role to all schemas in a register
- **WHEN** an admin selects a register and chooses "Apply role to all schemas"
- **THEN** the system SHALL present the available roles defined on that register
- **AND** the admin SHALL be able to select a role and target groups
- **AND** the authorization SHALL be applied to all schemas in the register that do not have explicit authorization overrides

#### Scenario: Remove group from all schemas in a register
- **WHEN** an admin selects a register and chooses "Remove group from all schemas"
- **THEN** the system SHALL remove the specified group from authorization blocks of all schemas in that register
- **AND** schemas relying on register-level cascade SHALL NOT be modified (they inherit from the register)

### Requirement: Authorization change audit logging
The system SHALL log all changes to authorization configuration via Nextcloud's activity system (`OCP\Activity\IManager`). Each audit entry SHALL include the user who made the change, the target entity (register or schema), the action type, the old authorization value, and the new authorization value.

#### Scenario: Schema authorization updated
- **WHEN** a user updates the authorization block on a schema
- **THEN** an activity entry SHALL be created with type `openregister_authorization`
- **AND** the entry SHALL include the schema identifier, old authorization JSON, and new authorization JSON
- **AND** the entry SHALL be visible in the Nextcloud activity feed

#### Scenario: Register authorization updated
- **WHEN** a user updates the authorization block on a register
- **THEN** an activity entry SHALL be created noting that cascaded schemas may be affected
- **AND** the entry SHALL list the number of schemas that will inherit the new authorization

#### Scenario: Role definition changed
- **WHEN** a user modifies the roles configuration on a register
- **THEN** an activity entry SHALL be created with the old and new role definitions
- **AND** the entry SHALL note which schemas currently reference the modified roles

### Requirement: Public access toggle per schema and register
The system SHALL provide a simple toggle mechanism to add or remove `public` group access on a schema or register. This toggle SHALL be available in the permission matrix UI and via the API.

#### Scenario: Enable public read access on a schema
- **WHEN** an admin toggles "Public access" on for a schema
- **THEN** the system SHALL add `"public"` to the `read` action in the schema's authorization
- **AND** unauthenticated requests SHALL be able to read objects in that schema
- **AND** other CRUD actions SHALL NOT be affected

#### Scenario: Disable public access on a register
- **WHEN** an admin toggles "Public access" off on a register
- **THEN** the system SHALL remove `"public"` from all action entries in the register's authorization
- **AND** schemas inheriting from that register SHALL no longer allow unauthenticated access
- **AND** schemas with their own explicit `public` authorization SHALL NOT be affected

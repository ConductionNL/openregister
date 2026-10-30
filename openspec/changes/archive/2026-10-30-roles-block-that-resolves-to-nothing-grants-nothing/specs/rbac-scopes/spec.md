## ADDED Requirements

### Requirement: A roles block that resolves to nothing grants nothing

When an authorization block declares `roles` and none of them can be expanded,
because the parent register declares no role definitions or every role name is
unknown, the expanded block SHALL grant no verb to anybody: `read`, `create`,
`update` and `delete` SHALL each be an empty list. It SHALL NOT be read as an
absent block, which is open to every signed in account. The administrator and
object owner bypasses SHALL still apply, and a block that keeps any other rule
after expansion SHALL be evaluated as written.

#### Scenario: A schema with roles only and a register without role definitions
@e2e exclude An engine verdict with no surface of its own; asserted through hasPermission() in tests/Unit/Service/Object/PermissionHandlerHandlingTeamRuleTest.php.
- **GIVEN** a schema whose authorization is `{"roles": {"behandelaar": ["behandelaars"]}}`
- **AND** a parent register that declares no role definitions
- **WHEN** a signed in user in no group asks to read, update or delete an object of it
- **THEN** each SHALL be refused
- **AND** an administrator SHALL still be allowed

#### Scenario: A schema whose role names are all unknown
@e2e exclude Same engine verdict; asserted in tests/Unit/Service/Object/PermissionHandlerRoleHierarchyTest.php.
- **GIVEN** a register declaring the role `viewer`
- **AND** a schema whose authorization assigns only the unknown role `ghost`
- **WHEN** the block is expanded
- **THEN** every canonical verb SHALL be an empty list

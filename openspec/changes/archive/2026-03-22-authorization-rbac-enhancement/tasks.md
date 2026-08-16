## 1. Register Authorization Cascade

- [x] 1.1 Extend `PermissionHandler::hasPermission()` to fall back to register authorization when schema authorization is null/empty — load register via `RegisterMapper`, use register's `authorization` field
- [x] 1.2 Add per-request register authorization cache in `PermissionHandler` (property `$cachedRegisterAuth: array<int, ?array>`) to avoid repeated DB lookups
- [x] 1.3 Extend `MagicRbacHandler::applyRbacFilters()` and `buildRbacConditionsSql()` to use register authorization cascade when schema authorization is null
- [x] 1.4 Extend `OasService::extractSchemaGroups()` to fall back to register authorization for schemas without their own authorization block
- [x] 1.5 Write unit tests for register authorization cascade in `PermissionHandler` — test schema override, register fallback, and neither-configured scenarios

## 2. Named Role Definitions

- [x] 2.1 Add role expansion logic to `PermissionHandler` — resolve `authorization.roles` entries against register `configuration.roles` definitions, expanding role names to action-level permissions
- [x] 2.2 Handle coexistence of `roles` and direct action entries in authorization blocks — evaluate both formats together
- [x] 2.3 Add warning log for unknown role names referenced in authorization blocks
- [x] 2.4 Add role expansion support to `MagicRbacHandler` for SQL-level RBAC filters
- [x] 2.5 Add role expansion support to `OasService` for OAS scope generation
- [x] 2.6 Write unit tests for role expansion — test viewer/editor/manager roles, unknown role handling, mixed role+direct format

## 3. Manage Action and Delegation

- [x] 3.1 Add `manage` action type support to `PermissionHandler::hasPermission()` — evaluate `manage` entries in authorization blocks
- [x] 3.2 Add authorization-edit permission checks in Register controller — reject `authorization` field updates from users without `manage` permission (return 403)
- [x] 3.3 Add authorization-edit permission checks in Schema controller — reject `authorization` field updates from users without `manage` permission (return 403)
- [x] 3.4 Ensure admin users bypass `manage` checks following existing admin group bypass pattern
- [x] 3.5 Exclude `manage` groups from CRUD endpoint security blocks in OAS generation
- [x] 3.6 Write unit tests for manage action — test delegation, admin bypass, rejection without permission

## 4. Permission Matrix Admin UI

- [x] 4.1 Create `PermissionMatrix.vue` component — tree view of registers/schemas with CRUD+manage action columns, group indicators per cell
- [x] 4.2 Add visual distinction for inherited (cascaded from register) vs directly assigned permissions — use italic/color/icon indicator with hover tooltip
- [x] 4.3 Implement permission toggle functionality — click cell to add/remove group from action, save via API
- [x] 4.4 Add public access toggle — simple switch to add/remove `public` group from read action
- [x] 4.5 Add bulk role assignment — select register, choose role and target groups, apply to all schemas without explicit overrides
- [x] 4.6 Add access control to permission matrix — hide from non-admin/non-manage users, show access denied for direct URL access
- [x] 4.7 Integrate permission matrix into admin navigation

## 5. Audit Logging

- [x] 5.1 Create activity provider for `openregister_authorization` event type — register with `OCP\Activity\IManager`
- [x] 5.2 Log schema authorization changes — include schema identifier, old value, new value, user who made the change
- [x] 5.3 Log register authorization changes — include register identifier, old/new value, count of affected cascaded schemas
- [x] 5.4 Log role definition changes — include old/new role definitions, list schemas referencing modified roles
- [x] 5.5 Write unit tests for audit logging — test event creation for each change type

## 6. Integration Testing and Regression Verification

- [x] 6.1 Test with opencatalogi app to verify no RBAC regressions — verify existing schema-level and property-level authorization still works
- [x] 6.2 Test with softwarecatalog app to verify no regressions — verify public access and authenticated access patterns
- [x] 6.3 Test row-level security performance — verify less than 50ms overhead for list queries up to 1000 results with RBAC enabled
- [x] 6.4 Test field-level visibility in GraphQL responses — verify null return for unauthorized fields without errors
- [x] 6.5 End-to-end test of register cascade + role expansion + delegation flow

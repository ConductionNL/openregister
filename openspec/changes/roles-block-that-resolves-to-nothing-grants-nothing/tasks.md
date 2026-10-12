# Tasks: roles-block-that-resolves-to-nothing-grants-nothing

- [x] 1.1 `PermissionHandler::expandRoles()` grants nothing when the expansion is empty.
  - `tests/Unit/Service/Object/PermissionHandlerRoleHierarchyTest.php`
- [x] 1.2 Assert through `hasPermission()` and `ObjectFileAccess::mayUpdate()` that a
  roles-only block closes the schema, and that a handling team rule keyed on a
  field list and an assignee rule admit exactly their members.
  - `tests/Unit/Service/Object/PermissionHandlerHandlingTeamRuleTest.php`
- [x] 1.3 `openspec validate roles-block-that-resolves-to-nothing-grants-nothing --strict`.

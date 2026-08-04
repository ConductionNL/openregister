## 1. Schema helpers

- [x] 1.1 Add `Schema::hasWriteOnlyProperties()` mirroring `hasPropertyAuthorization()`
- [x] 1.2 Add `Schema::getWriteOnlyProperties()` returning writeOnly property names

## 2. Read-time stripping

- [x] 2.1 Strip `writeOnly` properties in `PropertyRbacHandler::filterReadableProperties()` before the admin short-circuit (admin not exempt)
- [x] 2.2 Widen the RenderObject strip gate to `hasPropertyAuthorization() OR hasWriteOnlyProperties()`
- [x] 2.3 Bypass the RenderObject strip block on `_rbac === false` or `SystemOperationContext::isActive()`
- [x] 2.4 Replace the two stale `TODO: property-level RBAC` markers in `PermissionHandler` with pointers to the real implementation

## 3. Demonstration fixture

- [x] 3.1 Add a TEST schema fixture with a `writeOnly` property and a property `authorization.read`

## 4. Tests

- [x] 4.1 writeOnly stripped for non-admin and admin; property present in stored object; still writable
- [x] 4.2 property `authorization.read` stripped for non-member, returned for member and admin
- [x] 4.3 regression: property with neither mechanism serialises unchanged
- [x] 4.4 caller-selected writeOnly field cannot re-surface (post-selection strip)

## 5. Verify

- [x] 5.1 Run unit suite in Docker; confirm zero new errors/failures vs baseline (name-set diff)
- [x] 5.2 Scoped phpcs clean on every touched file

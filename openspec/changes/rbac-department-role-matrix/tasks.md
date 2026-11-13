# Tasks: rbac-department-role-matrix

## 1. Declaration

- [x] 1.1 Validate `authorization.matrix` at schema save (field exists,
      userSource shape, actions in the resolvable verb set).

## 2. Compiler

- [x] 2.1 Compile rows into conditional scopes, merging rows per group.
- [x] 2.2 Resolve `$self` for the GROUP-PREFIX user source. **Resolved in the
      compiler rather than through a new dynamic-variable token, deliberately:**
      the values are the caller's own, the compiler runs per request with the
      session in hand, and a `$userDepartments` token would mean teaching both
      evaluators a new word and keeping their two readings identical forever.
      A literal `$in` list leaves one vocabulary.
- [x] 2.2b The PERSON-SCHEMA user source (`{schema, property, match}`, match
      defaults to `userId`): `DepartmentMatrixPersonValues` reads the person
      object WITHOUT RBAC (so it cannot re-enter the decision it is part of),
      re-checks the match field on every returned person, and gives no values
      on any failure (a `$self` row then compiles to nothing, the closed
      direction). `PermissionHandler::compileDepartmentMatrix()` unions it with
      the group-prefix values. Tests: `DepartmentMatrixPersonSourceTest` (4),
      the caller test red before the wiring.
- [x] 2.3 Declare `handle` as a custom verb with an `update` fallback.

## 3. Admin surface

- [ ] 3.1 `src/views/schema/SchemaRightsTab.vue`, opened by a "Rights" tab button in `src/views/schema/SchemaDetails.vue` beside "Rules". The grid has one row per value of the matrix's `field` (the property's `enum` when declared, else the values already in the matrix plus an "Add value" row), one column per role group, and a checkbox per action (`read`, `list`, `update`, `handle`, `delete`). Saving writes only `authorization.matrix` through `schemaStore.saveSchema()`; the save-time validation from 1.1 answers any refusal next to the cell.
- [x] 3.2 Preview route `scopes#matrixPreview`, `GET /api/schemas/{id}/authorization/matrix/preview?user={uid}`, in `lib/Controller/ScopesController.php`, admin only (`IGroupManager::isAdmin()`). It returns the scopes from a new public `PermissionHandler::previewDepartmentMatrix(Schema $schema, IUser $user)` in `lib/Service/Object/PermissionHandler.php`, which runs the existing private `compileDepartmentMatrix()` for that user and reads no objects. The tab shows it under an `NcSelect` user picker with an `inputLabel`.
      Built: `previewDepartmentMatrix()` is declared on `PermissionExplanationInterface`, shares `matrixOwnValues()` with the authorization path, and compiles the schema's own block plus app rows for the chosen user; the route answers 403 to anyone but an administrator before any lookup, 404 for an unknown user or schema, else `{schema, user, values, scopes}`. The `NcSelect` user picker is part of 3.1.
- [ ] 3.3 Red first: `tests/Unit/Controller/ScopesControllerMatrixPreviewTest.php` (403 for a non-administrator; for user A one scope per action group with A's resolved values), `src/views/schema/SchemaRightsTab.spec.js` (a save sends only the `matrix` key), and a grid and preview step added to `tests/e2e/ci/rbac-department-role-matrix.spec.ts`.
      Done for the route: `tests/Unit/Controller/ScopesControllerMatrixPreviewTest.php` (3) and `tests/Unit/Service/Object/PermissionHandlerMatrixPreviewTest.php` (3, the chosen user's values and not the administrator's). Left with 3.1: `SchemaRightsTab.spec.js` and the e2e grid and preview step.

## 4. Tests

- [x] 4.1 Unit tests: compilation, `$self`, PHP and SQL parity on a matrix.
- [x] 4.2 `tests/e2e/ci/rbac-department-role-matrix.spec.ts`: two users in
      two departments, each sees only their department's cases in the list.

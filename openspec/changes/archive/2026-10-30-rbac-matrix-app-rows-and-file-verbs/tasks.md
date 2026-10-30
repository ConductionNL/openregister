# Tasks: rbac-matrix-app-rows-and-file-verbs

## 1. Matrix rows

- [x] 1.1 `DepartmentMatrixCompiler`: `where` copied into `match`, merge per (action, group, where); empty `$in` in `where` drops the row; `MatrixRowWhere` reads it for compiler and validator alike; PHPUnit (DepartmentMatrixRowConditionsTest, MatrixRowConditionsBothPathsTest)
- [x] 1.2 `until` on a row through `GrantConstraints::reaches()`; PHPUnit
- [x] 1.3 `DepartmentMatrixValidator`: refuse undeclared `where` keys and non-scalar values; PHPUnit
- [x] 1.4 User source `{groups: true}` in `valuesFromGroups()`; PHPUnit

## 2. Rows from an app

- [x] 2.1 `MatrixRowsRequestedEvent` and dispatch in `PermissionHandler::compileDepartmentMatrix()`, cached per request and schema; listener failure contributes nothing
- [x] 2.2 Access tests on the PHP and the SQL list path, asserted equal (design, access tests 1 to 4) (MatrixRowsFromAppsBothPathsTest)

## 3. File verbs

- [x] 3.1 `readFiles`, `addFiles`, `deleteFiles` in `DepartmentMatrixCompiler::ACTIONS` and `PermissionCatalogue`
- [x] 3.2 `ObjectFileAccess` per design D-4, opt-in when the block names a file verb; FilesController passes deleteFiles for delete, batch delete and the move source (the route walk test of object-files-follow-object-access still guards every route; it does not assert the verb)
- [x] 3.3 Access tests through `FilesController` (design, access test 5): FilesControllerFileVerbsTest

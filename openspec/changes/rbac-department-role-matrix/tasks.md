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

- [ ] 3.1 Rights tab on the schema page: grid editor and per-user preview.
      **Not built in this PR.** It is a Vue surface, and the declaration it
      edits is a JSON block an administrator can already write; shipping the
      grid without being able to lint, build or drive it (this phase's clone
      has no `node_modules`) would be a surface nobody has seen render. The
      compiler and its refusal are what the consuming apps are blocked on, and
      they are here.

## 4. Tests

- [x] 4.1 Unit tests: compilation, `$self`, PHP and SQL parity on a matrix.
- [x] 4.2 `tests/e2e/ci/rbac-department-role-matrix.spec.ts`: two users in
      two departments, each sees only their department's cases in the list.

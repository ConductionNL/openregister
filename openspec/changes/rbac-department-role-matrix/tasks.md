# Tasks: rbac-department-role-matrix

## 1. Declaration

- [ ] 1.1 Validate `authorization.matrix` at schema save (field exists,
      userSource shape, actions in the resolvable verb set).

## 2. Compiler

- [ ] 2.1 Compile rows into conditional scopes, merging rows per group.
- [ ] 2.2 Resolve `$self` through the dynamic-variable mechanism for both
      user sources.
- [ ] 2.3 Declare `handle` as a custom verb with an `update` fallback.

## 3. Admin surface

- [ ] 3.1 Rights tab on the schema page: grid editor and per-user preview.

## 4. Tests

- [ ] 4.1 Unit tests: compilation, `$self`, PHP and SQL parity on a matrix.
- [ ] 4.2 `tests/e2e/ci/rbac-department-role-matrix.spec.ts`: two users in
      two departments, each sees only their department's cases in the list.

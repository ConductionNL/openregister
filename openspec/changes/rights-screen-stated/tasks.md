# Tasks: rights-screen-stated

The screen is built; these tasks tie it to the requirement and add the checks that keep it true.

## 1. Tags

- [x] 1.1 Add `@spec openspec/changes/rights-screen-stated/specs/rbac-scopes/spec.md#REQ-RSS-001` to the save and bulk assignment methods in `src/views/settings/sections/PermissionMatrix.vue` and to the section in `src/views/settings/sections/RbacConfiguration.vue`.

## 2. Checks

- [x] 2.1 `tests/e2e/ci/permission-matrix.spec.ts`: grant a group read on a schema, see the inherited marker for a register grant, and confirm a member of the group can list the schema (REQ-RSS-001).
  - Done (26 Nov): 2 passed against a sandbox Nextcloud 34.0.6 + OpenRegister main on MariaDB. Red on main: an expanded register showed no schema rows at all. The register store reads registers with `_extend[]=schemas`, so a register lists its schemas as objects, and `PermissionMatrix::getRegisterSchemas()` only matched ids; that is fixed. The matrix has no editor for a single cell (it edits the public switches and assigns roles in bulk), so the grant goes through the save the matrix makes (`PUT /api/schemas/{id}`). The spec then reads the read cell: the register grant is marked inherited, the schema's own grant is not, and the group's member lists the object.
- [x] 2.2 Unit test on the schema save route the matrix calls: a caller who may not manage the schema saving a changed `authorization` gets 403 (REQ-RSS-001).

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change into `rbac-scopes` and repoint `acc-admin-screen` in `openspec/parity/capabilities.json` to the main spec requirement.

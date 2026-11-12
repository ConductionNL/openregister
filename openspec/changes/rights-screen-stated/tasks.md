# Tasks: rights-screen-stated

The screen is built; these tasks tie it to the requirement and add the checks that keep it true.

## 1. Tags

- [ ] 1.1 Add `@spec openspec/changes/rights-screen-stated/specs/rbac-scopes/spec.md#REQ-RSS-001` to the save and bulk assignment methods in `src/views/settings/sections/PermissionMatrix.vue` and to the section in `src/views/settings/sections/RbacConfiguration.vue`.

## 2. Checks

- [ ] 2.1 `tests/e2e/ci/permission-matrix.spec.ts`: grant a group read on a schema, see the inherited marker for a register grant, and confirm a member of the group can list the schema (REQ-RSS-001).
- [ ] 2.2 Unit test on the schema save route the matrix calls: a caller who may not manage the schema saving a changed `authorization` gets 403 (REQ-RSS-001).

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change into `rbac-scopes` and repoint `acc-admin-screen` in `openspec/parity/capabilities.json` to the main spec requirement.

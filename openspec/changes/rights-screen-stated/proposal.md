---
kind: code
---

# Proposal: rights-screen-stated

## Summary

An administrator manages rights in a permission matrix in OpenRegister's settings, not by editing schema JSON: one row per register and schema, one column per action plus public, with bulk role assignment. The screen is built, but `rbac-scopes` only specifies the API behind it, so the row `acc-admin-screen` cites nothing that says the screen. This change adds that requirement.

## Rows this closes

| row | capability | built in |
|---|---|---|
| acc-admin-screen | Manage rights in an access-control screen, not in configuration files | `src/views/settings/sections/PermissionMatrix.vue`, `RbacConfiguration.vue`, mounted in `src/views/settings/Settings.vue` |

The other access rows already have a requirement that says them; this PR records it as `built.requirement` in `openspec/parity/capabilities.json`.

## What changes

- `rbac-scopes`: one added requirement for the permission matrix screen.

## Screens

`openregister/OrBeheerinstellingen`; see `screens.md`.

## Out of scope

- Conditional rules and the department matrix in the screen (`rbac-department-role-matrix`, open).
- The matrix export with provenance (`rights-administration-hardening`, open).

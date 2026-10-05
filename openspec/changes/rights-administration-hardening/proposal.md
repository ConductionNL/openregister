---
kind: code
depends_on: [permission-provenance-and-deny]
---

# Proposal: rights-administration-hardening

## Why

Three rows ask for rights administration an auditor can trust. Our column (`baseline/openwoo.tsv`):

| row | capability | ours today |
|---|---|---|
| 12.23 | An administrator exports the full authorisation matrix of groups, users and rights | no: `AuthorizationAuditService` audits authorisation changes; `GET /api/scopes` answers one user about themself; `GET /api/permissions/scope-audit` and `compare-roles` answer narrower questions. Nothing exports the whole matrix |
| 12.24 | The product refuses to let an administrator grant rights beyond their own | partial: Nextcloud limits a delegated (sub)administrator to their own groups; nothing in OpenRegister refuses a grant beyond the granter's own rights, except the token rule `scoped-api-tokens` specifies ("manage cannot be granted", a shrunken user shrinks the token) |
| 12.26 | Some permission groups are owned by the product, and any edit to them is reverted on upgrade | partial: `GroupReconciler` (with `GroupReconcilerJob`) recreates a declared group that was deleted; it does not notice or revert an edit to a group that still exists |

`permission-provenance-and-deny` (34 of 34, built) gives every effective permission the rule that decided it (REQ-PPD-004). This change builds the export on that, generalises the token rule to every grant, and teaches the reconciler what a product-owned group's rights are.

## What changes

- **The matrix export (12.23).** `GET /api/permissions/matrix?format=csv|json&level=group|user` (administrator only) lists every group, or every user, against every register, schema and action, granted or not, with the rule that decided it from REQ-PPD-004. The export carries its provenance: when, by whom, instance, the count of rows and a SHA-256 of the body, and writes an administrative audit row. Streaming, so a large instance does not run out of memory.
- **No grant beyond your own (12.24).** One `GrantCeiling` check is called by every write that grants a right: schema and register authorization blocks and the role matrix, object shares with permissions, organisation roles, derived grant rules and API tokens. A caller who is not a Nextcloud administrator is refused when the grant gives an action, on a register or schema, that the caller does not hold there; `manage` is never grantable by a non-administrator. The refusal names each excess action.
- **Product-owned groups stay as shipped (12.26).** The configuration import stores, per app, the rights its configuration declares for each declared group (`declared_group_rights_<app>`). `GroupReconciler` compares the live authorization blocks with that declaration for the declared groups only, restores a removed right, removes an added one, writes an administrative audit row and logs it. It runs on `GroupReconcilerJob` and after every configuration import, so an upgrade reverts edits. Membership of the group is the organisation's data and is not touched. Rights on the same schemas for groups the organisation created itself are not touched.

## What does not change

- How rights are evaluated (`permission-provenance-and-deny`).
- Nextcloud's own subadmin limits.
- Local changes to shipped configuration for groups that are not product-owned (`local-changes-to-app-shipped-configuration`).

## Dependencies and absent apps

- `permission-provenance-and-deny` (built) for the provenance the export prints.
- `scoped-api-tokens` (amended in this programme, wave 1): its token rule becomes a caller of `GrantCeiling`; whichever lands second wires the other.
- `history-schema-and-settings-edits-audited` (amended, wave 1) gives the audit rows their administrative category; until it lands they are written without it.
- No other app is called.

## Wave and decision

Wave 1, size L. No decision bears on it. Closes 12.23, 12.24 and 12.26.

---
kind: code
---

# Proposal: bulk-save-applies-property-authorization

## Why

The single save (`SaveObject`) refuses a value the caller may not write (`getUnauthorizedProperties`) and keeps a
protected property a PUT leaves out (`collectOmittedProtectedProperties`, openregister#4862). The bulk save
(`SaveObjects`) did neither: its per-row safeguards check the object rule, drafts and appendOnly, but no property
rule. So a caller with object-level update could write a protected property through `/api/bulk/...` past every
property rule, fleet-wide (found by INT-OR on dossiq's `restricted` / `accessHandlers`). The mixed-schema route
(`/api/bulk/{register}/0/save`) also skipped `BulkController`'s schema `manage` gate.

## What changes

- **Per row, in `SaveObjects::applyBulkSafeguards`, after the object rule:** the same `PropertyRbacHandler` calls the
  single save makes. A row writing a value the caller may not write is refused into `invalid`, naming the
  properties; on an update, a stored property the row omits and the caller may not read or update is carried
  forward. Unchanged values pass, as in a PATCH. Admins are unaffected, as for every property rule.
- **The mixed-schema route** checks the `manage` rule of every schema its rows name (`@self.schema`); a named schema
  that cannot be resolved is refused, since its rule cannot be checked.

## Nested saves

Cascade and inverse-relation saves (`CascadingHandler`, `SaveObject` inverse relations and the streaming loop) go
through `SaveObject::saveObject` with `_rbac` true (explicitly or by default), so the single-save property rules
already apply to them. They do not inherit a caller's `_rbac: false`.

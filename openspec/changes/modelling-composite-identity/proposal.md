---
kind: code
---

# Proposal: modelling-composite-identity

## Summary

A functional administrator declares that a combination of fields identifies a
record, such as a municipality code plus a case number. Other software can then
read and change that record by its key, without knowing Open Register's uuid.
The combination stays unique and its fields cannot drift after the record is
created.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | mod-composite-key | Identify a record by a combination of fields, such as a municipality code plus a case number, instead of one id | no |

The row is in Open Register's own matrix, in its core area (modelling).
Demand: feature request, https://github.com/directus/directus/discussions/12137.
No competitor is rated yes on this row.

## Why

Objects are addressed by `{id}` only, a uuid or a slug
(`appinfo/routes.php:1177` `objects#show`, resolved by `ObjectService::find()` from
`lib/Controller/ObjectsController.php:2913`). A system that knows a case as
`0363` plus `Z-2026-0042` has to search first and then fetch by uuid, and a search
that matches two records gives it no way to tell.

Two pieces exist and neither is identity. A schema can declare named uniqueness
constraints (`configuration.uniqueConstraints`, read by
`lib/Service/Schemas/UniqueConstraintEvaluator.php` as `{name, properties, action}`
and enforced by `lib/Listener/UniqueConstraintListener.php`). That keeps the
combination unique; it does not let anyone address a record by it. And
`lib/Service/Import/MatchResolver.php:116` matches a row on several declared
properties, but only inside an import preview.

## What changes

- A named uniqueness constraint with action `refuse` may say `identity: true`. A
  schema has at most one identity constraint.
- `GET`, `PUT`, `PATCH` and `DELETE` on
  `/api/objects/{register}/{schema}/by-key?<property>=<value>&...` address the
  one object whose identity properties carry those values. A missing property is
  a 400, no match is a 404, and more than one match (data from before the
  constraint) is a 409 listing the uuids.
- An object carries its key as `@self.key`, the identity values joined in
  declared order.
- Once an object is created, a write that changes one of its identity properties
  is refused with a 422 that names the property. A key changes only through the
  schema migration path, which is audited.
- The generated OpenAPI document describes the by-key paths for a schema that
  declares an identity.

## Consumers

- integriq source adapters and synchronisations can upsert and fetch by the
  source system's key (see also `api-upsert-on-a-declared-key` in this pass).
- dossiq and decidiq can expose a case or a decision by its number to outside
  systems without leaking uuids.

## ADRs

- hydra ADR-002 (API): one resource, one canonical path; the by-key path resolves
  to the same object and renders it the same way.
- hydra ADR-005 (security): a lookup honours RBAC and multitenancy exactly as
  `objects#show` does; a record the caller may not read answers 404, not 403.
- hydra ADR-058 (bounded object queries) and openregister ADR-009: the lookup is
  one indexed query capped at two rows.

## Impact

- Extends the `objects-crud` capability.
- Affected code: `UniqueConstraintEvaluator` (the `identity` flag), a
  `ObjectKeyResolver` service, four routes in `appinfo/routes.php` declared before
  `objects#show`, `ObjectsController`, `RenderObject` (`@self.key`), the save
  path guard, `OasService`.
- Backwards compatible: a schema without an identity constraint behaves as today.
- Size: M.

## Out of scope

- Relations that point at another object by its key instead of its uuid. A
  relation keeps storing the uuid; the key is how outside software finds it.
- Replacing the uuid as the internal id. The uuid stays the primary key.

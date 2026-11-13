---
kind: code
---

# Proposal: api-upsert-on-a-declared-key

## Summary

An integration sends one record and names the key that identifies it, such as a municipality code plus a case number. Open Register updates the record that carries that key, or creates it when none does, in one call. The key is one the schema already declares as unique, so an integration cannot match on a field that was never meant to identify a record. When the key matches more than one record, nothing is written and the answer lists the matches. The caller sees 201 for a new record and 200 for an updated one.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | api-upsert | Create a record or update the existing one that matches a key, in a single API call. | partial |

**api-upsert** (openregister's matrix)

- Demand: feature request, https://github.com/nocodb/nocodb/issues/5126 (the row's origin).
- Competitor yes cells:
  - nocodb (NocoDB), no evidence URL, source path cited: "source read at 2026.09.0, not driven: v3 data API route POST records/upsert nocodb:packages/nocodb/src/controllers/v3/data-v3.controller.ts:67 creates or updates records matched on given fields".

## Why

Open Register upserts today, but only on the record's own id.

- `POST /api/objects/{register}/{schema}` (`appinfo/routes.php:1174`) calls `ObjectsController::create()` (`lib/Controller/ObjectsController.php:3250`), which saves with `uuid: null` (`:3354-3364`). `SaveObject` updates when the body's identifier already exists (`lib/Service/Object/SaveObject.php:3257-3300`), and `_failIfExists` turns that off (`lib/Controller/ObjectsController.php:3312-3322`). A caller that knows a case by its number and not by its uuid cannot use it.
- Matching on a declared business key exists, but only inside an import. `lib/Service/Import/MatchResolver.php:116` resolves a row against declared properties and returns every match, capped at three (`:55`). It is called from the import preview (`appinfo/routes.php:1337-1342`, `matchKey` read at `lib/Controller/ImportPreviewController.php:229`), which is two calls, a stored preview and a commit.
- A schema can already declare which combinations are unique: `configuration.uniqueConstraints`, read by `lib/Service/Schemas/UniqueConstraintEvaluator.php:92` as `{name, properties, action}`, enforced on every save by `lib/Listener/UniqueConstraintListener.php:122-190`. Nothing lets a write name one of those constraints as the key to match on.

## What changes

- `POST /api/objects/{register}/{schema}?_upsertOn=<constraint>` names a declared uniqueness constraint with action `refuse`. Open Register reads the constraint's property values from the body and matches on them with `MatchResolver`.
- No match: the record is created, 201. One match: that record is updated with the body, 200. More than one match: 409 listing the matches the caller may read, and nothing is written.
- An unknown constraint name, a `report` constraint, or a body missing one of the key's values is a 400 that names the problem and lists the schema's `refuse` constraints.
- A key held by a record the caller may not read answers 409 without that record's uuid.
- The lookup and the write run under one lock per register, schema and key value, so two concurrent calls with the same key produce one record.
- `_upsertOn` requires a signed-in caller. An anonymous caller gets 401.
- The generated OpenAPI document lists `_upsertOn` on the collection POST, with the schema's `refuse` constraint names as its enum.

## Consumers

- integriq (openconnector) synchronisations write records from a source system that knows its own key and not Open Register's uuid. Today they search, then create or update, in two calls with a race between them.
- `modelling-composite-identity` (this pass, PR1) makes one `refuse` constraint a schema's identity. That identity is a valid `_upsertOn` value like any other `refuse` constraint; this change does not wait for it.

## ADRs

- hydra ADR-002 (api): the upsert stays on the collection POST; no new resource path.
- hydra ADR-005 (security) and openregister ADR-002 (organisation tenancy): the match runs under the caller's RBAC and organisation; a record the caller cannot see is never updated and never named.
- hydra ADR-058 (bounded object queries) and openregister ADR-009: the lookup is one filtered query capped at three rows, like the import's.
- hydra ADR-105 (controller exception translation): each outcome has its own status (400, 401, 403, 409, 503), none flattened into 403.
- openregister ADR-003 (immutable audit trail): the update writes the normal update audit row.

## Impact

- Extends the capability `objects-crud`.
- Affected code: `lib/Controller/ObjectsController.php` (`create()`), a new `lib/Service/Object/UpsertOnKeyHandler.php`, `lib/Service/Import/MatchResolver.php` (reused unchanged), `lib/Service/Schemas/UniqueConstraintEvaluator.php` (reused), `lib/Service/OasService.php` (`createPostOperation`).
- Backwards compatible. Without `_upsertOn` the POST behaves exactly as today, including the id-based upsert and `_failIfExists`.
- Size: S.

## Out of scope

- Upsert on a key in the bulk save route (`/api/bulk/{register}/{schema}/save`). A batch matched on a key goes through `import-preview-and-conflict-policy`, which already declares the key and a conflict policy for a whole file.
- Matching on fields a caller picks per call without a declared constraint. That is deliberate, see design D-1.
- Choosing which of several matches to update. More than one match is always refused, as in the import (`import-preview-and-conflict-policy` design D-3).

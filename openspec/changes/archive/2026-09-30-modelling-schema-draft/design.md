# Design: modelling-schema-draft

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Schema entity | `lib/Db/Schema.php` |
| Update and versioning | `lib/Controller/SchemasController.php` update, `lib/Service/Schema/SchemaVersioningService.php` |

## Approach

1. Add a nullable `draft` JSON column to the schemas table (migration) holding the pending definition.
2. Draft save, publish and discard routes on SchemasController; publish calls the existing update with the draft body.

## Declarative or imperative

Imperative: a schema edit mode, not a declaration.

## Tests

- PHPUnit: a draft with a new required field does not refuse a record saved while the draft exists; after publish it does and the version is bumped with one changelog entry.

## As built (2026-09-30)

- Draft save is `PUT /api/schemas/{id}?draft=true` on the existing update route; publish is `POST /api/schemas/{id}/draft/publish` (takes `acknowledgeBreaking` and `renames` like an update); discard is `DELETE /api/schemas/{id}/draft`. `SchemasController::update()` now delegates to `applyUpdate()`, which publish reuses.
- `Schema::hydrate()` drops a `draft` key, so an import or a PUT body cannot set one.
- The shared nc-vue `CnSchemaFormDialog` has no slot for extra footer buttons, so the editor does not grow three buttons. Instead the schema page has an "Edit as draft" action that opens the same editor in draft mode (its Save becomes "Save draft"), and a draft bar on the schema page shows a pending draft with Publish draft, Discard draft and Edit draft. A breaking draft is a question on the bar ("Publish anyway"), never answered for the user.

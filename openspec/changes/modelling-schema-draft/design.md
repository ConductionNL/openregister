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

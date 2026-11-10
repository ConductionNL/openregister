---
status: draft
---
# Capability: `runtime-schema-api`

## Purpose

Extend runtime schema deletion with a cascade disposition. `DELETE /api/schemas/{id}`
currently offers only two outcomes — refuse with HTTP 409, or `?force=true` and orphan
the objects forever. This delta adds `?deleteObjects=true`, which hard-deletes the
schema's objects (audited), drops the now-empty magic table, and then deletes the
schema, leaving no orphans. It also repairs the mapper-level guard, which today queries
a retired table and therefore guards nothing.

## MODIFIED Requirements

### Requirement: Runtime schema deletion is guarded by object count

The system SHALL refuse `DELETE /api/schemas/{id}` with HTTP 409
`{ "error": "schema-has-objects", "objectCount": N }` when any objects
exist that reference the schema. Callers MAY override the guard in exactly
two ways, which are **mutually exclusive**:

- `?deleteObjects=true` — the **cascade** disposition. The system SHALL
  hard-delete every object of the schema (each one audited per the
  `deletion-audit-trail` capability), SHALL drop the schema's now-empty magic
  table, and SHALL then delete the schema, leaving no orphaned rows and no
  orphaned table.
- `?force=true` — the legacy disposition, retained for API back-compat. It
  deletes the schema and **detaches** its objects, orphaning them. It MUST NOT
  be exposed in any user interface.

Passing both flags SHALL be refused with HTTP 400. The object count N SHALL
exclude soft-deleted rows.

A successful delete (by any disposition) MUST invalidate the schema cache and
remove the schema from every engine's registry.

The guard MUST be enforced at the mapper level (`SchemaMapper::delete()`), not
only in the controller, because the mapper is the choke point shared by all
deletion callers — including the AI-facing `SchemasToolProvider` and `SchemaTool`
surfaces. The mapper's object count MUST be taken from the **magic tables**; a
count taken from the retired `openregister_objects` blob table is always 0 for
magic-table objects and therefore guards nothing. `SchemaMapper::delete()` SHALL
accept an explicit force/bypass parameter, defaulting to refusing, so that only a
deliberate `?force=true` request can orphan data.

#### Scenario: Delete a schema with objects, no flag
- **WHEN** a client DELETEs `/api/schemas/{id}` where N > 0 objects
  reference the schema
- **THEN** the response is HTTP 409 with body
  `{ "error": "schema-has-objects", "objectCount": N }` and the schema
  remains persisted, along with all of its objects

#### Scenario: Cascade — delete a schema and its objects
- **WHEN** a client DELETEs `/api/schemas/{id}?deleteObjects=true` where N > 0
  objects reference the schema
- **AND** the caller passes `checkSchemaManagePermission()`
- **THEN** every object of the schema is hard-deleted, the schema's magic table
  is dropped, and the schema is removed
- **AND** the response body reports `deletedCount: N`, the deleted UUIDs, and
  `tableDropped: true`
- **AND** no row, no magic table, and no schema record referencing the schema remains

#### Scenario: Cascade on a schema with zero objects
- **WHEN** a client DELETEs `/api/schemas/{id}?deleteObjects=true` where 0 objects
  reference the schema
- **THEN** the schema is removed and its empty magic table is dropped
- **AND** the response reports `deletedCount: 0` and `tableDropped: true`

#### Scenario: Cascade rolls back when object deletion fails
- **WHEN** a cascade delete is in progress and any audit write or row deletion fails
- **THEN** the whole transaction is rolled back
- **AND** the schema and every one of its objects remain exactly as they were
- **AND** the response is HTTP 500

#### Scenario: Cascade succeeds but the table drop fails
- **GIVEN** the objects and the schema have been deleted and committed
- **WHEN** dropping the magic table fails
- **THEN** the response is still success, reporting `tableDropped: false`
- **AND** the failure is logged at WARNING level
- **AND** the leftover table is empty, and no rollback is attempted

#### Scenario: Both dispositions passed at once
- **WHEN** a client DELETEs `/api/schemas/{id}?deleteObjects=true&force=true`
- **THEN** the response is HTTP 400 and the schema remains persisted

#### Scenario: Delete a schema with objects and force=true
- **WHEN** a client DELETEs `/api/schemas/{id}?force=true` where N > 0
  objects reference the schema
- **THEN** the response is HTTP 204, the schema is removed,
  `SchemaCacheHandler::invalidate({id})` is called, and every engine's
  `reloadForSchema({id})` is invoked to drop its registry entry
- **AND** the objects are orphaned and the magic table is left in place
  (unchanged legacy behaviour)
- **AND** the action is logged at WARNING level with the actor and the orphan count

#### Scenario: Delete an unused schema
- **WHEN** a client DELETEs `/api/schemas/{id}` where 0 objects
  reference the schema
- **THEN** the response is HTTP 204 and the schema is removed

#### Scenario: Mapper guard protects non-controller callers
- **GIVEN** a schema with N > 0 objects in its magic table
- **WHEN** `SchemaMapper::delete()` is invoked without the force parameter from any
  caller (including `SchemasToolProvider` and `SchemaTool`)
- **THEN** a `ValidationException` is thrown and the schema is not deleted

#### Scenario: Cascade requires manage permission
- **WHEN** a client DELETEs `/api/schemas/{id}?deleteObjects=true` and
  `checkSchemaManagePermission()` returns false
- **THEN** the response is HTTP 403 and neither the schema nor any object is deleted

## ADDED Requirements

### Requirement: Schema-wide object deletion is available as a bulk operation

The system SHALL provide a working schema-wide object delete that removes every
object of a register/schema pair **without** deleting the schema itself, exposed at
`POST /api/bulk/{register}/{schema}/delete-objects`. The operation SHALL be gated on
`checkSchemaManagePermission()` and SHALL return the deleted count and the deleted UUIDs.

`ObjectService::deleteObjectsBySchema()` SHALL implement this on top of
`MagicMapper::deleteObjectsBySchema()`. It MUST collect the object UUIDs and snapshots
**before** deleting the rows, because the mapper primitive returns only a count.

#### Scenario: Bulk-delete every object of a schema
- **WHEN** a client POSTs to `/api/bulk/{register}/{schema}/delete-objects`
- **AND** the caller passes `checkSchemaManagePermission()`
- **THEN** every object of that register/schema is deleted
- **AND** the response reports `deleted_count` and `deleted_uuids`
- **AND** the schema itself remains persisted

#### Scenario: Schema-wide object delete no longer returns HTTP 500
- **GIVEN** `ObjectService::deleteObjectsBySchema()` previously threw a
  `RuntimeException` stub ("needs reimplementation using MagicMapper")
- **WHEN** either `/delete-objects` or the legacy `/delete-schema` bulk route is called
- **THEN** the request succeeds instead of returning HTTP 500

#### Scenario: Bulk delete on a schema with no objects
- **WHEN** a client POSTs to `/api/bulk/{register}/{schema}/delete-objects` for a
  schema with zero objects
- **THEN** the response is success with `deleted_count: 0` and an empty `deleted_uuids`

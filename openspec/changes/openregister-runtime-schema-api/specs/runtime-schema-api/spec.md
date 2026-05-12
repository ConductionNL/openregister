## ADDED Requirements

### Requirement: Runtime schema creation invalidates cache and reloads declarative engines

The system SHALL accept `POST /api/schemas` at runtime from any
authenticated caller with the `openregister.schema.write` permission,
persist the new schema via `SchemaMapper::insert`, invalidate the
schema cache for the affected ID, and re-bind every declarative engine
(`lifecycle`, `aggregations`, `calculations`, `notifications`) for the
new schema before returning the response. The next request — including
a request in the same PHP worker — MUST observe the new schema in
`SchemaService::find`, in cache, and in every engine's registry.

#### Scenario: Create schema with lifecycle metadata
- **WHEN** a client POSTs a schema body containing
  `x-openregister-lifecycle` to `/api/schemas`
- **THEN** the response is HTTP 201 with the canonical schema entity,
  `SchemaCacheHandler::invalidate(newId)` has been called,
  `LifecycleEngine::reloadForSchema(newId)` has been called, and a
  follow-up `GET /api/schemas/{newId}` in the same worker returns the
  freshly-persisted schema

#### Scenario: Create schema without declarative metadata
- **WHEN** a client POSTs a schema body containing no `x-openregister-*`
  blocks
- **THEN** the response is HTTP 201, the cache invalidator is called,
  and each declarative engine MAY skip its reload step but MUST NOT
  raise an error

### Requirement: Runtime schema update invalidates cache and reloads affected engines

The system SHALL accept `PUT /api/schemas/{id}` and
`PATCH /api/schemas/{id}` at runtime, persist the change via
`SchemaMapper::update`, invalidate the schema cache for the affected
ID, and re-bind only the declarative engines whose corresponding
`x-openregister-*` block changed value between the old and new schema.
Engines whose metadata did not change MUST NOT be reloaded.

#### Scenario: PATCH adds an aggregation
- **WHEN** a client PATCHes a schema to add an `x-openregister-aggregations`
  block where none existed
- **THEN** the response is HTTP 200,
  `AggregationEngine::reloadForSchema({id})` has been called, and the
  lifecycle / calculations / notifications engines have NOT been
  reloaded for that schema

#### Scenario: PUT replaces lifecycle block
- **WHEN** a client PUTs a schema whose `x-openregister-lifecycle` block
  differs from the persisted value
- **THEN** the response is HTTP 200 and
  `LifecycleEngine::reloadForSchema({id})` has been called exactly once

### Requirement: Runtime schema deletion is guarded by object count

The system SHALL refuse `DELETE /api/schemas/{id}` with HTTP 409
`{ "error": "schema-has-objects", "objectCount": N }` when any objects
exist that reference the schema. Callers MAY override the guard by
passing `?force=true`, which deletes the schema and detaches its
objects. A successful delete (with or without force) MUST invalidate
the schema cache and remove the schema from every engine's registry.

#### Scenario: Delete a schema with objects, no force flag
- **WHEN** a client DELETEs `/api/schemas/{id}` where N > 0 objects
  reference the schema
- **THEN** the response is HTTP 409 with body
  `{ "error": "schema-has-objects", "objectCount": N }` and the schema
  remains persisted

#### Scenario: Delete a schema with objects and force=true
- **WHEN** a client DELETEs `/api/schemas/{id}?force=true` where N > 0
  objects reference the schema
- **THEN** the response is HTTP 204, the schema is removed,
  `SchemaCacheHandler::invalidate({id})` is called, and every engine's
  `reloadForSchema({id})` is invoked to drop its registry entry

#### Scenario: Delete an unused schema
- **WHEN** a client DELETEs `/api/schemas/{id}` where 0 objects
  reference the schema
- **THEN** the response is HTTP 204 and the schema is removed

### Requirement: Same CRUD guarantees apply to /api/registers

The system SHALL apply the same cache-invalidation, deletion-guard, and
audit-emit guarantees on `/api/registers` as on `/api/schemas`. Register
deletion MUST refuse when any schemas attached to the register still
have objects, unless `?force=true` is passed.

#### Scenario: Create register at runtime
- **WHEN** a client POSTs a register body to `/api/registers`
- **THEN** the response is HTTP 201 and
  `RegisterCacheHandler::invalidate({newId})` has been called

#### Scenario: Delete register with attached schemas-with-objects
- **WHEN** a client DELETEs `/api/registers/{id}` where any attached
  schema has objects
- **THEN** the response is HTTP 409 with body
  `{ "error": "register-has-objects", "objectCount": N }`

#### Scenario: PATCH register schemas[] field
- **WHEN** a client PATCHes a register to add or remove a schema ID
  from `schemas[]`
- **THEN** the response is HTTP 200 and
  `RegisterCacheHandler::invalidate({id})` has been called

### Requirement: ObjectService.searchObjectsBySlug resolves slugs at the slug-aware layer

The system SHALL expose `ObjectService::searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters): array` which resolves the
register slug and schema slug to their numeric IDs via
`RegisterMapper::findBySlug` and `SchemaMapper::findBySlug`,
constructs the `@self` filter with numeric IDs, and delegates to
`ObjectService::searchObjects`. The existing `searchObjects` method
SHALL document in its docblock that `@self.register` and
`@self.schema` MUST be numeric IDs, not slugs.

#### Scenario: Search by slug-pair
- **WHEN** a caller invokes
  `searchObjectsBySlug('openbuilt', 'application', ['status' => 'published'])`
- **THEN** the method resolves both slugs to numeric IDs and the
  resulting query is identical to
  `searchObjects(['@self.register' => 7, '@self.schema' => 42, 'status' => 'published'])`

#### Scenario: Unknown register slug
- **WHEN** a caller invokes `searchObjectsBySlug` with a register slug
  that no Register entity uses
- **THEN** the method throws `DoesNotExistException` with a message
  identifying the unknown slug

#### Scenario: Unknown schema slug
- **WHEN** a caller invokes `searchObjectsBySlug` with a register slug
  that resolves and a schema slug that no Schema entity uses
- **THEN** the method throws `DoesNotExistException` identifying the
  schema slug

### Requirement: importFromApp auto-creates Register from x-openregister.app

The system SHALL, when `ImportHandler::importFromApp` processes a
configuration whose root carries `x-openregister.type=application`,
create or update a Register entity using `x-openregister.app` as the
slug, `info.title` as the title, and `info.description` as the
description. The lookup MUST be idempotent per
`(slug, organisationId)`: a re-import on the same slug+org pair MUST
update the existing register rather than insert a duplicate. Every
schema imported in the same call MUST be appended to the resulting
Register's `schemas[]` field if not already present.

#### Scenario: First import of an OpenBuilt application config
- **WHEN** `ImportHandler::importFromApp` runs against an OAS document
  with `x-openregister.type=application`, `x-openregister.app=openbuilt`,
  `info.title='OpenBuilt'`, and 3 schemas
- **THEN** a new Register row with slug=`openbuilt`, title=`OpenBuilt`,
  and `schemas` containing the 3 newly-created schema IDs is persisted

#### Scenario: Re-import of the same application config
- **WHEN** the same import runs a second time against the same
  organisation
- **THEN** the existing Register row is found by `(slug=openbuilt, org)`,
  its `schemas[]` is reconciled (no duplicates), and no second Register
  row is created

#### Scenario: Import config without application type
- **WHEN** `ImportHandler::importFromApp` runs against an OAS document
  with no `x-openregister.type` or `x-openregister.type=library`
- **THEN** no Register row is auto-created; the existing pre-spec
  behaviour is preserved

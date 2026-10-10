---
status: done
---

# runtime-schema-api Specification

## Purpose
Provides runtime CRUD on registers and schemas so that creating, updating, or deleting a schema or register immediately invalidates the relevant cache and re-binds the affected declarative engines (lifecycle, aggregations, calculations, notifications) without a restart. Deletions are guarded by object counts and refuse with HTTP 409 unless forced, and slug-pairs are resolved to numeric IDs at the slug-aware layer for object search and application-config imports.

## Requirements

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
  `searchObjectsBySlug('openbuild', 'application', ['status' => 'published'])`
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

#### Scenario: First import of an OpenBuild application config
- **WHEN** `ImportHandler::importFromApp` runs against an OAS document
  with `x-openregister.type=application`, `x-openregister.app=openbuild`,
  `info.title='OpenBuild'`, and 3 schemas
- **THEN** a new Register row with slug=`openbuild`, title=`OpenBuild`,
  and `schemas` containing the 3 newly-created schema IDs is persisted

#### Scenario: Re-import of the same application config
- **WHEN** the same import runs a second time against the same
  organisation
- **THEN** the existing Register row is found by `(slug=openbuild, org)`,
  its `schemas[]` is reconciled (no duplicates), and no second Register
  row is created

#### Scenario: Import config without application type
- **WHEN** `ImportHandler::importFromApp` runs against an OAS document
  with no `x-openregister.type` or `x-openregister.type=library`
- **THEN** no Register row is auto-created; the existing pre-spec
  behaviour is preserved

### Requirement: REQ-SDRAFT-001 A schema edit can be held as a draft until it is published

A schema SHALL accept a draft of its definition that does not affect validation of records until it is published. `PUT /api/schemas/{id}?draft=true` SHALL store the edit as the draft only. `POST /api/schemas/{id}/draft/publish` SHALL apply the draft through the normal update, with its breaking-change gate, version bump and one changelog entry, and SHALL clear it; a refused publish SHALL keep the draft. `DELETE /api/schemas/{id}/draft` SHALL remove it. An import SHALL NOT carry a draft.

#### Scenario: a draft does not refuse live records

- **GIVEN** a published schema and a draft that makes `email` required
- **WHEN** a client saves a record without `email`
- **THEN** the record is saved
- @e2e exclude {asserted against the real versioning service and Opis in tests/Unit/Controller/SchemaDraftTest.php testADraftDoesNotRefuseLiveRecords}

#### Scenario: publishing applies the draft

- **GIVEN** the same draft
- **WHEN** the administrator publishes it
- **THEN** a record without `email` is refused, the schema version is bumped and the changelog has one entry for the change
- @e2e exclude {asserted in tests/Unit/Controller/SchemaDraftTest.php testPublishingAppliesTheDraftOnceWithOneChangelogEntry}

### Requirement: A schema keeps its declared uniqueness constraints on save

A schema SHALL keep `configuration.uniqueConstraints` through every create, update and import, in the list form (`[{name, properties, action}]`) and the keyed form (`{name: {properties, action}}`), beside the legacy `configuration.unique`. The constraints served back SHALL be the ones declared, so the uniqueness check on a record save reads what the schema owner wrote.

#### Scenario: a declared refuse constraint survives the save

- **GIVEN** a client creates a schema with `configuration.uniqueConstraints` `[{"name": "een-bezwaar", "properties": ["besluit", "indiener"], "action": "refuse"}]`
- **WHEN** the schema is read back
- **THEN** its configuration carries that constraint unchanged
- **AND** the uniqueness check reads `een-bezwaar` as a refuse constraint
- @e2e exclude {asserted on the real entity and the real evaluator in tests/Unit/Db/SchemaUniqueConstraintsConfigTest.php; tests/e2e/ci/code-list-lifecycle.spec.ts drives the refusal live}

#### Scenario: a record that shares its key can still be deleted

- **GIVEN** two records holding the same key, saved before the schema declared a refuse constraint on it
- **WHEN** either record is deleted
- **THEN** the delete succeeds, because removing a record never breaches uniqueness
- @e2e exclude {asserted on the real listener, evaluator and ObjectUpdatingEvent in tests/Unit/Listener/UniqueConstraintListenerDeleteTest.php; the Newman upsert collection deletes both duplicates in its tearDown}

### Requirement: A property declares its meaning and its help text (REQ-CLH-003)

A property MAY declare a semantic role of `title`, `status`, `assignee` or
`term`, and a schema declaring the same role on two properties SHALL fail
to save naming both. A property MAY carry administered help text,
resolvable per language, beside its existing description, and the schema
read SHALL return it in the negotiated language.

#### Scenario: one list component serves an unknown schema

- **GIVEN** a schema declaring `onderwerp` as the title and `fase` as the status
- **WHEN** the schema is read
- **THEN** the roles are returned with the properties that hold them

#### Scenario: two titles are refused

- **GIVEN** a schema declaring the role `title` on two properties
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming both properties
- @e2e exclude {validator, covered by unit tests}

#### Scenario: help text is read in the caller's language

- **GIVEN** a property with Dutch and English help text
- **WHEN** the schema is read with `Accept-Language: nl`
- **THEN** the Dutch help text is returned
- @e2e exclude {negotiation, covered by unit tests}

### Requirement: Uniqueness over a named field combination (REQ-CLH-004)

A schema MAY declare a uniqueness constraint over a named combination of
properties, with the action `refuse` or `report` on a breach. A `refuse`
constraint SHALL fail the save with 422 naming the combination and the
conflicting object. A `report` constraint SHALL let the save succeed and
SHALL record the breach where it can be read.

#### Scenario: a second bezwaar on one besluit is refused

- **GIVEN** a constraint over `besluit` and `indiener` with action `refuse`, and an object holding that pair
- **WHEN** a second object with the same pair is saved
- **THEN** the save fails with 422 naming both properties and the existing object

#### Scenario: a reporting constraint does not block the intake

- **GIVEN** a constraint over `email` with action `report`
- **WHEN** a second object with the same e-mail is saved
- **THEN** the save succeeds and the breach is recorded and readable

### Requirement: A property type change on populated objects is a declared conversion (REQ-CLH-005)

The system SHALL publish which property type conversions it supports. A
conversion request on a property that has stored values SHALL first return
a preview over those values, naming how many convert and which do not. A
conversion that is not on the published list SHALL be refused with a
reason, never attempted.

#### Scenario: an administrator sees what a conversion would cost

- **GIVEN** a string property with 4,000 stored values of which 12 are not numeric
- **WHEN** a conversion to number is previewed
- **THEN** the preview reports 3,988 convertible and names the 12 that are not

#### Scenario: an unsupported conversion is refused

- **GIVEN** a request to convert a file property to a number
- **WHEN** the conversion is requested
- **THEN** it is refused with a reason and the property is unchanged
- @e2e exclude {validator, covered by unit tests}

### Requirement: The property vocabulary is published (REQ-PVP-001)

The system SHALL publish every property type it accepts, with the
constraint keys that type takes, the formats it supports and a description
a human can read. The published list SHALL be generated from the same
source the save-time validator reads, so the two cannot disagree. Each
entry SHALL state whether converting a populated property to that type is
supported.

#### Scenario: an editor is generated rather than typed

- **GIVEN** an instance whose validator accepts more property types than any
  leaf editor offers
- **WHEN** the property vocabulary is read
- **THEN** every type the validator accepts is returned, each with its
  constraint keys, its formats and its conversion answer

#### Scenario: the published list cannot drift from the validator

- **GIVEN** a type accepted by the validator
- **WHEN** the vocabulary is read
- **THEN** that type is present
- @e2e exclude {generated from one source, covered by a unit test that compares the two}

### Requirement: A declared property is validated against the vocabulary (REQ-PVP-002)

A schema save naming a property type, a constraint key or a format that
the vocabulary does not hold SHALL fail with HTTP 422 naming the offending
value. A property that validates today SHALL continue to validate.

#### Scenario: a typo does not become an untyped string

- **GIVEN** a schema declaring a property of type `sting`
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming `sting`

#### Scenario: an unknown constraint key is refused

- **GIVEN** a property declaring a constraint key the vocabulary does not hold
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming the key
- @e2e exclude {validator, covered by unit tests}

### Requirement: An app's property form declares what it forwards (REQ-PVP-003)

An application that lets an administrator author schema properties through
its own form SHALL declare which vocabulary keys that form forwards. The
declaration SHALL map each vocabulary key to the application's own field
name, in that direction, because that is the direction the shipped consumer
reads. The declaration SHALL be validated against the vocabulary, a
vocabulary key the vocabulary does not hold SHALL be refused naming it, and
a forwarded property SHALL be validated exactly as a directly declared one.
The declared narrowing SHALL be readable, so the difference between the
app's list and the vocabulary can be counted.

#### Scenario: a narrower editor is a stated narrowing

- **GIVEN** an app whose property form forwards six of the vocabulary's keys,
  each named on the left of its map
- **WHEN** its declaration is read
- **THEN** the six are listed and the keys it does not forward can be derived

#### Scenario: forwarding a key nobody defines is refused

- **GIVEN** an app declaring that its form forwards a key the vocabulary does not hold
- **WHEN** the declaration is saved
- **THEN** it is refused naming the key
- @e2e exclude {validator, covered by unit tests}

#### Scenario: a key an app owns stays out of the vocabulary until it is defined

- **GIVEN** a property annotation in the `x-` namespace that this layer does
  not define
- **WHEN** a schema carrying it is saved
- **THEN** the save succeeds and the annotation is not published as a
  vocabulary key
- @e2e exclude {vendor-extension passthrough, covered by a unit test}

### Requirement: A repeating group is a declared property kind (REQ-RGC-001)

A property MAY be declared a repeating group, naming its member
properties, a minimum and a maximum count, whether the order is
meaningful, and which member acts as the item label. Each item SHALL be
validated the way an object is validated, and a violation SHALL name the
item's position and the member property. Counts outside the declared
bounds SHALL be refused.

#### Scenario: two gemachtigden on one record

- **GIVEN** a repeating group `gemachtigden` with a maximum of three
- **WHEN** two items are saved
- **THEN** both are stored, in order, each validated

#### Scenario: a violation says which item

- **GIVEN** the same group and an item missing a required member
- **WHEN** it is saved
- **THEN** the refusal names the item's position and the member property

#### Scenario: the maximum is enforced

- **GIVEN** the same group with a maximum of three
- **WHEN** four items are saved
- **THEN** the write is refused, naming the maximum

### Requirement: A property records that a value was not supplied, with a reason (REQ-RGC-002)

A property MAY be marked as not supplied, carrying a reason from an
administered list. That state SHALL be distinguishable from empty, SHALL
satisfy a required-value rule, and SHALL be readable with the object.

#### Scenario: honest incompleteness beats a typed "onbekend"

- **GIVEN** a required property and an administered reason
- **WHEN** the property is marked not supplied with that reason
- **THEN** the object saves and the property reads as not supplied with the reason

#### Scenario: not supplied is not empty

- **GIVEN** one object with an empty property and one marked not supplied
- **WHEN** both are read
- **THEN** the two states are distinguishable

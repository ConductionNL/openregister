# Register Service Extensions

## Purpose

Defines the `_extend` contract on `RegisterService` so that any caller — HTTP controllers or other apps consuming the service via DI — can request post-processed register payloads (e.g. schemas hydrated from IDs into full objects, object counts per schema) with identical semantics and output. Also establishes the `lib/Service/Serializer/` namespace as the home for entity serializers that implement this contract.

## ADDED Requirements

### Requirement: RegisterService SHALL expose serialized query methods that honor `_extend`

`RegisterService` MUST provide `findAllSerialized(...)` and `findSerialized($id, ...)` methods that return arrays of register data with all requested `_extend` transformations applied. These methods MUST produce the same per-register payload that the `RegistersController::index()` endpoint returns for equivalent inputs, with one documented exception: orphan schema IDs are retained (see "Missing schema ID" requirement below).

The entity-returning methods `findAll()` and `find()` MUST keep their current signatures and entity return types; `_extend` has no effect when called through them (it is declared for signature compatibility only).

#### Scenario: DI caller requests expanded schemas

- **GIVEN** a register with schema IDs `[1, 2, 3]` persisted in the database
- **WHEN** a DI caller invokes `$registerService->findAllSerialized(_extend: ['schemas'])`
- **THEN** the returned array's `schemas` field MUST contain three schema objects (each with `id`, `title`, and the other fields `Schema::jsonSerialize()` produces), not an array of IDs
- **AND** each schema object MUST be retrieved via `SchemaMapper::find()` with `_multitenancy: false`

#### Scenario: DI caller omits `_extend`

- **WHEN** a DI caller invokes `$registerService->findAllSerialized()` (no `_extend`)
- **THEN** the returned array's `schemas` field MUST be the ID array exactly as produced by `Register::jsonSerialize()` — no expansion applied
- **AND** no additional database queries (beyond the register fetch) MUST be issued

#### Scenario: Entity-returning `findAll` is unaffected

- **WHEN** any caller invokes `$registerService->findAll(_extend: ['schemas'])`
- **THEN** the method MUST return `Register[]` entities as before
- **AND** calling `->jsonSerialize()` on each entity MUST return schemas as an ID array (no expansion)

### Requirement: `schemas` extension SHALL replace schema IDs with full schema objects

When `_extend` contains the string `'schemas'`, the serializer MUST replace each schema ID on each serialized register with the corresponding full schema object retrieved from `SchemaMapper`. The ordering of entries in the output `schemas` array MUST match the ordering of IDs on the register entity. The `properties` field of each schema MUST be preserved — the serializer MUST NOT strip it.

#### Scenario: Schema expansion preserves order and shape

- **GIVEN** a register with schemas `[10, 20]`, where schema 10 and schema 20 both exist
- **WHEN** the serializer is invoked with `_extend: ['schemas']`
- **THEN** the output `schemas` MUST be `[<schema-10-json>, <schema-20-json>]` in that order
- **AND** each element MUST equal `$schemaMapper->find($id, _multitenancy: false)->jsonSerialize()`
- **AND** the `properties` field of each schema MUST be present in the output

#### Scenario: Register with empty schemas array

- **GIVEN** a register with `schemas = []`
- **WHEN** the serializer is invoked with `_extend: ['schemas']`
- **THEN** the output `schemas` MUST be an empty array
- **AND** no calls to `SchemaMapper::find()` MUST be made

### Requirement: Missing schema ID SHALL be retained in place, not dropped

When `SchemaMapper::find()` throws `DoesNotExistException` for a schema ID referenced by a register, the serializer MUST keep the original ID in its original position within the output `schemas` array, producing a mixed array of objects and IDs. This diverges from `RegistersController::index()`'s pre-refactor behavior, which dropped the missing schema; the new behavior aligns with OpenRegister's established "preserve original identifier on hydration failure" convention (e.g. `RenderObject` preserves the UUID when cache lookup fails). The wire-format implications for typed JSON consumers are documented in the proposal and changelog.

The serializer MUST log a warning via the injected `LoggerInterface` with the failed schema ID in context.

#### Scenario: Orphan schema ID is preserved

- **GIVEN** a register with schemas `[10, 999, 20]`, where schema 10 and schema 20 exist but schema 999 has been deleted
- **WHEN** the serializer is invoked with `_extend: ['schemas']`
- **THEN** the output `schemas` MUST equal `[<schema-10-json>, 999, <schema-20-json>]` — the missing ID preserved in its original position
- **AND** a warning MUST be logged with the failing schema ID (`999`) in context
- **AND** no exception MUST propagate to the caller

#### Scenario: ID type is preserved for orphan schemas

- **GIVEN** a register with schemas `["uuid-abc-123", 20]`, where the UUID-referenced schema cannot be resolved
- **WHEN** the serializer is invoked with `_extend: ['schemas']`
- **THEN** the output `schemas` MUST equal `["uuid-abc-123", <schema-20-json>]` — the orphan entry retains its original type (string UUID stays a string; numeric ID stays numeric)

### Requirement: `@self.stats` extension SHALL attach per-schema object counts to expanded schemas only

When `_extend` contains both `'schemas'` and `'@self.stats'`, each *successfully expanded* schema object in the output MUST receive a `stats.objects.total` field populated from `RegisterService::getSchemaObjectCounts()`. Expanded schemas not present in the counts result MUST receive `stats.objects.total = 0` (matching the current controller behavior). Orphan schema IDs (the bare-ID entries retained per the previous requirement) MUST NOT be augmented — they remain bare IDs.

Stats MUST be pre-computed by `RegisterService` before being passed to the serializer, so that the serializer itself does not depend on `RegisterService` (avoiding circular DI).

#### Scenario: Stats are attached to expanded schemas

- **GIVEN** a register with schemas `[10, 20]`, where schema 10 has 5 objects and schema 20 has 0 objects
- **WHEN** the serializer is invoked with `_extend: ['schemas', '@self.stats']`
- **THEN** the output schema with `id: 10` MUST have `stats.objects.total == 5`
- **AND** the output schema with `id: 20` MUST have `stats.objects.total == 0`

#### Scenario: Stats are NOT attached to orphan IDs

- **GIVEN** a register with schemas `[10, 999]`, where schema 10 exists and schema 999 does not
- **WHEN** the serializer is invoked with `_extend: ['schemas', '@self.stats']`
- **THEN** the output MUST be `[<schema-10-json-with-stats>, 999]` — the orphan entry is still a bare ID with no stats attached

#### Scenario: `@self.stats` without `schemas` has no effect on schemas

- **WHEN** the serializer is invoked with `_extend: ['@self.stats']` (no `'schemas'`)
- **THEN** the `schemas` field MUST remain an ID array (not expanded, not annotated)
- **AND** no schema-level stats MUST be attached

### Requirement: Unknown `_extend` keys SHALL be ignored silently

The serializer MUST accept any string values in `_extend` without error. Keys that are not recognized (anything other than `'schemas'` and `'@self.stats'`) MUST be silently ignored — no exception, no log entry. This matches the current `RegistersController::index()` behavior.

#### Scenario: Unknown key does not affect output

- **WHEN** the serializer is invoked with `_extend: ['schemas', 'nonexistent-key']`
- **THEN** the output MUST be identical to `_extend: ['schemas']`
- **AND** no warning or error MUST be emitted

### Requirement: Controller SHALL delegate `_extend` handling to the service

`RegistersController::index()` MUST obtain its final response body via `RegisterService::findAllSerialized(...)` (or the equivalent injected serializer path). The controller MUST NOT contain inline schema-expansion logic or inline per-schema stats loops.

#### Scenario: HTTP endpoint response shape is preserved for the happy path

- **GIVEN** a set of registers whose schema references all resolve successfully, and a request `GET /api/registers?_extend=schemas&_extend=@self.stats`
- **WHEN** the endpoint is called before and after this refactor
- **THEN** the JSON response body MUST be byte-equal (same keys, same ordering, same values) for identical database state

#### Scenario: HTTP endpoint response diverges only on orphan schema IDs

- **GIVEN** a register referencing a schema ID that has been deleted from the database
- **WHEN** `GET /api/registers?_extend=schemas` is called
- **THEN** the response's `schemas` array MUST contain the orphan ID in its original position
- **AND** pre-refactor behavior (dropping the orphan) MUST NOT be restored — this change is permanent

#### Scenario: Controller contains no expansion logic

- **WHEN** `RegistersController::index()` is inspected after the refactor
- **THEN** it MUST NOT directly call `SchemaMapper::find()` for schema expansion
- **AND** it MUST NOT iterate over register arrays to mutate their `schemas` field
- **AND** per-schema stats attachment logic MUST live in the serializer, not the controller

### Requirement: Register entity contract SHALL remain ID-only

`Register::jsonSerialize()` MUST continue to serialize `schemas` as an array of IDs (ints or strings). This spec change MUST NOT modify the entity's `jsonSerialize()` behavior.

#### Scenario: Direct entity serialization returns IDs

- **GIVEN** a Register entity with schemas `[10, 20]`
- **WHEN** `$register->jsonSerialize()` is called directly
- **THEN** the `schemas` field in the result MUST be `[10, 20]` (or the string-form equivalents)
- **AND** the result MUST NOT contain expanded schema objects

### Requirement: Serializer SHALL live in a dedicated namespace

The entity serializer introduced by this change MUST live under `lib/Service/Serializer/` (consistent with the existing subfolder convention in `lib/Service/`: `Archival/`, `Chat/`, `Configuration/`, `Edepot/`, `File/`). The namespace MUST be `OCA\OpenRegister\Service\Serializer`. This establishes a stable home for future entity serializers (`SchemaSerializer`, `ObjectSerializer`, etc.).

#### Scenario: RegisterSerializer is placed in the Serializer namespace

- **WHEN** the new serializer class is created
- **THEN** its filesystem path MUST be `lib/Service/Serializer/RegisterSerializer.php`
- **AND** its fully-qualified class name MUST be `OCA\OpenRegister\Service\Serializer\RegisterSerializer`

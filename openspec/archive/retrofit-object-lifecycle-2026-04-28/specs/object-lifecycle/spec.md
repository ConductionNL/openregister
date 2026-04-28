---
retrofit: true
---

# Object Lifecycle

## Purpose

Describes the internal pipeline that governs how OpenRegister objects are created, read, updated, and deleted. This capability covers the layered handler pattern used to decompose the save, validate, cache, metadata-hydration, and bulk processing concerns, and is the foundation on which all higher-level capabilities (schema hooks, RBAC, retention, audit trail) attach their side effects.

## Requirements

### REQ-001: The system MUST process object mutations through a layered save pipeline

Every create or update operation on an object MUST pass through `SaveObject` → `SaveObjects` → `CrudHandler` in a defined execution order, applying validation, relation cascade, metadata hydration, and computed field resolution before persisting. The pipeline MUST be deterministic: the same input object with the same schema MUST always produce the same persisted state and version increment.

#### Scenario: Single object create flows through full pipeline
- **GIVEN** a valid object payload for schema `meldingen` in register `gemeente`
- **WHEN** `CrudHandler::save()` is invoked
- **THEN** the pipeline MUST invoke validation, metadata hydration, computed field resolution, and relation cascade handlers in order before calling the mapper's insert/update
- **AND** the resulting entity MUST have a non-null `uuid`, `version`, and `created` timestamp

#### Scenario: Pipeline short-circuits on validation failure
- **GIVEN** an object payload that fails schema validation (missing required field)
- **WHEN** the save pipeline is invoked
- **THEN** the pipeline MUST return a validation error response before reaching the persistence step
- **AND** no database write MUST occur

### REQ-002: Object validation MUST enforce schema constraints before persistence

The `ValidateObject` and `ValidationHandler` MUST check all object field values against the schema's property definitions (type, format, required, enum, pattern) before the object is persisted. Validation errors MUST be collected and returned as a structured array, not as exceptions.

#### Scenario: Required field missing
- **GIVEN** schema `meldingen` has a required property `omschrijving`
- **WHEN** an object without `omschrijving` is validated
- **THEN** `ValidationHandler` MUST return `["omschrijving" => "Field is required"]`

#### Scenario: Bulk validation collects all errors
- **GIVEN** a bulk import payload of 50 objects, 5 of which have type mismatches
- **WHEN** `BulkValidationHandler` validates the batch
- **THEN** the 45 valid objects MUST proceed and the 5 invalid ones MUST be returned as failed with per-field error details
- **AND** the successful objects MUST NOT be blocked by the failures

### REQ-003: Object reads MUST be served from cache when available

`CacheHandler` MUST cache retrieved objects by their UUID key. A cache hit MUST bypass the database query entirely. Cache MUST be invalidated on any successful save or delete of the same UUID. The cache strategy MUST be transparent to callers of the object service layer.

#### Scenario: Cache hit bypasses database
- **GIVEN** object `abc-123` was previously fetched and cached
- **WHEN** a second request for `abc-123` arrives within the cache TTL
- **THEN** the response MUST be served from cache without a database query
- **AND** `PerformanceHandler` metrics MUST record a cache hit

#### Scenario: Save invalidates cache
- **GIVEN** object `abc-123` is in cache
- **WHEN** `CrudHandler` persists an update to `abc-123`
- **THEN** the cache entry for `abc-123` MUST be evicted before the updated object is returned

### REQ-004: Bulk object operations MUST use chunked processing

When handling batches of objects, `SaveObjects` and its sub-handlers (`PreparationHandler`, `ChunkProcessingHandler`) MUST split the batch into configurable chunks to limit memory consumption and enable partial-success reporting. Each chunk MUST be processed independently so that a failure in one chunk does not roll back already-persisted chunks.

#### Scenario: Large import is chunked
- **GIVEN** a bulk import of 5000 objects with chunk size 100
- **WHEN** `ChunkProcessingHandler` processes the import
- **THEN** objects MUST be processed in groups of 100
- **AND** the response MUST include a `processed`, `failed`, and `skipped` count per chunk
- **AND** a failure in chunk 30 MUST NOT roll back objects from chunks 1–29

### REQ-005: Object metadata MUST be hydrated before persistence

`MetadataHydrationHandler` MUST populate system-managed fields (uuid, created, updated, version, organisationId, application) on every object before it is inserted or updated. Computed fields MUST be evaluated after user-provided data is set, so computations can reference other field values.

#### Scenario: UUID assigned on first save
- **GIVEN** a new object is submitted without a uuid
- **WHEN** `MetadataHydrationHandler` processes it
- **THEN** a UUIv4 MUST be assigned to the `uuid` field
- **AND** `created` and `updated` MUST both be set to the current UTC timestamp

#### Scenario: Computed field references sibling field
- **GIVEN** schema `meldingen` has a computed field `volledigeNaam` that concatenates `voornaam` and `achternaam`
- **WHEN** an object with `voornaam: "Jan"` and `achternaam: "Janssen"` is saved
- **THEN** `ComputedFieldHandler` MUST set `volledigeNaam: "Jan Janssen"` after hydration

## Cross-References
- **rbac-scopes** — RBAC checks are applied by `PermissionHandler` at the start of every pipeline stage
- **schema-hooks** — schema hooks fire via event dispatcher after each successful save
- **audit-trail-immutable** — `AuditHandler` records every mutation as an immutable audit trail entry
- **linked-entity-types** — `RelationHandler` and `RelationCascadeHandler` resolve and cascade linked entity relations
- **faceting-configuration** — `FacetHandler` builds facet aggregations from queried object sets
- **zoeken-filteren** — `SearchQueryHandler` and `QueryHandler` translate search parameters into database queries

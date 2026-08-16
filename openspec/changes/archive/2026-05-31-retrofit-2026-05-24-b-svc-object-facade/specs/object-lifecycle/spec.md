---
retrofit: true
---

# Object Lifecycle

## Why

The `ObjectService` facade is the entry point REST controllers call for every object operation. While the underlying save/validate/cache handlers are already specified (REQ-001..005), the facade itself owns orchestration concerns that no requirement currently captures: how request-scoped register/schema/object context is resolved, how related-object names are hydrated onto query results, the ordering the facade enforces before delegating to the save pipeline, and the write-protection it applies for transferred and append-only objects. The schema/register cache layer (a two-tier memory + persistent cache) is likewise unspecified. This change retroactively documents these observed behaviors so the facade and cache contracts are anchored.

## ADDED Requirements

### Requirement: REQ-006 The facade MUST resolve register, schema, and object context with cached lookup
MUST resolve register, schema, and object context from an entity, numeric ID, UUID, or slug into request-scoped state, using cached-entity lookup for numeric IDs and bypassing access checks when deriving context from an already-accessible object.

`ObjectService::setRegister()`, `setSchema()`, and `setObject()` MUST accept an entity, a numeric ID, a UUID, or a slug and resolve it to the corresponding entity stored as request-scoped context. Numeric-ID lookups MUST go through the cached-entity path (`PerformanceHandler::getCachedEntities`) with a direct mapper `find()` fallback when the cache misses. When context is being derived from an already-accessible object, resolution MUST bypass RBAC and multi-tenancy checks (`_rbac: false`, `_multitenancy: false`), because access to the object already implies access to its register and schema. A `setSchema()` lookup that fails MUST propagate the `DoesNotExistException` unwrapped so the framework returns a 404 rather than a generic 500. `setObject()` MUST route through the magic-table mapper when register and schema context are already set.

#### Scenario: Numeric register ID resolved via cache
- **GIVEN** `setRegister(42)` is called with a numeric ID
- **WHEN** the register is resolved
- **THEN** resolution MUST use the cached-entity lookup with `_rbac: false` and `_multitenancy: false`
- **AND** the resolved `Register` entity MUST be stored as the current register context

#### Scenario: Slug resolution falls through to mapper
- **GIVEN** `setSchema("gemeente-meldingen")` is called with a slug string
- **WHEN** the schema is resolved
- **THEN** the mapper `find()` MUST be invoked (which supports id/uuid/slug) to load the schema

#### Scenario: Missing schema propagates 404
- **GIVEN** `setSchema()` is called with an identifier that does not exist
- **WHEN** the mapper throws `DoesNotExistException`
- **THEN** the facade MUST rethrow it unwrapped so the framework dispatcher returns a 404

### Requirement: REQ-007 The facade MUST hydrate related-object names onto query results
MUST collect every related-object UUID referenced by a result set (relations, owner/organisation metadata, object-data properties) without full serialization, then batch-resolve them to display names via the cache handler.

`ObjectService::collectNamesForResults()` MUST walk each result (whether an `ObjectEntity` or an already-serialized array), collect every UUID referenced by its relations, its `organisation` and `owner` metadata fields, and its object-data properties, de-duplicate them, and resolve them to display names via the cache handler. UUID collection MUST NOT trigger full object serialization or render operations. Only values matching the UUID format MUST be treated as references. When no UUIDs are found the result MUST be an empty array.

#### Scenario: Names collected from entity relations and metadata
- **GIVEN** a result set of `ObjectEntity` instances with relations and `owner`/`organisation` UUID references
- **WHEN** `collectNamesForResults()` runs
- **THEN** all referenced UUIDs MUST be collected without full serialization
- **AND** the collected UUIDs MUST be resolved to names via the cache handler in a single batch lookup

#### Scenario: Non-UUID values ignored
- **GIVEN** an object field holds the string `"Jan Janssen"` and another holds a valid UUID
- **WHEN** UUIDs are collected
- **THEN** only the UUID-formatted value MUST be added to the lookup set

### Requirement: REQ-008 The facade MUST enforce save orchestration ordering before delegating to the pipeline
MUST perform facade-level save orchestration in a fixed order — context, normalization, permissions, write-protection, cascade, always-defaults, date-normalization, validation — before delegating to the SaveObject pipeline, with defaults and date coercion applied before validation.

`ObjectService::saveObject()` MUST perform facade-level orchestration in a fixed order before delegating to the `SaveObject` handler: (1) set register/schema context from parameters, (2) extract the UUID and normalize the payload to an array, (3) check create/update permissions, (4) reject transferred and append-only updates, (5) handle cascading relations while preserving context, (6) apply "always" schema defaults, (7) normalize date values, (8) validate when hard validation is enabled. Steps 6 and 7 MUST run before validation so computed/derived defaults and date coercion can satisfy schema constraints. After the `SaveObject` handler persists, the facade MUST render the saved entity before returning it. When a UUID is auto-generated by the cascade step (rather than user-provided), the facade MUST mark the payload as a CREATE operation in `@self`.

#### Scenario: Always-defaults and date normalization precede validation
- **GIVEN** an object whose computed `dienstType` is derived from `type` and whose date field carries a datetime value
- **WHEN** `saveObject()` runs
- **THEN** "always" defaults MUST be applied and date values normalized before validation executes
- **AND** validation MUST see the corrected values

#### Scenario: Auto-generated UUID marked as create
- **GIVEN** a payload submitted without a UUID
- **WHEN** the cascade step assigns a UUID
- **THEN** the facade MUST set `@self._autoGeneratedUuid` to true so the save handler treats it as a CREATE

### Requirement: REQ-009 The facade MUST block writes to transferred and append-only objects
MUST reject updates to objects whose retention archiefstatus is `overgebracht` (transferred to the e-Depot) and reject updates to append-only schemas, while still allowing inserts on append-only schemas.

Before persisting an update, `ObjectService` MUST reject the operation when the target object is in a protected state. `rejectIfTransferred()` MUST load the object (including deleted) and throw a `DoesNotExistException` carrying the `OBJECT_TRANSFERRED:` prefix when its retention `archiefstatus` equals `overgebracht`. An update to an object whose schema is append-only MUST throw an `AppendOnlyException`; inserts on append-only schemas MUST still be allowed. A not-found lookup during the transferred check MUST be treated as a new object and MUST NOT block the save.

#### Scenario: Transferred object is read-only
- **GIVEN** an object whose retention `archiefstatus` is `overgebracht`
- **WHEN** an update is attempted
- **THEN** the facade MUST throw a `DoesNotExistException` with the `OBJECT_TRANSFERRED:` message prefix

#### Scenario: Append-only schema rejects update but allows insert
- **GIVEN** a schema marked append-only
- **WHEN** an update (UUID present) is attempted
- **THEN** the facade MUST throw `AppendOnlyException`
- **AND** a create (no UUID) on the same schema MUST be allowed to proceed

### Requirement: REQ-010 Schema reads MUST use a two-tier cache with explicit invalidation
MUST serve schemas from a two-tier (in-memory then persistent) cache with warm-on-miss, and MUST drop both tiers plus the mapper find-cache on the canonical invalidation entry points called by the runtime-schema-api CRUD controllers.

`SchemaCacheHandler` MUST serve schemas from a two-tier cache: a static in-memory cache checked first, then a persistent cache table, falling back to a mapper load that warms both tiers on miss. `RegisterCacheHandler` and `SchemaCacheHandler::invalidate()` MUST provide canonical invalidation entry points called by the runtime-schema-api CRUD controllers after a successful mapper round-trip; after invalidation the next read in the same PHP worker MUST observe a fresh database load, with both the in-memory tier and the mapper's request-scoped find-cache dropped. Cache failures MUST be logged and MUST NOT abort the surrounding operation.

#### Scenario: Schema warm-on-miss populates both tiers
- **GIVEN** schema 7 is in neither the memory nor the persistent cache
- **WHEN** `getSchema(7)` is called
- **THEN** the schema MUST be loaded from the mapper and written to both the persistent cache and the in-memory cache
- **AND** a subsequent `getSchema(7)` in the same worker MUST be served from the memory tier

#### Scenario: Invalidation forces a fresh read
- **GIVEN** schema 7 is cached and the runtime-schema-api updates it
- **WHEN** `SchemaCacheHandler::invalidate(7)` is called
- **THEN** the persistent cache row, the in-memory entry, and the mapper find-cache for schema 7 MUST all be dropped
- **AND** the next read MUST load fresh state from the database

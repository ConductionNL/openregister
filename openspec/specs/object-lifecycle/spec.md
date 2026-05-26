---
retrofit: true
---

# Object Lifecycle

## Purpose

@e2e exclude internal object pipeline backend — covered by PHPUnit

Describes the internal pipeline that governs how OpenRegister objects are created, read, updated, and deleted. This capability covers the layered handler pattern used to decompose the save, validate, cache, metadata-hydration, and bulk processing concerns, and is the foundation on which all higher-level capabilities (schema hooks, RBAC, retention, audit trail) attach their side effects.
## Requirements

### REQ-001: The system MUST process object mutations through a layered save pipeline

Every create or update operation on an object MUST be processed through a layered handler pipeline before persisting. For a single object the entry point is `SaveObject::saveObject()`, which invokes validation, metadata hydration, computed field resolution, and relation cascade handlers in sequence before calling the mapper's insert/update. For bulk operations the entry point is `SaveObjects::saveObjects()`, which delegates to `SaveObject::saveObject()` per item after preparation and chunking. The pipeline MUST be deterministic: the same input object with the same schema MUST always produce the same persisted state and version increment.

#### Scenario: Single object create flows through full pipeline
- **GIVEN** a valid object payload for schema `meldingen` in register `gemeente`
- **WHEN** `SaveObject::saveObject()` is invoked
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
- **THEN** a UUIDv4 MUST be assigned to the `uuid` field
- **AND** `created` and `updated` MUST both be set to the current UTC timestamp

#### Scenario: Computed field references sibling field
- **GIVEN** schema `meldingen` has a computed field `volledigeNaam` that concatenates `voornaam` and `achternaam`
- **WHEN** an object with `voornaam: "Jan"` and `achternaam: "Janssen"` is saved
- **THEN** `ComputedFieldHandler` MUST set `volledigeNaam: "Jan Janssen"` after hydration

### Requirement: Declared initial lifecycle state applied on create

`LifecycleInitialStateListener::handle()` MUST, on `ObjectCreatingEvent`, force-set the schema's declared initial lifecycle value when the caller did not supply one. The listener reads the `x-openregister-lifecycle` annotation from the object's schema, takes the annotation's `field` and `initial` keys, and writes `initial` into the object payload under `field` ONLY when that field is currently absent, null, or an empty string. A caller-supplied non-empty value MUST be left untouched (its validity is the validator's / update-guard's concern). The listener MUST be a no-op when the event is not an `ObjectCreatingEvent`, when the schema cannot be resolved, when the schema declares no lifecycle annotation, or when the annotation's `field`/`initial` are empty.

Apps therefore never need to know the starting state — lifecycle is a declarative property of the schema.

#### Scenario: Initial state applied when caller omits it
- **GIVEN** a schema declaring `x-openregister-lifecycle` with `field: "status"` and `initial: "draft"`
- **AND** an object being created whose `status` field is absent
- **WHEN** `ObjectCreatingEvent` fires and `LifecycleInitialStateListener::handle()` runs
- **THEN** the object payload MUST have `status` set to `"draft"` before persistence

#### Scenario: Caller-supplied value is preserved
- **GIVEN** the same schema and an object being created with `status: "open"`
- **WHEN** the listener runs
- **THEN** the `status` value MUST remain `"open"` (the listener MUST NOT overwrite it)

#### Scenario: Empty string is treated as missing
- **GIVEN** the same schema and an object being created with `status: ""`
- **WHEN** the listener runs
- **THEN** `status` MUST be set to the declared `initial` value `"draft"`

#### Scenario: No-op without a lifecycle annotation
- **GIVEN** a schema with no `x-openregister-lifecycle` annotation
- **WHEN** the listener runs on an object of that schema
- **THEN** the payload MUST be left unchanged

#### Notes
- `loadSchema()` resolves the object's schema via `SchemaMapper::find($ref, _multitenancy: false)` — a system-level lookup because the listener is not user-scoped. An unresolvable or empty schema reference yields a null schema and the listener returns early after logging a warning. See the change Notes for the multitenancy-boundary follow-up.
- This is the create-time complement to REQ-006's annotation validator; it relies on the annotation already being shape-valid.

### Requirement: Direct lifecycle-field edits guarded on update

`LifecycleValidationListener::handle()` MUST, on `ObjectUpdatingEvent`, reject lifecycle-field edits made through the ordinary save path (`ObjectService::saveObject()`) that no declared transition allows. This is the complement to REQ-007's named-action `TransitionEngine`: it guards the case where a caller edits the lifecycle field value directly rather than invoking a named action. When the old and new value of the annotation's `field` differ, the listener MUST:

1. require the new value to be a non-empty string (else reject with code `lifecycle-invalid-value`);
2. find a declared transition whose `to` equals the new value AND whose `from` array contains the old value (else reject with code `lifecycle-invalid-transition`);
3. when the matched transition declares a non-empty `requires` tag, resolve the guard via `LifecycleGuardRegistry` and run `check()` with the new data, the action name, and the caller's uid — rejecting with code `lifecycle-guard-denied` when the verdict is not allowed.

Each rejection MUST stamp a structured error onto the event and stop propagation, so the controller surfaces it (HTTP 422 for invalid value/transition, 403 for guard denial). The listener MUST be a no-op when the event is not an `ObjectUpdatingEvent`, when there is no prior object state (initial state is REQ-010's concern), when the schema or its lifecycle annotation is absent, or when the lifecycle field value is unchanged.

#### Scenario: Allowed transition passes
- **GIVEN** a schema declaring a transition `open` with `from: ["draft"], to: "open"`
- **AND** an object whose `status` changes from `"draft"` to `"open"`
- **WHEN** `ObjectUpdatingEvent` fires and the listener runs
- **THEN** propagation MUST continue and no error MUST be stamped on the event

#### Scenario: Disallowed transition is rejected
- **GIVEN** the same schema and an object whose `status` changes from `"closed"` to `"open"` (no transition allows that pair)
- **WHEN** the listener runs
- **THEN** the event MUST carry a structured error with code `lifecycle-invalid-transition` naming the from/attempted values
- **AND** propagation MUST be stopped

#### Scenario: Non-string lifecycle value is rejected
- **GIVEN** an object whose lifecycle field is changed to a null or empty value
- **WHEN** the listener runs
- **THEN** the event MUST carry an error with code `lifecycle-invalid-value`
- **AND** propagation MUST be stopped

#### Scenario: Guard denial maps to 403
- **GIVEN** a matched transition declaring `requires: "decidesk.meeting.openGuard"` whose guard returns a deny verdict
- **WHEN** the listener runs
- **THEN** the event MUST carry an error with code `lifecycle-guard-denied` and the guard's message
- **AND** propagation MUST be stopped

#### Scenario: Unchanged lifecycle value is a no-op
- **GIVEN** an object update where the lifecycle field value is identical between old and new
- **WHEN** the listener runs
- **THEN** no validation MUST be performed and propagation MUST continue

#### Notes
- Trust contract: this listener only fires on `ObjectUpdatingEvent`, dispatched by `ObjectService::saveObject()`. Code paths that mutate an object outside `saveObject()` (direct `MagicMapper::update`, raw SQL, import bypass) skip the listener and can persist an invalid lifecycle value. Callers MUST go through `saveObject()` for the guarantee to hold; a DB-level CHECK constraint is a future hardening step.
- `loadSchema()` uses `_multitenancy: false` (system-level lookup). The guard receives the loaded object payload, the action name, and the caller's uid via `IUserSession`.

### Requirement: Objects MUST expose an expiry-aware lock-state contract
MUST report whether an object is currently locked, treating an expired lock as unlocked, and MUST expose the lock's metadata (who locked it, when, optional process identifier, and expiry) when a live lock is present.

`LockHandler::isLocked()` MUST resolve the object by id or UUID, read its `locked` metadata, and return `false` when no lock metadata is present. When lock metadata carries an `expiresAt` timestamp that is in the past, `isLocked()` MUST treat the lock as expired and return `false`. `getLockInfo()` MUST return `null` when the object is not locked and otherwise MUST return a normalized array exposing `locked_at`, `locked_by`, `process`, and `expires_at`. Both reads MUST be defensive: a lookup failure MUST be logged and degrade to "not locked" (`false` / `null`) rather than propagating an exception, so a read-side lock probe never breaks the calling flow.

#### Scenario: Active lock is reported as locked
- **GIVEN** an object whose `locked` metadata has no `expiresAt` or an `expiresAt` in the future
- **WHEN** `isLocked()` is called with its identifier
- **THEN** it MUST return `true`
- **AND** `getLockInfo()` MUST return an array with `locked_by`, `locked_at`, `process`, and `expires_at` keys sourced from the metadata

#### Scenario: Expired lock is reported as unlocked
- **GIVEN** an object whose `locked.expiresAt` is in the past
- **WHEN** `isLocked()` is called
- **THEN** it MUST return `false`

#### Scenario: Lookup failure degrades to not-locked
- **GIVEN** the object cannot be resolved (lookup throws)
- **WHEN** `isLocked()` or `getLockInfo()` is called
- **THEN** the failure MUST be logged
- **AND** `isLocked()` MUST return `false` and `getLockInfo()` MUST return `null` rather than throwing

### Requirement: The system MUST support merging a duplicate object into a target within the same register and schema
MUST merge a source object into a target object — applying property overrides, transferring or deleting the source's files, transferring or dropping its relations, updating inbound references from other objects, and soft-deleting the source — while rejecting merges across mismatched register or schema, and MUST return a structured report of the actions taken.

`MergeHandler::mergeObjects()` MUST require a non-empty target identifier and MUST throw an `InvalidArgumentException` when it is missing. It MUST resolve both source and target across all magic-table sources, throwing a not-found exception when either cannot be located. It MUST reject the merge with an `InvalidArgumentException` when the source and target belong to a different register or a different schema. Merge behavior MUST be configurable per call: `fileAction` of `transfer` (default) or `delete`, `relationAction` of `transfer` (default) or `drop`, and a reference action governing how inbound references to the source are rewritten to the target. After applying property overrides and the configured file/relation/reference handling, the source object MUST be soft-deleted. The method MUST return a merge report containing the original source and target, the merged result, the per-category actions taken, aggregate statistics (properties changed, files transferred/deleted, relations transferred/dropped, references updated), and any warnings or errors — rather than throwing on partial, recoverable problems.

#### Scenario: Merge requires a target
- **GIVEN** a merge request with no `target`
- **WHEN** `mergeObjects()` is called
- **THEN** it MUST throw an `InvalidArgumentException` before resolving any object

#### Scenario: Cross-register or cross-schema merge is rejected
- **GIVEN** a source and target object that belong to different registers (or different schemas)
- **WHEN** `mergeObjects()` is called
- **THEN** it MUST throw an `InvalidArgumentException` and MUST NOT soft-delete the source

#### Scenario: Successful merge transfers and soft-deletes the source
- **GIVEN** a source and target in the same register and schema, with `fileAction: transfer` and `relationAction: transfer`
- **WHEN** `mergeObjects()` is called with property overrides
- **THEN** the target MUST receive the property overrides, the source's files and relations MUST be transferred, inbound references MUST be rewritten to the target, and the source MUST be soft-deleted
- **AND** the returned report MUST include the merged object and statistics for the actions taken

## Cross-References
- **rbac-scopes** — RBAC checks are applied by `PermissionHandler` at the start of every pipeline stage
- **schema-hooks** — schema hooks fire via event dispatcher after each successful save
- **audit-trail-immutable** — `AuditHandler` records every mutation as an immutable audit trail entry
- **linked-entity-types** — `RelationHandler` and `RelationCascadeHandler` resolve and cascade linked entity relations
- **faceting-configuration** — `FacetHandler` builds facet aggregations from queried object sets
- **zoeken-filteren** — `SearchQueryHandler` and `QueryHandler` translate search parameters into database queries

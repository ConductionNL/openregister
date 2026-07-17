---
status: done
retrofit: true
---

# Object Lifecycle

**Status**: in-progress

**OpenSpec changes**:
- `tighten-relation-detection-heuristic` (active) — relation detection records a string in `@self.relations` only when it is a UUID/prefixed-UUID/URL or a schema-declared reference property; removes the loose "8+ chars with hyphen/underscore" heuristic that polluted the map with dates, enum values, and business identifiers. Correctness fix to a derived field; no schema/lifecycle/aggregation/notification change.
- `fk-graph-lifecycle-transitions` — adds declarative FK-scoped graph transition mode (in-progress)

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

When handling batches of objects, `SaveObjects` (assisted by its sub-handler `PreparationHandler`) MUST split the batch into configurable chunks to limit memory consumption and enable partial-success reporting. Chunk processing is implemented by `SaveObjects`' internal `processObjectsChunk()` — the former standalone `ChunkProcessingHandler` was an uncalled duplicate and has been removed. Each chunk MUST be processed independently so that a failure in one chunk does not roll back already-persisted chunks.

#### Scenario: Large import is chunked
- **GIVEN** a bulk import of 5000 objects with chunk size 100
- **WHEN** `SaveObjects` processes the import
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

### Requirement: The object collection endpoint MUST serve a paginated, source-routed list

`ObjectsController::objects()` MUST resolve the target register/schema from
either path-style (`register`/`schema`) or underscore-prefixed
(`_register`/`_schema`) query parameters, and route the read to the optimal
source: a cross-table search when more than one register or schema is supplied,
the `MagicMapper` table when magic mapping is enabled for the resolved
register+schema, or `ObjectService::searchObjectsPaginated()` otherwise. Unless
`_empty=true` is supplied, empty values MUST be stripped from each result row.

#### Scenario: Single register+schema served via paginated search
- **GIVEN** a request to the objects endpoint with `register` and `schema` query parameters and no magic mapping configured
- **WHEN** `ObjectsController::objects()` processes the request
- **THEN** the response MUST be the `searchObjectsPaginated()` envelope (`results`, `total`, `pages`, `page`, `limit`)
- **AND** empty values in each result row MUST be stripped unless `_empty=true` was supplied

#### Scenario: Multiple schemas trigger cross-table search
- **GIVEN** a request supplying a `schemas` parameter with more than one schema
- **WHEN** `objects()` parses the multi-value parameter
- **THEN** the request MUST be delegated to `crossTableSearch()`

#### Scenario: Unresolvable register or schema returns 404
- **GIVEN** a request whose `register` or `schema` cannot be resolved
- **WHEN** `objects()` calls `resolveRegisterSchemaIds()`
- **THEN** the response MUST be HTTP 404 with a `message` body

### Requirement: The object read endpoint MUST resolve slugs and return a 404 envelope on miss

`ObjectsController::show()` MUST accept a register and schema as slug or numeric
ID, resolve them to entities via `resolveRegisterSchemaIds()`, and return the
single object honoring the request's extend and field-filter parameters. If the
register or schema cannot be resolved, the response MUST be HTTP 404 with a
`message` body.

#### Scenario: Show resolves slugs and returns the object
- **GIVEN** an existing object addressed by `{register}/{schema}/{id}` using slugs
- **WHEN** `show()` is called
- **THEN** the register and schema slugs MUST be resolved to entities before the read
- **AND** the response MUST be the rendered object honoring any `_extend` / field-filter parameters

#### Scenario: Show on unknown register/schema returns 404
- **GIVEN** a request whose register or schema does not exist
- **WHEN** `resolveRegisterSchemaIds()` throws `RegisterNotFoundException` or `SchemaNotFoundException`
- **THEN** the response MUST be HTTP 404 with a `message` body

### Requirement: The object patch endpoint MUST merge with stored data and map domain errors to status codes

`ObjectsController::patch()` MUST filter out reserved/underscore-prefixed and
`@`-prefixed keys (except `@self`) and `uuid`/`register`/`schema` from the
payload, normalize multipart form-data values, read the existing object via
`findSilent` (RBAC and multitenancy disabled for the internal read), and merge
the patch over the stored object data before saving. RBAC and multitenancy on
the save MUST be enabled only for non-admin callers. The object MUST be unlocked
after a successful save. Domain errors MUST map to: append-only → HTTP 405,
validation → HTTP 422, missing object → HTTP 404, other → HTTP 500.

#### Scenario: Patch merges over existing data
- **GIVEN** an existing object and a patch payload containing a subset of fields
- **WHEN** `patch()` processes the request
- **THEN** the patch MUST be merged over the stored object data (`array_merge(existing, patch)`) before `saveObject()`
- **AND** reserved keys (underscore- and `@`-prefixed except `@self`, plus `uuid`/`register`/`schema`) MUST be filtered out of the payload

#### Scenario: Append-only schema rejects patch with 405
- **GIVEN** the target schema is append-only
- **WHEN** the save raises `AppendOnlyException`
- **THEN** the response MUST be HTTP 405 with the exception's response body

#### Scenario: Validation failure returns 422
- **GIVEN** a patch that fails schema validation
- **WHEN** `saveObject()` raises `ValidationException` or `CustomValidationException`
- **THEN** the response MUST be the validation-exception envelope (HTTP 422)

#### Scenario: Missing object returns 404
- **GIVEN** a patch addressed to a non-existent object id
- **WHEN** `findSilent` cannot locate the object
- **THEN** the response MUST be HTTP 404 with `error: "Object not found"`

### Requirement: The object lock and unlock endpoints MUST manage optimistic locks with a status flag

`ObjectsController::lock()` MUST accept optional `process` and `duration`
parameters, delegate to `ObjectService::lockObject()`, and return the lock
result merged with `locked: true`. A non-existent object MUST return HTTP 404
and other failures HTTP 500. `ObjectsController::unlock()` MUST delegate to
`ObjectService::unlockObject()` and return `{message, locked: false, uuid}`.

#### Scenario: Lock returns the locked status
- **GIVEN** an existing object and an optional `duration`
- **WHEN** `lock()` is called
- **THEN** the response MUST be the lock result merged with `locked: true`

#### Scenario: Lock on missing object returns 404
- **GIVEN** a lock request for a non-existent object
- **WHEN** `lockObject()` raises `DoesNotExistException`
- **THEN** the response MUST be HTTP 404 with `error: "Object not found"`

#### Scenario: Unlock clears the lock
- **GIVEN** a locked object
- **WHEN** `unlock()` is called
- **THEN** the response MUST be `{message: "Object unlocked successfully", locked: false, uuid}`

### Requirement: The object merge endpoint MUST validate the merge payload and map errors to status codes

`ObjectsController::merge()` MUST require both a `target` object id and a
non-empty `object` payload in the request body, returning HTTP 400 if either is
missing, and delegate to `ObjectService::mergeObjects()`. A missing object MUST
return HTTP 404, an invalid argument HTTP 400, and any other failure HTTP 500.
The execution time limit MUST be disabled (`set_time_limit(0)`) because merging
objects with many references can be long-running.

#### Scenario: Merge requires target and object payload
- **GIVEN** a merge request missing the `target` id or with an empty `object` payload
- **WHEN** `merge()` validates the request
- **THEN** the response MUST be HTTP 400 with a descriptive `error`

#### Scenario: Merge of a non-existent source returns 404
- **GIVEN** a merge whose source object id does not exist
- **WHEN** `mergeObjects()` raises `DoesNotExistException`
- **THEN** the response MUST be HTTP 404 with `error: "Object not found"`

### Requirement: The relation sub-resource endpoints MUST return paginated forward and inverse references

`ObjectsController::contracts()`, `uses()`, and `used()` MUST set the
register/schema context on the object service and return relation traversals for
the addressed object. `uses()` MUST return objects this object references
(A→B); `used()` MUST return objects that reference this object (B→A);
`contracts()` MUST return the object's contracts as a paginated envelope. RBAC
and multitenancy MUST be enforced on `uses()` and `used()`.

#### Scenario: uses returns forward references
- **GIVEN** object A that references objects B and C
- **WHEN** `uses()` is called for A
- **THEN** the response MUST contain B and C (the objects A uses)
- **AND** RBAC and multitenancy MUST be enforced

#### Scenario: used returns inverse references
- **GIVEN** objects B and C that reference object A
- **WHEN** `used()` is called for A
- **THEN** the response MUST contain B and C (the objects that use A)

#### Scenario: contracts returns a paginated envelope
- **GIVEN** an object with contracts and `limit`/`offset` query parameters
- **WHEN** `contracts()` is called
- **THEN** the response MUST be a paginated envelope (`results`, `total`, `limit`, `offset`, `page`)

### Requirement: The object audit-log sub-resource MUST enforce register/schema ownership before returning logs

`ObjectsController::logs()` MUST fetch the object by id, return HTTP 404 if it is
not found, and verify that the object's register AND schema match the addressed
`{register}/{schema}` (by id or slug). On a mismatch the response MUST be HTTP
404 with `message: "Object does not belong to specified register/schema"`. On a
match the audit logs MUST be returned as a paginated envelope.

#### Scenario: Logs returned for a matching object
- **GIVEN** an object whose register and schema match the addressed path
- **WHEN** `logs()` is called
- **THEN** the response MUST be a paginated envelope of the object's audit logs

#### Scenario: Mismatched register/schema returns 404
- **GIVEN** an existing object addressed under the wrong register or schema
- **WHEN** `logs()` compares the object's register/schema to the path
- **THEN** the response MUST be HTTP 404 with `message: "Object does not belong to specified register/schema"`

#### Scenario: Unknown object id returns 404
- **GIVEN** a logs request for a non-existent object id
- **WHEN** the object cannot be found
- **THEN** the response MUST be HTTP 404 with `message: "Object not found"`

### Requirement: The bulk-validation trigger and retired blob endpoint MUST expose stable contracts

`ObjectsController::validate()` MUST require `register` and `schema` parameters
(HTTP 400 if absent), accept optional `limit`/`offset` for chunked processing,
delegate to `ObjectService::validateAndSaveObjectsBySchema()`, and return a
`{success, message, statistics, pagination, errors}` envelope. Failures MUST
return HTTP 500 with `success: false`. `ObjectsController::clearBlob()` is a
retired endpoint that MUST return a static success envelope reporting zero
deletions and that blob storage has been retired in favor of magic tables.

#### Scenario: Bulk validation requires register and schema
- **GIVEN** a validate request missing `register` or `schema`
- **WHEN** `validate()` checks the parameters
- **THEN** the response MUST be HTTP 400 with `success: false`

#### Scenario: Bulk validation returns a statistics envelope
- **GIVEN** a valid `register`/`schema` with optional `limit`/`offset`
- **WHEN** `validate()` completes
- **THEN** the response MUST include `success: true`, a `statistics` object (`processed`, `updated`, `failed`, `total`), `pagination`, and an `errors` array

#### Scenario: clearBlob returns the retired-endpoint envelope
- **GIVEN** any call to the blob-clear endpoint
- **WHEN** `clearBlob()` runs
- **THEN** the response MUST be `{success: true, deleted: 0, message: "Blob storage has been retired. All objects now use magic tables."}`

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

### Requirement: REQ-006 — Schema lifecycle annotations MUST be shape-validated at schema-save time

`LifecycleAnnotationValidator::validate()` MUST check the `x-openregister-lifecycle`
annotation on a schema and return a structured list of error entries (each with
a `code` and `message`). An empty list MUST be returned when the annotation is
absent or fully valid. Validation MUST NOT throw on malformed input; errors are
collected and returned, mapped to HTTP 422 by the caller. The validator MUST
enforce:

- the required top-level keys `field`, `initial`, and `transitions` are present;
- the `field` name resolves to a declared property of type `string` with a
  non-empty `enum`;
- the `initial` value and every declared `final` value is a member of the
  enum;
- the `transitions` map is non-empty;
- each transition object declares a non-empty `from` array whose every member
  is in the enum, and a non-empty `to` string that is in the enum;
- when a transition declares `requires`, the value is a non-empty string
  (DI-tag shape only — the validator does NOT attempt to resolve the tag).

#### Scenario: Annotation absent — no errors
- **GIVEN** a schema definition without an `x-openregister-lifecycle` key
- **WHEN** `LifecycleAnnotationValidator::validate()` is invoked
- **THEN** the method MUST return an empty array

#### Scenario: Missing required top-level key
- **GIVEN** an annotation `{"field": "status", "initial": "draft"}` (no `transitions`)
- **WHEN** the annotation is validated
- **THEN** the result MUST contain an entry with code `lifecycle-missing-key`
  and a message naming `transitions` as the missing key

#### Scenario: Initial state not in field enum
- **GIVEN** a schema with `properties.status.enum = ["draft", "open"]` and an
  annotation whose `initial` is `"closed"`
- **WHEN** the annotation is validated
- **THEN** the result MUST contain an entry with code
  `lifecycle-initial-not-in-enum` referencing the offending value

#### Scenario: Transition `to` not in enum
- **GIVEN** a schema with `properties.status.enum = ["draft", "open"]` and a
  transition `{"open": {"from": ["draft"], "to": "closed"}}`
- **WHEN** the annotation is validated
- **THEN** the result MUST contain an entry with code
  `lifecycle-to-not-in-enum`

#### Scenario: `requires` shape check only
- **GIVEN** a transition declaring `"requires": "decidesk.meeting.openGuard"`
- **WHEN** the annotation is validated
- **THEN** the result MUST NOT contain a tag-resolution error — the validator
  does not attempt DI resolution at schema-save time

### Requirement: REQ-007 — Named transitions MUST be applied through the central engine

`TransitionEngine::transition($objectId, $action)` MUST be the entry point for
state-machine transitions and MUST, in order:

1. Load the object via `ObjectService::find()`; throw `RuntimeException` if not found.
2. Resolve the object's schema; throw `RuntimeException` if unresolvable.
3. Gate on per-object RBAC via `PermissionHandler::hasPermission(action: 'update')`;
   throw `NotAuthorizedException` on denial.
4. Read the schema's `x-openregister-lifecycle` annotation; throw
   `RuntimeException` if the schema does not declare lifecycle.
5. Look up the requested action in `transitions`; throw `RuntimeException` if
   the action is not declared.
6. Reject the transition if the object's current lifecycle field value is not
   in the action's `from` array.
7. Mutate the lifecycle field to the action's `to` value and persist through
   `ObjectService::saveObject()` (so all standard validation/eventing/audit
   machinery runs unchanged).
8. Dispatch a typed `ObjectTransitionedEvent` carrying object, action, from,
   to, userId, register, and schema.

The engine MUST NOT bypass the standard save pipeline; transitions inherit
validation, audit, and event behaviour from REQ-001..005.

#### Scenario: Successful transition
- **GIVEN** an object in state `"draft"` and a transition `open` with
  `from: ["draft"], to: "open"`
- **AND** the caller has `update` permission on the object
- **WHEN** `TransitionEngine::transition($objectId, "open")` is invoked
- **THEN** the saved object MUST have lifecycle field `"open"`
- **AND** an `ObjectTransitionedEvent(from: "draft", to: "open", action: "open")`
  MUST be dispatched

#### Scenario: Transition rejected when current state not in `from`
- **GIVEN** an object in state `"closed"` and a transition `open` declaring
  `from: ["draft"]`
- **WHEN** `TransitionEngine::transition($objectId, "open")` is invoked
- **THEN** a `RuntimeException` MUST be thrown with a message naming the
  current state and the action
- **AND** no save MUST occur and no `ObjectTransitionedEvent` MUST be dispatched

#### Scenario: Transition denied by RBAC
- **GIVEN** a caller without `update` permission on the target object
- **WHEN** `TransitionEngine::transition()` is invoked
- **THEN** a `NotAuthorizedException` MUST be thrown before the annotation is
  read or the object is saved

#### Scenario: Schema does not declare lifecycle
- **GIVEN** an object whose schema has no `x-openregister-lifecycle` annotation
- **WHEN** `TransitionEngine::transition()` is invoked
- **THEN** a `RuntimeException` MUST be thrown naming the schema slug

### Requirement: REQ-008 — Guard DI tags MUST resolve through the registry with NC server fallback

`LifecycleGuardRegistry::resolve($tag)` MUST resolve a transition's `requires`
DI tag to a `LifecycleGuardInterface` instance. Resolution MUST:

- try the OpenRegister app container first (covers OR-internal guards);
- fall back to the injected `IServerContainer` (covers FQCN-referenced guards
  in cooperating apps that Nextcloud can autowire);
- fail closed: when neither container resolves the tag, log the collected
  resolution errors at error level and throw `RuntimeException` whose message
  names the tag;
- type-check the resolved service: if it does not implement
  `LifecycleGuardInterface`, throw `RuntimeException` naming the offending
  service and the required interface;
- cache successful resolutions per request so repeat transitions on the same
  tag within one request reuse the resolved instance.

The registry MUST NOT reach `\OC::$server` directly; the server container is
injected via constructor (`IServerContainer`) to keep `lib/` free of static
server accessors.

#### Scenario: Tag resolves from OR app container
- **GIVEN** a guard service `my.guard` registered in the OR app container
- **WHEN** `LifecycleGuardRegistry::resolve("my.guard")` is invoked
- **THEN** the registered `LifecycleGuardInterface` instance MUST be returned
- **AND** a second invocation with the same tag MUST return the cached instance

#### Scenario: Tag falls back to server container
- **GIVEN** a guard FQCN `Acme\\Guard\\OpenGuard` autowirable by Nextcloud
  but not registered in the OR app container
- **WHEN** `LifecycleGuardRegistry::resolve("Acme\\\\Guard\\\\OpenGuard")` is invoked
- **THEN** the server container MUST be consulted and its instance MUST be
  returned

#### Scenario: Unresolvable tag fails closed
- **GIVEN** a tag that neither container can resolve
- **WHEN** `resolve()` is invoked
- **THEN** a `RuntimeException` MUST be thrown whose message names the tag
- **AND** the logger MUST receive an error-level entry containing the
  resolution errors from each container

#### Scenario: Resolved service does not implement the interface
- **GIVEN** a service registered under a tag that does NOT implement
  `LifecycleGuardInterface`
- **WHEN** `resolve()` is invoked with that tag
- **THEN** a `RuntimeException` MUST be thrown naming the service and the
  required interface

### Requirement: REQ-009 — Guard verdicts MUST use the immutable GuardResult contract

Guards (implementations of `LifecycleGuardInterface::check()`) MUST return a
`GuardResult` value object constructed via the static factories
`GuardResult::allow()` or `GuardResult::deny(string $message)`. The
constructor MUST be private; callers MUST NOT instantiate `GuardResult`
directly. The verdict MUST be inspectable via `isAllowed(): bool`, and a deny
verdict MUST carry a human-readable message that is surfaced to the caller in
the 403 response. Guards MUST be read-only: implementations MUST NOT mutate
the inbound `$object` payload; side effects (notifications, cascades,
derived-field maintenance) belong on `ObjectTransitionedEvent` listeners.

#### Scenario: Allow factory
- **WHEN** `GuardResult::allow()` is called
- **THEN** the returned instance MUST report `isAllowed() === true`

#### Scenario: Deny factory carries message
- **WHEN** `GuardResult::deny("Meeting is not in draft state")` is called
- **THEN** the returned instance MUST report `isAllowed() === false`
- **AND** the deny message MUST be retrievable for surfacing in the response

#### Scenario: Guard contract receives loaded object, action, and userId
- **GIVEN** a guard implementing `LifecycleGuardInterface::check()`
- **WHEN** invoked from a transition flow
- **THEN** the guard MUST receive the loaded object payload, the action name,
  and the caller's uid as parameters
- **AND** the guard MUST return a `GuardResult` without having mutated the
  inbound object array

### Requirement: The delete pipeline MUST honour register/schema scope when both are supplied

When a caller invokes `ObjectService::deleteObject(string $uuid, Register|string|int|null $register, Schema|string|int|null $schema, ...)` with both `$register` and `$schema` non-null, the delete pipeline MUST resolve the UUID using the scoped path that targets exactly one magic table (`MagicMapper::find($identifier, $register, $schema, ...)`). The pipeline MUST NOT fall back to `findAcrossAllSources()` / `findAcrossAllMagicTables()` when the caller has expressed a scope. A UUID that exists in a different `(register,schema)` scope MUST raise `DoesNotExistException`, and the pipeline MUST NOT mutate any row in any magic table.

When the caller omits one or both of `$register` / `$schema`, the legacy unscoped lookup (`findAcrossAllSources`) MUST remain in force so existing call sites continue to work; the unscoped form is soft-deprecated in the docblock.

`ObjectServiceMapperAdapter::delete(array $criteria)` MUST forward the adapter's own bound `(register, schema)` to `ObjectService::deleteObject()`. The array form MUST NOT collapse to an unscoped delete when the adapter itself is scoped.

The audit-trail row recorded by the scoped delete MUST capture both the `register` and `schema` of the deleted object, so the audit log distinguishes "deleted UUID X from `gemeente`/`meldingen`" from "deleted UUID X from `landelijk`/`meldingen`" even when the UUID is identical.

#### Scenario: Scoped delete refuses cross-scope UUID
- **GIVEN** an object with UUID `abc-123` exists in magic table `oc_openregister_table_1_5` (register `openconnector`, schema `source`)
- **AND** no object with UUID `abc-123` exists in register `softwarecatalogus` / schema `application`
- **WHEN** a caller invokes `ObjectService::deleteObject(uuid: 'abc-123', register: 'softwarecatalogus', schema: 'application')`
- **THEN** the pipeline MUST raise `DoesNotExistException`
- **AND** the row in `oc_openregister_table_1_5` MUST remain present and unmodified
- **AND** no audit-trail row MUST be recorded

#### Scenario: Scoped delete succeeds when UUID is in the requested scope
- **GIVEN** an object with UUID `abc-123` exists in magic table for register `openconnector` / schema `source`
- **WHEN** a caller invokes `ObjectService::deleteObject(uuid: 'abc-123', register: 'openconnector', schema: 'source')`
- **THEN** the pipeline MUST locate the object via the scoped `MagicMapper::find()` path (no cross-table scan)
- **AND** the row MUST be deleted from the `(openconnector, source)` magic table
- **AND** an audit-trail row MUST be recorded with `register=openconnector` and `schema=source`

#### Scenario: Cross-magic-table UUID collision touches only the matching scope
- **GIVEN** two distinct objects share UUID `dup-uuid`: one in register `A` / schema `X`, another in register `B` / schema `Y`
- **WHEN** a caller invokes `ObjectService::deleteObject(uuid: 'dup-uuid', register: 'B', schema: 'Y')`
- **THEN** only the row in the `(B, Y)` magic table MUST be deleted
- **AND** the row in the `(A, X)` magic table MUST remain present and unmodified

#### Scenario: Legacy unscoped delete remains unchanged
- **GIVEN** a caller invokes `ObjectService::deleteObject(uuid: 'abc-123')` with no register/schema (the pre-existing form)
- **WHEN** the pipeline runs
- **THEN** the legacy `findAcrossAllSources()` lookup MUST be used (preserves backward compatibility)
- **AND** the row MUST be deleted if found in any magic table
- **AND** the docblock `@deprecated` notice MUST point callers at the scoped form

#### Scenario: Adapter forwards bound scope to the service
- **GIVEN** an `ObjectServiceMapperAdapter` bound to register `openconnector` / schema `source`
- **AND** an object with UUID `abc-123` in a different scope (register `softwarecatalogus` / schema `application`)
- **WHEN** the adapter's `delete(['id' => 'abc-123'])` is called
- **THEN** the adapter MUST forward `(openconnector, source)` to `ObjectService::deleteObject()`
- **AND** the call MUST raise `DoesNotExistException` because `abc-123` is not in the bound scope
- **AND** the `softwarecatalogus` / `application` row MUST remain present and unmodified

### Requirement: Declarative per-transition authorization gate

OpenRegister SHALL enforce a declarative `authorization` list on a lifecycle
transition: when the matched transition declares a non-empty `authorization`
list of Nextcloud group ids and/or `{ "role": "<name>" }` entries, the caller
MUST satisfy the list on the `saveObject()` path for the transition to be
applied, otherwise the update SHALL be rejected with the structured error code
`lifecycle-transition-unauthorized` and the object data SHALL NOT be mutated.

Enforcement SHALL be fail-closed: an empty `authorization` list authorizes
nobody; an anonymous or unresolvable caller is denied; the `admin` group is
always authorized; a literal string entry matches a caller's Nextcloud group
membership; a `{ "role": "<name>" }` entry expands to the Nextcloud group ids
assigned to that role on the schema's `authorization.roles` map and matches the
same way. The authorization check SHALL run BEFORE any `requires` guard.

A transition WITHOUT an `authorization` key SHALL behave exactly as before
(additive); the gate SHALL only be evaluated when the key is present.

#### Scenario: Member of an authorized group may perform the transition
- **GIVEN** a transition declaring `"authorization": ["vergunningverleners"]`
- **AND** the caller belongs to the `vergunningverleners` Nextcloud group
- **WHEN** the caller saves the object with the transition's target lifecycle value
- **THEN** the transition is applied and no authorization error is raised

#### Scenario: Non-member is rejected fail-closed
- **GIVEN** a transition declaring `"authorization": ["vergunningverleners"]`
- **AND** the caller does NOT belong to that group and is not `admin`
- **WHEN** the caller attempts the transition via saveObject
- **THEN** the update is rejected with code `lifecycle-transition-unauthorized`
- **AND** the lifecycle field is not changed

#### Scenario: Anonymous caller is denied
- **GIVEN** a transition declaring a non-empty `authorization` list
- **AND** there is no authenticated user
- **WHEN** the transition is attempted
- **THEN** the update is rejected with code `lifecycle-transition-unauthorized`

#### Scenario: Named role expands to assigned groups
- **GIVEN** a transition declaring `"authorization": [{ "role": "handler" }]`
- **AND** the schema's `authorization.roles.handler` lists `["vergunningverleners"]`
- **AND** the caller belongs to `vergunningverleners`
- **WHEN** the transition is attempted
- **THEN** the transition is applied

#### Scenario: A transition without authorization is unaffected
- **GIVEN** a transition with no `authorization` key
- **WHEN** an otherwise-valid transition is attempted by any authenticated caller
- **THEN** no authorization gate is evaluated and the transition proceeds

### Requirement: Lifecycle annotation accepts property alias and string from

The `x-openregister-lifecycle` annotation SHALL accept `property` as an additive
alias for `field` (with `field` taking precedence when both are present), and a
transition's `from` MAY be a single state string in addition to an array of
state strings. These shapes SHALL be accepted by both schema-save validation and
runtime transition enforcement, and SHALL NOT change behavior for schemas already
authored with `field` and array `from`.

#### Scenario: property alias drives enforcement
- **GIVEN** an annotation authored with `"property": "lifecycle"` and no `field`
- **WHEN** an illegal transition is attempted on save
- **THEN** it is rejected with code `lifecycle-invalid-transition`

#### Scenario: string from is honored
- **GIVEN** a transition declaring `"from": "concept"` (a string, not an array)
- **WHEN** an object in state `concept` transitions to that transition's target
- **THEN** the transition is accepted as a valid `from` match

### Requirement: Lifecycle graph mode derives transitions from FK-scoped siblings

`x-openregister-lifecycle` SHALL support a declarative `graph` mode in addition to
the static `transitions` map. When a schema declares a non-empty `graph` block and no
non-empty `transitions` map, `TransitionEngine` SHALL derive the available and target
transitions **at runtime** from sibling objects of a related schema, scoped to the
transitioning object's own parent by a foreign key.

The `graph` block SHALL declare: `schema` (the sibling schema slug), `parentField`
(the FK property on the sibling that references the parent), `parentFrom` (the
property on the transitioning object holding the parent reference), `orderField` (the
numeric ordering property on the sibling), `finalField` (the boolean terminal-state
property on the sibling), and `allowedMoves` (one of `forward`, `adjacent`, `any`).

Derivation SHALL: read the parent reference from `object.data[parentFrom]`; fetch
sibling objects of `schema` where `parentField` equals that parent reference, ordered
ascending by `orderField` (ties broken deterministically by UUID); locate the object's
current state (`object.data[field]`) within that ordered list; and compute candidate
targets by `allowedMoves` — `forward` yields only the next-higher-ordered sibling,
`adjacent` yields the next-higher and next-lower siblings, `any` yields every sibling
except the current one. Each derived action SHALL have a stable id `move-to-<targetUuid>`,
a `to` equal to the target UUID, and a `label` equal to the target's display name.

The derivation used by `availableActions()` and the validation used by `transition()`
SHALL be the SAME code path, so a client can only apply a `move-to-<uuid>` action that
the current graph state offers.

#### Scenario: Forward move offers only the next status
- **GIVEN** a `case` object whose `status` is `Ontvangen` (order 1) and whose graph declares `allowedMoves: forward`
- **AND** sibling `statusType` objects `Ontvangen` (1), `In behandeling` (2), `Afgehandeld` (3) all scoped to the case's `caseType`
- **WHEN** `availableActions()` is called for the object
- **THEN** the result MUST contain exactly one action `move-to-<InBehandelingUuid>` targeting `In behandeling`

#### Scenario: Adjacent move offers previous and next status
- **GIVEN** the same object at `status` `In behandeling` (order 2) with `allowedMoves: adjacent`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST contain exactly two actions targeting `Ontvangen` (order 1) and `Afgehandeld` (order 3)

#### Scenario: Any move offers every other sibling
- **GIVEN** the same object at `status` `In behandeling` with `allowedMoves: any`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST contain one action per sibling except the current one

#### Scenario: Applying a derived transition mutates and saves the object
- **GIVEN** a `case` object at `status` `Ontvangen` with `allowedMoves: forward`
- **WHEN** `transition()` is called with action `move-to-<InBehandelingUuid>`
- **THEN** the object's `status` MUST be saved as the `In behandeling` UUID through the standard object save path
- **AND** an `ObjectTransitionedEvent` MUST be dispatched with `from` = the `Ontvangen` UUID and `to` = the `In behandeling` UUID

#### Scenario: A target the graph does not allow is rejected
- **GIVEN** a `case` object at `status` `Ontvangen` with `allowedMoves: forward`
- **WHEN** `transition()` is called with action `move-to-<AfgehandeldUuid>` (order 3, not adjacent)
- **THEN** the transition MUST be rejected and the object's `status` MUST NOT change

#### Scenario: Object without a parent reference yields no actions
- **GIVEN** a `case` object whose `parentFrom` property is empty
- **WHEN** `availableActions()` is called
- **THEN** the result MUST be an empty list

### Requirement: Terminal graph states lock out non-any moves

The engine MUST lock out moves out of a terminal graph state: when the object's
current sibling has `finalField` set to true, `TransitionEngine` SHALL yield no
candidate targets under `allowedMoves` `forward` or `adjacent` (the state is a sink).
Under `allowedMoves` `any`, terminality SHALL be advisory and the engine SHALL still
offer moves to the other siblings.

#### Scenario: Final state blocks forward and adjacent moves
- **GIVEN** a `case` object at `status` `Afgehandeld` whose `statusType.isFinal` is true, with `allowedMoves: forward`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST be an empty list

#### Scenario: Any mode overrides terminal lockout
- **GIVEN** the same final-state object but with `allowedMoves: any`
- **WHEN** `availableActions()` is called
- **THEN** the result MUST contain actions targeting the non-final siblings

### Requirement: Static transitions take precedence over graph mode

The engine MUST prefer static transitions over graph mode: when a schema declares
BOTH a non-empty static `transitions` map and a `graph` block, `TransitionEngine`
SHALL use only the static `transitions` map and SHALL ignore the `graph` block, in
both `availableActions()` and `transition()`. Schemas declaring only
`transitions` SHALL behave exactly as before this change (no regression).

#### Scenario: Both declared uses static path
- **GIVEN** a schema declaring both a non-empty `transitions` map and a `graph` block
- **WHEN** `availableActions()` is called for an object of that schema
- **THEN** the actions MUST be derived from the static `transitions` map only
- **AND** no sibling objects MUST be fetched for derivation

### Requirement: Schema validation accepts the graph block and object-form initial

`LifecycleAnnotationValidator` SHALL accept a `graph` block on
`x-openregister-lifecycle` and SHALL shape-check it: `schema`, `parentField`,
`parentFrom`, `orderField`, and `finalField` MUST be non-empty strings, and
`allowedMoves` MUST be one of `forward`, `adjacent`, `any`. When `graph` is present,
the `field` property MUST be a non-empty string but the `enum`/`type:string`
constraint on that field SHALL be relaxed (a `$ref` lifecycle field has no enum).
`initial` MAY be either the existing literal-string form OR an object of the form
`{ "from": "<property>", "field": "<property>" }`; both string keys MUST be non-empty
when the object form is used. Validation SHALL NOT resolve sibling schemas or parent
objects — existence is a runtime concern. Validation errors SHALL use the existing
`lifecycle-*` error-code convention.

#### Scenario: Valid graph annotation passes validation
- **GIVEN** a schema whose `x-openregister-lifecycle` declares a well-formed `graph` block and object-form `initial`
- **WHEN** the schema is validated
- **THEN** `LifecycleAnnotationValidator` MUST return no errors

#### Scenario: Invalid allowedMoves is rejected
- **GIVEN** a `graph` block whose `allowedMoves` is `sideways`
- **WHEN** the schema is validated
- **THEN** the validator MUST return an error identifying the invalid `allowedMoves` value

#### Scenario: Missing graph key is rejected
- **GIVEN** a `graph` block missing `parentField`
- **WHEN** the schema is validated
- **THEN** the validator MUST return an error identifying the missing key

### Requirement: Object-form initial auto-seeds the lifecycle field on create

The object-create pipeline MUST auto-seed the lifecycle field when the schema
declares an object-form `initial` (with `from` and `field` keys) on
`x-openregister-lifecycle`: on the CREATE path only (never on update), when the
lifecycle field is absent, null, or the empty string, the pipeline MUST read the
parent reference from the object's `initial.from` property, load the parent object
through the standard `ObjectService` read path, and set the lifecycle field to the
parent's `initial.field` value BEFORE schema validation and persistence.

An explicitly provided lifecycle value MUST NOT be overwritten by the seed step.
When the parent reference is empty, the parent cannot be loaded, or the parent's
`initial.field` value is empty, the seed step SHALL be a no-op and the create SHALL
proceed with the field unset (normal schema validation then applies). The seed step
SHALL NOT dispatch an `ObjectTransitionedEvent` (it is an initialisation, not a
transition), and the legacy literal-string `initial` form SHALL keep its existing
static-mode semantics unchanged (no auto-seed behaviour change for static schemas).

#### Scenario: Empty lifecycle field is seeded from the parent on create
- **GIVEN** a `case` schema declaring `initial: { "from": "caseType", "field": "initialStatus" }`
- **AND** a create payload whose `caseType` references an `Omgevingsvergunning` case type with `initialStatus` = the `Ontvangen` UUID and whose `status` is empty
- **WHEN** the object is created
- **THEN** the persisted object's `status` MUST equal the `Ontvangen` UUID
- **AND** no `ObjectTransitionedEvent` MUST be dispatched for the seed

#### Scenario: Explicitly provided value is not overwritten
- **GIVEN** the same schema and a create payload whose `status` is explicitly set to the `In behandeling` UUID
- **WHEN** the object is created
- **THEN** the persisted object's `status` MUST equal the `In behandeling` UUID (the client-supplied value wins)

#### Scenario: Missing parent reference makes the seed a no-op
- **GIVEN** the same schema and a create payload with an empty `caseType` and an empty `status`
- **WHEN** the object is created
- **THEN** the seed step MUST be a no-op and the `status` field MUST remain unset
- **AND** normal schema validation MUST still apply to the unset field

#### Scenario: Parent without an initial status makes the seed a no-op
- **GIVEN** the same schema and a create payload whose `caseType` references a parent whose `initialStatus` is empty
- **WHEN** the object is created
- **THEN** the seed step MUST be a no-op and the `status` field MUST remain unset

### Requirement: A transition MAY declare `actions[]` that OpenRegister MUST execute on any transition form

OpenRegister MUST execute the `actions[]` a schema declares on a lifecycle
transition whenever that transition occurs, regardless of the transition form.
A schema's `x-openregister-lifecycle.transitions[<action>]` MAY declare an
`actions` array; each entry is an action envelope with a required `action` name,
an optional `actionParameters` object, and an optional `condition` string. When an
object's lifecycle field moves along a declared transition — through
`TransitionEngine::transition()` **or** through a plain list-form edit of the
lifecycle field via `ObjectService::saveObject()` — OpenRegister MUST run that
transition's declared actions.

`LifecycleActionListener`, on `ObjectUpdatingEvent`, MUST parse
`x-openregister-lifecycle` off `Schema::getConfiguration()`, match the transition
from the old and new value of the lifecycle `field`/`property` (the same match
`LifecycleValidationListener` performs), and — when the matched transition
declares a non-empty `actions[]` — invoke `LifecycleActionExecutor`. Because the
listener runs on the save path, the declared actions MUST run for every
transition form, closing the gap where list-form transitions bypassed
`TransitionEngine` and ran no actions at all.

The listener MUST NOT run actions when a prior listener has stopped propagation
(a rejected or approval-blocked transition), and MUST NOT run actions on an
initial create (no prior object state).

`LifecycleActionExecutor` MUST resolve each action's `action` name to a
`LifecycleActionInterface` handler through `LifecycleActionRegistry`. A
self-mutating handler returns the modified object payload, which the executor
threads to the next action and which the listener applies to the object before
persistence. When an action declares a `condition`, the executor MUST evaluate it
(`@self.<field>` / `@previous.<field>` equality against the new/old payload) and
skip the action when it does not hold.

`LifecycleActionRegistry` MUST ship built-in handlers for the action names
`set-fields` and `set-field` (stamping declared field values onto the object,
resolving the `@now` token to an ISO-8601 UTC timestamp). Action names without a
built-in MUST resolve to an app-registered service under that id.

A declared action that resolves to **no** registered handler, an action envelope
with no `action` name, or an **unparseable** `condition`, MUST FAIL LOUDLY —
`LifecycleActionExecutor`/`LifecycleActionRegistry` throw a `RuntimeException`
that propagates out of the listener and aborts the save. A declared action MUST
NOT be silently dropped — silent no-op is the exact defect this requirement
eliminates.

#### Scenario: A declared action runs on a list-form transition
- **GIVEN** a schema whose `activate` transition (draft → active) declares `actions: [{ "action": "set-fields", "actionParameters": { "activatedAt": "@now" } }]`
- **AND** an object whose lifecycle field is edited from `draft` to `active` through an ordinary `saveObject()` (no `TransitionEngine` call)
- **WHEN** the `ObjectUpdatingEvent` fires
- **THEN** the `set-fields` action MUST run and `activatedAt` MUST be stamped onto the object payload that is persisted

#### Scenario: A declared action naming a missing handler fails loudly
- **GIVEN** a transition that declares `actions: [{ "action": "phantom-materialiser" }]` with no service registered under `phantom-materialiser`
- **WHEN** that transition is attempted
- **THEN** `LifecycleActionRegistry::resolve()` MUST throw a `RuntimeException` naming the unregistered action
- **AND** the exception MUST propagate out of `LifecycleActionListener` (aborting the save), never a silent no-op

#### Scenario: A blocked transition runs no actions
- **GIVEN** a transition whose `ObjectUpdatingEvent` has already been rejected or blocked by a prior listener (propagation stopped)
- **WHEN** `LifecycleActionListener::handle()` runs
- **THEN** it MUST return without resolving or running any action

#### Scenario: An action condition that does not hold is skipped
- **GIVEN** an action declaring `condition: "@self.settlementMode == 'reimbursable'"` on a transition
- **AND** the transitioning object's `settlementMode` is `passthrough`
- **WHEN** the executor runs the transition's actions
- **THEN** the conditioned action MUST NOT run and its handler MUST NOT be resolved

## Cross-References
- **rbac-scopes** — RBAC checks are applied by `PermissionHandler` at the start of every pipeline stage
- **schema-hooks** — schema hooks fire via event dispatcher after each successful save
- **audit-trail-immutable** — `AuditHandler` records every mutation as an immutable audit trail entry
- **linked-entity-types** — `RelationHandler` and `RelationCascadeHandler` resolve and cascade linked entity relations
- **faceting-configuration** — `FacetHandler` builds facet aggregations from queried object sets
- **zoeken-filteren** — `SearchQueryHandler` and `QueryHandler` translate search parameters into database queries

---
status: done
retrofit: true
---

# Object Lifecycle

**Status**: in-progress

**OpenSpec changes**:
- `tighten-relation-detection-heuristic` (active) — relation detection records a string in `@self.relations` only when it is a UUID/prefixed-UUID/URL or a schema-declared reference property; removes the loose "8+ chars with hyphen/underscore" heuristic that polluted the map with dates, enum values, and business identifiers. Correctness fix to a derived field; no schema/lifecycle/aggregation/notification change.
- `fk-graph-lifecycle-transitions` — adds declarative FK-scoped graph transition mode (in-progress)
- `lifecycle-declarative-conditions` — adds an optional declarative JSONLogic `condition` (plus a `message`) on a lifecycle transition, refusing the save with `lifecycle-condition-unmet` when it does not hold; malformed conditions are rejected at schema-save time (in-progress)
- `lifecycle-auto-transitions`: lets a transition declare `autoWhen` (a JSONLogic rule) and `executionMode` (`sync` by default, or `async`), so OpenRegister fires it through the named-transition engine after a write, bounded by a loop cap; malformed or graph-mode declarations are refused at schema-save time (in-progress)

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

### Requirement: Lifecycle provider mode delegates available actions to an app service

The engine MUST support a third lifecycle mode for schemas whose state machine
is data rather than annotation: a per-object workflow template, a per-case-type
status list, or a policy table an administrator edits. When
`x-openregister-lifecycle` declares a non-empty `provider` string and no
non-empty static `transitions` map, `TransitionEngine::availableActions()` SHALL
resolve that value through `LifecycleActionProviderRegistry` and SHALL publish
what the resolved `LifecycleActionProviderInterface` answers.
`availableActions()` on the provider is a read: it MUST NOT mutate the object,
because it is called on a GET the caller may repeat at will. The move it offers
is taken through the same interface's `execute()`, specified below.

Mode precedence SHALL be static, then provider, then graph. A schema declaring a
non-empty `transitions` map SHALL keep it whatever else it declares, so an
annotation that grows a second mode never silently loses the transitions it had.

Each published entry SHALL carry `action`, `to`, `requires`, `description` and
`inputs`, and MAY carry `label` and `blocked`. The `inputs` list SHALL be
normalised through the same code that normalises a static transition's declared
`inputs`, so what a client is told a transition accepts is exactly what the write
path will accept.

#### Scenario: Provider answer is published
- **GIVEN** a schema whose `x-openregister-lifecycle` declares a `provider` tag and no static `transitions`
- **WHEN** `availableActions()` is called for an object of that schema
- **THEN** the response MUST contain the actions the registered provider answered, in the order it answered them

#### Scenario: Static transitions take precedence over a provider
- **GIVEN** a schema declaring both a non-empty `transitions` map and a `provider` tag
- **WHEN** `availableActions()` is called for an object of that schema
- **THEN** the actions MUST be derived from the static `transitions` map only
- **AND** the provider MUST NOT be resolved

#### Scenario: Provider inputs are normalised onto the published contract
- **GIVEN** a provider that answers an action whose `inputs` list contains an entry naming no field
- **WHEN** `availableActions()` is called
- **THEN** that entry MUST be dropped and the remaining entries MUST be published as `{field, required}` pairs

### Requirement: Lifecycle provider mode applies the move through the same app service

A mode that can offer a move and not take it is worse than one that offers
nothing, so the write path SHALL resolve `provider` exactly as the read path
does. `TransitionEngine::transition()` SHALL select modes in the SAME order
`availableActions()` uses — static `transitions`, then `provider`, then `graph`
— and when a non-empty `provider` string is declared beside no non-empty static
map, it SHALL resolve that tag through `LifecycleActionProviderRegistry` and
call `LifecycleActionProviderInterface::execute($object, $userId, $action,
$data)`.

`execute()` is declared on the same interface as `availableActions()` and is
MANDATORY, not an optional second interface: an app that may implement only the
read half can publish a timeline whose every click is refused, which is the
defect this requirement closes. A provider with nothing to offer says so by
answering an empty list; a provider that refuses one move says so by throwing.

THE PROVIDER PERFORMS THE WRITE. OpenRegister SHALL NOT mutate the lifecycle
field and SHALL NOT call `ObjectService::saveObject()` on this path. An app
whose state machine is data owns more than the field the status lands in —
guard re-evaluation, an optimistic version lock, a required closing result, a
status record, side-effect dispatch — and a branch that took a new value back
and saved it would strand all of them and race the app's own write.

OpenRegister SHALL NOT re-derive the posted action before handing it over, and
SHALL pass `$data` through without allowlisting it. The provider re-validates
with the same reader it answered the read with, so there is one authority; a
check in the engine would be a second derivation that can disagree with the
first, and the `inputs` a client was shown came from the provider rather than
from the schema.

After `execute()` returns, the engine SHALL re-read the object through
`ObjectService::find()` and answer the endpoint with what is stored, not with
the provider's echo of it. The provider's return value is a REPORT: exactly one
key, `to`, is read off it, and when it is absent the target state SHALL be read
off the re-read object's lifecycle field. Every other key is the app's own, so
a provider may return its existing result shape verbatim. The engine SHALL then
dispatch `ObjectTransitionedEvent` with the action, the pre-move value of the
lifecycle field as `from`, and that target state as `to`.

THE WRITE BOUNDARY IS DELIBERATELY NOT DECLARED on this path.
`LifecycleWriteBoundary::declaringAction()` exists so the lifecycle listeners
judge the named transition rather than the first one sharing its from/to pair.
In provider mode the annotation carries no `transitions` map, so there is no
name for them to look up; a declaration would tell them a name they cannot
resolve, would claim OpenRegister is applying it through its own save pipeline
while the app is writing across several objects, and would hold that claim open
for the whole app call rather than around one save. The enforcement the
declaration supports has not been lost, it has moved into the provider, which
re-validates the move itself — which is the difference from graph mode, where
the declaration is skipped AND nothing re-checks the move, and which is why
graph mode is documented as unenforced and this is not.
`LifecycleWriteBoundary::around()` still frames the write, because
`transition()` wraps every mode in it, so a rule-driven move that follows from
this one is still drained and still wins the entity that is answered.

#### Scenario: A provider-mode schema takes the move it offered
- **GIVEN** a schema whose `x-openregister-lifecycle` declares a `provider` tag and no static `transitions`
- **AND** a registered provider that published an action for the object's current state
- **WHEN** a client posts that action to the transition endpoint
- **THEN** the provider's `execute()` MUST be called with the object payload, the caller's uid, the action and the posted data
- **AND** OpenRegister MUST NOT save the object itself
- **AND** the response MUST be the object as re-read after the provider's write

#### Scenario: Static transitions take precedence over a provider on the write path
- **GIVEN** a schema declaring both a non-empty `transitions` map and a `provider` tag
- **WHEN** a declared static action is posted to the transition endpoint
- **THEN** the static transition MUST be applied through `ObjectService::saveObject()`
- **AND** the provider MUST NOT be resolved

#### Scenario: The transitioned event names the state the object actually reached
- **GIVEN** a provider whose report does not name a target state
- **WHEN** its move is applied
- **THEN** the dispatched `ObjectTransitionedEvent` MUST carry the lifecycle field of the re-read object as `to`

### Requirement: A provider that cannot answer MUST fail closed rather than return an empty list

An empty action list is a successful answer meaning the object offers no moves
from its current state, so a provider failure MUST NOT be reported as one. When
the declared `provider` resolves to no service, resolves to a service that does
not implement `LifecycleActionProviderInterface`, or throws while answering, the
engine SHALL raise `LifecycleProviderException` and
`TransitionController::availableActions()` SHALL answer HTTP 502. The existing
403 for a caller without `read` permission and 404 for a missing object SHALL be
unchanged.

Because a broken provider declaration fails at read time rather than at save
time, `lifecycle-provider-invalid` and `lifecycle-provider-mode-conflict` SHALL
refuse the schema save, unlike the advisory lifecycle findings that are stored as
written.

ON THE WRITE PATH, THREE FAILURES SHALL STAY DISTINGUISHABLE, because a handler
acts differently on each: a refused move is retried differently, a broken
provider is reported to someone, and a missing object is not retried at all.

- A REFUSAL — a guard said no, the object already moved, a required input is
  absent — SHALL be an ordinary `RuntimeException` from the provider, SHALL NOT
  be wrapped or reclassified by the engine, and SHALL answer HTTP 422 carrying
  the provider's own message. A refusal MUST leave the object untouched.
- A BREAKAGE SHALL answer HTTP 502. The provider declares one by throwing
  `LifecycleProviderException`; the engine raises one itself when the tag
  resolves to nothing or to the wrong type, when the provider throws anything
  that is not a `RuntimeException` (a `TypeError`, an `Error` — an unplanned
  failure is never a considered refusal), and when the object cannot be re-read
  after the write. A provider MUST NOT delete the object as part of a move,
  because the endpoint has nothing truthful left to answer with.
- A MISSING OBJECT SHALL answer HTTP 404, through
  `LifecycleSubjectNotFoundException`, which extends `RuntimeException` so
  REQ-007's "throw `RuntimeException` if not found" still holds and every
  existing catch site is unchanged. This aligns the write path with the read
  path, which already answered 404 for the same condition while the write path
  reported it as a refusal.

`TransitionController::transition()` SHALL catch these in that order —
`LifecycleSubjectNotFoundException`, then `LifecycleProviderException`, then
`RuntimeException` — because each extends the next and a reorder silently
collapses one status into another.

#### Scenario: A refused move answers 422 with the provider's sentence
- **GIVEN** a registered provider whose `execute()` throws a `RuntimeException` explaining why the move is refused
- **WHEN** a client posts that action to the transition endpoint
- **THEN** the response status MUST be 422 and the body MUST carry the provider's message
- **AND** no `ObjectTransitionedEvent` MUST be dispatched

#### Scenario: A broken provider answers 502 on the write path
- **GIVEN** a registered provider whose `execute()` throws a `TypeError`
- **WHEN** a client posts an action to the transition endpoint
- **THEN** the failure MUST be logged and raised as `LifecycleProviderException`
- **AND** the response status MUST be 502, never 422

#### Scenario: A missing object answers 404 on the write path
- **GIVEN** an object id that resolves to nothing
- **WHEN** a client posts an action to the transition endpoint
- **THEN** the response status MUST be 404, distinct from the 422 a refused move answers

#### Scenario: Unresolvable provider answers 502
- **GIVEN** a schema declaring a `provider` tag that no app has registered
- **WHEN** a client calls the available-actions endpoint for an object of that schema
- **THEN** the response status MUST be 502
- **AND** the response MUST NOT contain an empty `actions` list

#### Scenario: Provider with no moves answers an empty list
- **GIVEN** a registered provider that answers an empty list for the object's current state
- **WHEN** a client calls the available-actions endpoint
- **THEN** the response status MUST be 200 and `actions` MUST be an empty list

### Requirement: Schema validation accepts the provider block and refuses two modes on one field

`LifecycleAnnotationValidator` SHALL accept a `provider` key on
`x-openregister-lifecycle` and SHALL shape-check it: `provider` MUST be a
non-empty string, `field` MUST be a non-empty string declared in `properties`,
and the `enum`/`type:string` constraint on that field SHALL be relaxed as it is
for graph mode, because the app owns the state vocabulary. `initial` MAY be
either the literal-string form or the object form `{ "from": ..., "field": ... }`.

Declaring a non-empty `transitions` map or a non-empty `graph` block beside
`provider` SHALL be refused with `lifecycle-provider-mode-conflict`. The engine
does resolve the ambiguity by precedence, but the mode it drops would read as
declared and never run, which is the failure the graph `condition` refusal
already guards against. An empty `transitions` or `graph` value declares no
second mode and SHALL NOT be refused.

#### Scenario: Valid provider annotation passes validation
- **GIVEN** a schema whose `x-openregister-lifecycle` declares a `provider` tag, a `field` present in `properties` with no enum, and an object-form `initial`
- **WHEN** the schema is validated
- **THEN** `LifecycleAnnotationValidator` MUST return no errors

#### Scenario: Empty provider is rejected
- **GIVEN** an annotation whose `provider` is an empty string
- **WHEN** the schema is validated
- **THEN** the validator MUST return `lifecycle-provider-invalid`

#### Scenario: Two modes on one field are rejected
- **GIVEN** an annotation declaring both `provider` and a non-empty `transitions` map
- **WHEN** the schema is validated
- **THEN** the validator MUST return `lifecycle-provider-mode-conflict`

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

### Requirement: A lifecycle kept as data is validated through one entry point
An app that lets a person author a state machine as an object (a process template, not a schema annotation) MUST be able to validate that graph through `LifecycleTransitionsValidator::validate(states, initial, transitions, knownGuards)` without wrapping it in a schema. The method MUST return a list of `{code, message}` errors, empty when the graph is valid, and MUST refuse: no named state (`lifecycle-states-empty`), a missing or undeclared initial state (`lifecycle-initial-missing`, `lifecycle-initial-not-declared`), a transition that is not an object or lacks `from` or `to` (`lifecycle-transition-malformed`, `lifecycle-from-missing`, `lifecycle-to-missing`), an endpoint that is not a declared state (`lifecycle-from-not-declared`, `lifecycle-to-not-declared`), a declared state no transition starts or ends in that is not the initial state (`lifecycle-state-unreachable`), and, when the app passes its guard catalogue, a guard token outside it (`lifecycle-guard-unknown`). The unreachable rule MUST NOT be applied to `x-openregister-lifecycle` schema annotations, where an enum value nothing moves to is a legitimate legacy value and refusing it would stop shipped schemas from importing.

#### Scenario: A state no transition touches is refused
- **GIVEN** states `draft`, `decided` and `orphan`, initial state `draft`, and one transition from `draft` to `decided`
- **WHEN** the app validates the graph
- **THEN** the result MUST hold exactly one error, code `lifecycle-state-unreachable`, whose message names `orphan`

#### Scenario: A dangling transition is refused by name
- **GIVEN** states `draft` and `decided` and a transition from `decided` to `ghost`
- **WHEN** the app validates the graph
- **THEN** the result MUST hold `lifecycle-to-not-declared` with a message naming `ghost`

#### Scenario: A guard token outside the catalogue is refused
- **GIVEN** a transition declaring guards `quorum_met` and `made_up_token`, and the catalogue `quorum_met`
- **WHEN** the app validates the graph with that catalogue
- **THEN** the result MUST hold `lifecycle-guard-unknown` naming `made_up_token`, so a typo never disables a guard

#### Scenario: Without a catalogue guards are only shape-checked
- **GIVEN** a transition whose `guards` is a list of non-empty strings
- **WHEN** the app validates the graph without a catalogue
- **THEN** the guards MUST be accepted, and a `guards` value that is not a list MUST be refused with `lifecycle-guards-malformed`

### Requirement: A lifecycle state declares hidden, read-only and required fields per role

`x-openregister-lifecycle.states.<state>.fields` MAY declare `hidden`,
`readOnly` and `required` lists of `{fields, groups}`. Schema-save
validation SHALL refuse a field the schema does not declare, a state the
lifecycle does not declare, and a transition `inputs` entry naming a field
`hidden` in the target state.

#### Scenario: an unknown field is refused at schema save

- **GIVEN** a lifecycle whose state `open` requires field `outcome` and a schema without `outcome`
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming `outcome` and `open`
- @e2e exclude {asserted in tests/Unit/Service/Lifecycle/LifecycleStateFieldValidationTest.php::testAnUnknownFieldIsRefusedAtSchemaSave; the HTTP half rides tests/e2e/ci/field-rules-by-state.spec.ts}

### Requirement: A state declares entry and exit conditions

`x-openregister-lifecycle.states.<state>` MAY declare `entry` and `exit`
conditions over the object's data, grouped with and or or. An entry
condition SHALL be evaluated on every path into the state and an exit
condition on every path out of it, whichever transition is used. A refusal
SHALL name the clause that failed, in the schema author's declared
message where one is given. Schema-save validation SHALL refuse a
condition naming a property the schema does not declare.

#### Scenario: one rule guards every path into a state

- **GIVEN** a state `besloten` whose entry condition requires `besluit` to be present, reachable by three transitions
- **WHEN** an object without `besluit` is moved into it by any of the three
- **THEN** the move is refused and the refusal names `besluit`

#### Scenario: the failing clause is named

- **GIVEN** an entry condition grouping two clauses with and
- **WHEN** the second clause is false
- **THEN** the refusal names the second clause
- @e2e exclude {asserted in tests/Unit/Service/Lifecycle/StateConditionEvaluatorTest.php::testTheFailingClauseOfAnAndIsNamed}

#### Scenario: a condition on an undeclared property is refused at schema save

- **GIVEN** an exit condition naming a property the schema does not declare
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming the property
- @e2e exclude {asserted in tests/Unit/Service/Lifecycle/LifecycleStateFieldValidationTest.php::testAConditionOnAnUndeclaredPropertyIsRefused}

### Requirement: Referential-integrity CASCADE deletions MUST be batched

`ReferentialIntegrityService::applyDeletionActions()` SHALL apply the CASCADE
targets of a pre-computed `DeletionAnalysis` with batched statements: ONE
cross-magic-table lookup resolving all target UUIDs, one
`UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)` per magic
table (per-target `deletedBy`/`deletedAt`/`objectId`/`organisation` attribution
metadata bound per row), and ONE multi-row audit INSERT — instead of a
cross-table scan plus a single-row audit INSERT per target.

Per-target semantics SHALL be preserved: an object-updating event is dispatched
per target before the write (a hook stopping propagation skips that target; a
payload-modifying hook routes that target through the full-row save), an
object-updated event is dispatched per target after the write, and one audit
row is written per analysis target (a target referenced through two properties
yields two rows). Targets the uuid-based batch lookup cannot resolve — and all
targets when the batched resolve or write fails — SHALL fall back to the
legacy per-object pipeline unchanged. RESTRICT, SET_NULL and SET_DEFAULT
handling and the SET_NULL → SET_DEFAULT → CASCADE (deepest first) execution
order are unchanged.

#### Scenario: CASCADE targets are soft-deleted with batched statements
- **GIVEN** a deletion analysis with N CASCADE targets stored in one magic table
- **WHEN** `applyDeletionActions()` runs
- **THEN** the targets are resolved with one batched cross-table lookup
- **AND** soft-deleted with one UPDATE statement carrying per-target attribution
  metadata including the acting user and active organisation
- **AND** N per-object updating and updated events are dispatched
- **AND** N audit rows are persisted with one multi-row INSERT

#### Scenario: Batch misses keep the per-object pipeline
- **GIVEN** a deletion analysis where one CASCADE target UUID is not found by the
  batched lookup
- **WHEN** `applyDeletionActions()` runs
- **THEN** that target is deleted and audited through the unchanged per-object
  pipeline
- **AND** the resolved targets are still handled by the batched statements

#### Scenario: Batch failure falls back to the per-object pipeline
- **GIVEN** the batched lookup or the batched soft-delete write fails
- **WHEN** `applyDeletionActions()` continues
- **THEN** every CASCADE target is retried through the legacy per-object
  pipeline and no exception escapes

#### Scenario: Non-CASCADE actions are untouched
- **GIVEN** a deletion analysis with SET_NULL and SET_DEFAULT targets
- **WHEN** `applyDeletionActions()` runs
- **THEN** those targets are processed per object exactly as before, before any
  CASCADE deletion

### Requirement: An app can run a transition as the system after approving the caller itself (REQ-TAS-001)

OpenRegister SHALL offer a server-side entry point, `TransitionEngine::transitionAsSystem()`,
through which app code that has already checked the caller runs a named
transition on an object the caller holds no right on. The entry point SHALL
require the id of the app taking that decision and SHALL refuse an empty
one. On that path OpenRegister SHALL skip its own read check on the
subject, its `update` check on the subject, and RBAC and the organisation
filter on the lifecycle save, and nothing else.

#### Scenario: the normal path still refuses a caller without rights

- **GIVEN** a draft object the session user may not read or update
- **WHEN** the user's request runs the transition through `transition()`
- **THEN** the transition is refused and nothing is written
- @e2e exclude {engine-level contract, covered by TransitionEngineAsSystemTest}

#### Scenario: the same caller succeeds through the system entry point

- **GIVEN** the same draft and the same session user, and an app that has checked the user itself
- **WHEN** the app calls `transitionAsSystem()` naming itself
- **THEN** the object moves to the transition's target state
- **AND** OpenRegister's `update` check is not consulted
- @e2e exclude {server-side entry point with no HTTP route, covered by TransitionEngineAsSystemTest}

#### Scenario: an app must name itself

- **WHEN** `transitionAsSystem()` is called with an empty app id
- **THEN** it is refused before the object is read
- @e2e exclude {argument check, covered by TransitionEngineAsSystemTest}

### Requirement: What a transition declares still runs on the system path (REQ-TAS-002)

On the system path the lifecycle save SHALL still dispatch the update
event, so the transition's declared `authorization`, `condition` and
`requires` guard run and see the session user, the real caller, and a
refusal from any of them SHALL refuse the transition. The declared
`actions[]` and the transitioned event SHALL run with the real caller as
their user.

#### Scenario: the guard runs with the real caller

- **GIVEN** a transition that `requires` an app guard
- **WHEN** an app runs it through `transitionAsSystem()`
- **THEN** the guard is asked once, with the session user's id
- @e2e exclude {listener contract, covered by TransitionEngineAsSystemTest with the real LifecycleValidationListener}

#### Scenario: a guard refusal still refuses

- **GIVEN** a guard that denies the move
- **WHEN** an app runs the transition through `transitionAsSystem()`
- **THEN** the transition is refused with `lifecycle-guard-denied`
- @e2e exclude {listener contract, covered by TransitionEngineAsSystemTest with the real LifecycleValidationListener}

### Requirement: A system transition is recorded with the real caller and the app (REQ-TAS-003)

The audit row of a system transition SHALL name the real caller as its
user and SHALL carry `transitionAsSystem: {"app": "<app id>"}` in its change
set. The mark SHALL apply only while the transition's write runs and SHALL
be released when the write is refused. An ordinary write SHALL carry no
such mark. Each system transition SHALL also be logged at info level with
the app, the caller, the object and the action.

#### Scenario: the audit row names both

- **GIVEN** an app runs a transition through `transitionAsSystem()` for user `learner-1`
- **WHEN** the audit row of that write is built
- **THEN** its user is `learner-1` and its change set carries `transitionAsSystem` with the app id
- @e2e exclude {audit row builder, covered by TransitionEngineAsSystemTest}

#### Scenario: the mark does not outlive the write

- **GIVEN** a system transition that finished, or was refused
- **WHEN** the same object is saved again in the same request
- **THEN** that save carries no system mark
- @e2e exclude {request-scoped state, covered by TransitionEngineAsSystemTest}

### Requirement: The HTTP API cannot reach the system path (REQ-TAS-004)

No controller SHALL call `transitionAsSystem()`, and nothing in the
transition payload SHALL select the system path.

#### Scenario: the payload cannot ask for it

- **GIVEN** a caller without rights on the object
- **WHEN** the transition endpoint is called with `_rbac`, `asSystem` or `app` in the payload
- **THEN** the transition is refused as for any caller without rights
- @e2e exclude {engine-level contract, covered by TransitionEngineAsSystemTest}

#### Scenario: no controller calls it

- **WHEN** the source under `lib/` is searched for calls to `transitionAsSystem()`
- **THEN** none is found under `lib/Controller`
- @e2e exclude {structural assertion, covered by TransitionEngineAsSystemTest}

### Requirement: A transition MAY declare `inputs[]` bounding the payload it accepts

A schema's `x-openregister-lifecycle.transitions[<action>]` MAY declare an
`inputs` array whose entries name a property of that schema and whether it is
required: `inputs: [{"field": "<propertyName>", "required": true|false}]`.

OpenRegister MUST enforce that declaration as an ALLOWLIST when a transition
is applied with a payload:

- A payload key the transition does not declare MUST be REJECTED. A
  transition that declares no `inputs` therefore accepts NO payload at all —
  which is the existing behaviour of every transition in the fleet and MUST
  remain so, so that opting in is explicit and no schema changes behaviour by
  this requirement being written down.
- A declared `required` input that is absent from the payload, or supplied as
  an empty string, MUST be REJECTED.
- Accepted values MUST be merged into the object write that carries the
  transition, so ordinary save-path schema validation and read-only
  enforcement apply to them exactly as to any other object write. OpenRegister
  MUST NOT validate an accepted value only against the `inputs` declaration:
  the declaration says which fields may be supplied, and the schema says what
  a legal value is.
- The accepted values and the lifecycle field change MUST land in ONE save, so
  a caller cannot observe an object whose values were written while its state
  change was refused, or the reverse.

A rejection MUST carry the offending field names in a machine-readable form
alongside the human message, and MUST distinguish an undeclared key from a
missing required input. A malformed payload is a client error and MUST be
reported as one, distinctly from a transition that is refused from the current
state and from one the caller is not authorized to take.

Declaring `inputs` MUST NOT change which transitions are available, who may
take them, or what any existing declared guard, authorization gate or
`actions[]` entry does.

#### Scenario: A transition with no declared inputs rejects a payload
- **GIVEN** a schema whose `approve` transition declares no `inputs`
- **WHEN** the transition is applied with a payload containing one field
- **THEN** the call MUST be rejected naming that field as undeclared
- **AND** the object's lifecycle field MUST be unchanged

#### Scenario: A declared required input is enforced
- **GIVEN** a `reject` transition declaring `inputs: [{"field": "reason", "required": true}]`
- **WHEN** the transition is applied with an empty payload
- **THEN** the call MUST be rejected naming `reason` as a missing required input
- **AND** the response MUST carry that field name machine-readably

#### Scenario: An accepted value is still validated by the schema
- **GIVEN** a `reject` transition declaring `reason` as an input, where the
  schema constrains `reason` to an enumerated set
- **WHEN** the transition is applied with a `reason` outside that set
- **THEN** the save-path validation MUST refuse the write
- **AND** the object's lifecycle field MUST be unchanged

#### Scenario: An accepted value cannot overwrite a read-only property
- **GIVEN** a transition declaring an input naming a property the schema marks
  read-only
- **WHEN** the transition is applied supplying that property
- **THEN** the read-only enforcement on the save path MUST apply exactly as it
  does to any other object write

### Requirement: The available-actions response MUST publish each action's declared inputs

Every action returned by the available-actions endpoint MUST carry the
declared `inputs` for that transition — the field names and their required
flags — so a client can present the payload the transition expects without
reading the schema.

An action whose transition declares no inputs MUST carry an EMPTY inputs list
rather than omitting the key. Absent and empty MUST NOT be the same value on
this response: empty is the positive statement "this transition accepts no
payload", which is exactly what the allowlist enforces, and a client must be
able to read it as such rather than infer it from silence.

This MUST hold for actions derived from a static transition map and for
actions derived at runtime from a graph block, so a client's handling of the
response does not have to know which mode a schema uses.

The endpoint's existing per-action keys MUST be unchanged, and the existing
read-permission check that gates the response MUST be unchanged: publishing
what a transition accepts MUST NOT be reachable by a caller who may not read
the object.

#### Scenario: A declaring transition publishes its fields
- **GIVEN** a schema whose `reject` transition declares two inputs, one required
- **AND** an object in a state from which `reject` is available
- **WHEN** the available-actions endpoint is called for that object
- **THEN** the `reject` action MUST carry both field names with their required flags

#### Scenario: A non-declaring transition publishes an empty list
- **GIVEN** a transition declaring no `inputs`
- **WHEN** the available-actions endpoint is called
- **THEN** that action MUST carry an empty inputs list
- **AND** the key MUST be present

#### Scenario: A caller without read permission still learns nothing
- **GIVEN** a user without read permission on an object
- **WHEN** they call the available-actions endpoint for it
- **THEN** the call MUST be refused
- **AND** no field name from any transition MUST appear in the response

### Requirement: A lifecycle transition MAY fire automatically when its `autoWhen` rule holds after a write

A transition declared in `x-openregister-lifecycle.transitions` MAY carry an optional `autoWhen`, whose
value is a JSONLogic rule object. After an object is created or updated through the object save path,
OpenRegister SHALL look for a transition whose `from` contains the object's current lifecycle value and
whose `autoWhen` holds for the object as written. When exactly one such transition exists, OpenRegister
SHALL fire it as a named transition, exactly as `POST /api/objects/{id}/transition` would with that
transition's name and no payload.

A transition whose `to` equals the object's current lifecycle value SHALL NOT fire automatically, because
the move would change nothing and would repeat on every write.

A transition WITHOUT an `autoWhen` key SHALL behave exactly as before and SHALL never fire on its own.
The key is additive and never required.

A write made inside a system operation (a configuration import, a repair step, seeding) dispatches no
object events, and SHALL therefore fire no automatic transition. Seeding is not a user action, and an
automatic move on seed data has no one to act as.

#### Scenario: An automatic transition fires after an update and the response carries the new state
- **GIVEN** a transition `beslissen` with `from: ["in-behandeling"]`, `to: "besloten"` and `autoWhen: { "!!": { "var": "object.motivering" } }`
- **AND** an object in state `in-behandeling` without a `motivering`
- **WHEN** the object is updated through an ordinary object save that sets a non-empty `motivering`
- **THEN** the response to that save MUST carry the lifecycle value `besloten`
- **AND** a fresh read of the object MUST return `besloten`

#### Scenario: An automatic transition fires after a create
- **GIVEN** the same transition and a schema whose declared `initial` state is `in-behandeling`
- **WHEN** an object is created carrying a non-empty `motivering`
- **THEN** the response to the create MUST carry the lifecycle value `besloten`

#### Scenario: An automatic transition does not fire while its rule does not hold
- **GIVEN** the same transition
- **WHEN** an object in state `in-behandeling` is updated without a `motivering`
- **THEN** the response MUST carry the lifecycle value `in-behandeling`
- **AND** no transition MUST be applied

#### Scenario: A transition without autoWhen never fires automatically
@e2e exclude regression guard on an unchanged path, covered by the existing lifecycle PHPUnit suite
- **GIVEN** a transition declaring no `autoWhen`
- **WHEN** an object in its `from` state is saved
- **THEN** no transition MUST be applied and the lifecycle value MUST be unchanged

#### Scenario: A move to the current value is never fired
@e2e exclude candidate selection is internal, covered by PHPUnit
- **GIVEN** a transition whose `from` contains its own `to`, such as `from: ["ontvangen", "in-behandeling"]`, `to: "in-behandeling"`, with an `autoWhen` that holds
- **WHEN** an object in state `in-behandeling` is saved
- **THEN** that transition MUST NOT fire

#### Scenario: A write inside a system operation fires no automatic transition
@e2e exclude system operations have no HTTP surface, covered by PHPUnit
- **GIVEN** a transition whose `autoWhen` holds
- **WHEN** the object is written inside a system operation
- **THEN** no automatic transition MUST be applied

### Requirement: An automatic transition MUST obey every gate a manual transition obeys

An automatic transition SHALL be applied through the same path as a named transition, so every rule
that can refuse a manual transition SHALL be able to refuse the automatic one, in the same order: the
object `update` permission, the `from` check, the `authorization` list, the `condition`, the `requires`
guard, and any approval-chain gate. When the transition is applied, its declared `actions[]` SHALL run
exactly as they do for a manual transition.

When any of those gates refuses the automatic transition, OpenRegister SHALL NOT apply it, SHALL leave
the object in the state the triggering write left it in, and SHALL log the refusal at warning level
naming the schema, the object, the transition and the refusal code. The refusal SHALL NOT be retried,
SHALL NOT be surfaced to the caller as an error, and SHALL NOT affect the triggering write. A later
write that finds the rule still holding SHALL try again.

A transition declaring a required input cannot be fired automatically, because an automatic move
carries no payload. That declaration is refused at schema-save time; see the validation requirement.

#### Scenario: An automatic transition refused by its condition leaves the triggering write intact
- **GIVEN** a transition with an `autoWhen` that holds and a `condition` that does not hold
- **WHEN** an object in its `from` state is saved with other field changes
- **THEN** the save MUST succeed and its field changes MUST be stored
- **AND** the response MUST carry the unchanged lifecycle value
- **AND** no transition MUST be applied

#### Scenario: An automatic transition refused by its authorization list is not applied
@e2e exclude needs a second non-admin principal per run, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds and an `authorization` list naming a group the saving user is not in
- **WHEN** that user saves the object
- **THEN** the save MUST succeed and the transition MUST NOT be applied
- **AND** a warning MUST be logged carrying the code `lifecycle-transition-unauthorized`

#### Scenario: A denying guard stops the automatic transition
@e2e exclude guard resolution is backend wiring, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds and a `requires` guard that denies
- **WHEN** the object is saved
- **THEN** the transition MUST NOT be applied and a warning MUST be logged carrying the code `lifecycle-guard-denied`

#### Scenario: An automatic transition runs its declared actions
@e2e exclude action handlers are backend wiring, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds and a declared `actions[]` entry
- **WHEN** the automatic transition is applied
- **THEN** that action MUST run exactly as it runs for the same transition fired by name

### Requirement: A sync automatic transition MUST be applied after the triggering write completes and MUST NOT unwind it

A `sync` automatic transition SHALL be applied in the same request as the write that triggered it, and
SHALL be applied only after that write is complete: after its object row, its audit row, and any
follow-up write the same save makes to the same object. It SHALL be applied before the response is
produced, so the response of the triggering request SHALL carry the lifecycle value the automatic
transition reached. This holds for both routes that write an object: an ordinary object save, and a
named transition whose save makes a further automatic transition hold.

The triggering write SHALL NOT be unwound, failed or delayed in its commit by anything the automatic
transition does. An exception raised while applying it SHALL be caught and logged at warning level; the
triggering request SHALL answer as it would have without the automatic transition.

For a named transition that triggers an automatic one, the `ObjectTransitionedEvent` of the named
transition SHALL be dispatched before that of the automatic transition, so a listener sees the moves in
the order they happened.

#### Scenario: The named-transition route returns the state an automatic transition reached
- **GIVEN** a manual transition `indienen` from `concept` to `ingediend`, and a transition `beoordelen` from `ingediend` to `in-beoordeling` whose `autoWhen` holds
- **WHEN** `POST /api/objects/{id}/transition` is called with action `indienen`
- **THEN** the response MUST carry the lifecycle value `in-beoordeling`

#### Scenario: The triggering write's audit row precedes the automatic one
@e2e exclude audit ordering is a backend invariant, covered by PHPUnit
- **GIVEN** a save that makes an automatic transition hold
- **WHEN** both writes have been applied
- **THEN** the audit row of the triggering save MUST precede the audit row of the automatic transition

#### Scenario: A save that also writes file properties keeps the automatic move
@e2e exclude the stale second write is internal to the save pipeline, covered by PHPUnit
- **GIVEN** a save carrying a file property that makes an automatic transition hold
- **WHEN** the save and the automatic transition have been applied
- **THEN** the stored lifecycle value MUST be the one the automatic transition reached

#### Scenario: The named transition's event precedes the automatic transition's event
@e2e exclude event order is a backend invariant, covered by PHPUnit
- **GIVEN** a named transition whose save makes an automatic transition hold
- **WHEN** both have been applied
- **THEN** `ObjectTransitionedEvent` for the named transition MUST be dispatched before `ObjectTransitionedEvent` for the automatic one

#### Scenario: An exception while applying an automatic transition does not fail the request
@e2e exclude provoking an engine exception needs a broken fixture, covered by PHPUnit
- **GIVEN** a transition with an `autoWhen` that holds, whose application raises an exception
- **WHEN** the object is saved
- **THEN** the save MUST answer with the status it would have had without the automatic transition
- **AND** the exception MUST be logged at warning level naming the transition

### Requirement: `executionMode` selects sync or async, using the flow engine's two values

@e2e exclude background job execution is not browser-observable, covered by PHPUnit

A transition declaring `autoWhen` MAY also declare `executionMode`. Its value SHALL be exactly one of the
two values the flow engine already defines for the same question, `sync` and `async`, and OpenRegister
SHALL compare against those definitions rather than spelling the strings anew. When `executionMode` is
absent, the transition SHALL run `sync`.

An `async` automatic transition SHALL be decided at the moment its `sync` counterpart would be applied,
using the same document, and SHALL then be queued and applied off-request. The queued move SHALL be
applied only when the object has not been written since the decision. When it has, the queued move
SHALL be dropped silently, because the newer write made its own decision.

A write made outside any request-scoped save, such as a bulk save, a deferred create event or a revert,
has no point at which a `sync` move can be applied after the write completes and before a response. Its
automatic transitions SHALL be queued as `async`, whatever their declared mode.

When the instance's listener-deferral kill switch is set to run deferred work inline, an `async`
automatic transition decided inside a request-scoped save SHALL be applied where a `sync` one would be.
A move decided outside a request-scoped save SHALL still be queued, because there is no safe inline
point for it.

#### Scenario: An absent executionMode runs sync
- **GIVEN** a transition declaring `autoWhen` and no `executionMode`
- **WHEN** its rule holds after a save
- **THEN** it MUST be applied before the response is produced

#### Scenario: An async automatic transition is applied off-request
- **GIVEN** a transition declaring `autoWhen` and `executionMode: "async"` whose rule holds after a save
- **WHEN** the save's response is produced
- **THEN** the response MUST carry the unchanged lifecycle value
- **AND** once the queued move is processed, the object MUST carry the transition's `to` value

#### Scenario: A queued move is dropped when the object changed in between
- **GIVEN** a queued `async` automatic transition
- **AND** a later write to the same object before the queue is processed
- **WHEN** the queued move is processed
- **THEN** it MUST NOT be applied

#### Scenario: A write outside a request-scoped save queues its automatic transitions
- **GIVEN** a `sync` transition whose rule holds after a bulk save
- **WHEN** the bulk save completes
- **THEN** the automatic transition MUST be queued and applied off-request

#### Scenario: An unrecognised stored executionMode does not fire
- **GIVEN** a stored transition whose `executionMode` is neither `sync` nor `async`
- **WHEN** its rule holds after a save
- **THEN** it MUST NOT fire and a warning MUST be logged naming the transition

### Requirement: Automatic transitions MUST be bounded by a loop cap that logs its breach

An automatic transition's own write is a write, so it can make another automatic transition hold. A
pass is the work started by one write and everything it triggers automatically. Within a pass,
OpenRegister SHALL keep applying automatic transitions one at a time, re-deciding after each, until no
rule holds or the pass is cut.

A pass SHALL be cut, for the object concerned, when either limit is reached:

- **Revisit.** A move whose `to` is a state the object already occupied in this pass, including the
  state it started in, SHALL NOT be made.
- **Ceiling.** At most 10 automatic transitions SHALL be applied to one object in one pass.

A cut SHALL be logged at error level, naming the schema, the object, the limit reached and the sequence
of transitions applied so far. The cut SHALL NOT raise an exception and SHALL NOT recurse. The ceiling
is a fixed constant of the engine and SHALL NOT be configurable per schema or per instance.

A pass SHALL survive an `async` hop: a queued move SHALL carry the pass's count and visited states, and
the job applying it SHALL continue the same pass rather than start a new one. A loop SHALL NOT escape
the cap by crossing into a background job.

#### Scenario: A chain of automatic transitions continues in the same pass
- **GIVEN** transitions `a` from `stap-1` to `stap-2` and `b` from `stap-2` to `stap-3`, both `sync` with an `autoWhen` that holds
- **WHEN** an object in `stap-1` is saved
- **THEN** the response MUST carry the lifecycle value `stap-3`

#### Scenario: A ping-pong between two states stops at the first revisit
- **GIVEN** a transition from `heen` to `terug` and a transition from `terug` to `heen`, both with an `autoWhen` that always holds
- **WHEN** an object in `heen` is saved
- **THEN** exactly one automatic transition MUST be applied
- **AND** the response MUST carry the lifecycle value `terug`

#### Scenario: A chain longer than the ceiling stops at ten moves
- **GIVEN** twelve states `s0` to `s11` and eleven transitions each moving one state forward, all with an `autoWhen` that always holds
- **WHEN** an object in `s0` is saved
- **THEN** exactly ten automatic transitions MUST be applied
- **AND** the response MUST carry the lifecycle value `s10`

#### Scenario: A cut is logged at error level with the chain
@e2e exclude log output is not browser-observable, covered by PHPUnit
- **GIVEN** a pass cut by either limit
- **WHEN** the cut happens
- **THEN** one error-level log line MUST name the schema, the object, the limit and the transitions applied

#### Scenario: The count travels with an async hop
@e2e exclude background job execution, covered by PHPUnit
- **GIVEN** an `async` automatic transition queued after nine automatic transitions in one pass
- **WHEN** the job applies it and a further rule holds
- **THEN** the job MUST apply at most one more move and then cut the pass

### Requirement: The `autoWhen` document is the condition document, read when the move is decided

@e2e exclude document construction is internal, covered by PHPUnit

An `autoWhen` SHALL be evaluated against the same four top-level keys a transition `condition` reads,
and against no others:

- `object`: the object as the triggering write stored it, so its lifecycle field holds the transition's
  `from` value;
- `previous`: the object as it was before the triggering write, or an empty object when that write
  created it;
- `user`: the identity the automatic transition acts as, as `uid` and `groups`, with an empty string and
  an empty list when there is none;
- `transition`: the candidate transition, as `action`, `from` (the current value) and `to`.

The keys are shared with `condition` and their meaning differs in one place an author must know: in an
`autoWhen`, `object` holds the state being LEFT, whereas in a `condition` it holds the state being
ENTERED. An `autoWhen` is decided once per candidate per step of the pass, after the triggering write
is complete, so it reads computed fields and defaults as stored.

A reference to a key the document does not carry SHALL resolve to null. An expression that cannot be
evaluated SHALL count as not holding, so an unevaluable rule never moves an object.

#### Scenario: The rule reads the object as stored
- **GIVEN** an `autoWhen` referencing `object.motivering`
- **WHEN** it is evaluated after a save that stored a `motivering`
- **THEN** the reference MUST resolve to the stored value

#### Scenario: The previous state is empty after a create
- **GIVEN** an `autoWhen` referencing `previous.status`
- **WHEN** it is evaluated after the object was created
- **THEN** the reference MUST resolve to null

#### Scenario: The transition key names the candidate
- **GIVEN** a candidate transition `beslissen` from `in-behandeling` to `besloten`
- **WHEN** its `autoWhen` is evaluated
- **THEN** `transition.action`, `transition.from` and `transition.to` MUST resolve to `beslissen`, `in-behandeling` and `besloten`

#### Scenario: An unevaluable rule does not move the object
- **GIVEN** a stored `autoWhen` that cannot be evaluated for the object at hand
- **WHEN** the object is saved
- **THEN** the transition MUST NOT fire

### Requirement: An ambiguous or shadowed automatic transition MUST NOT fire

@e2e exclude candidate selection is internal, covered by PHPUnit

When more than one transition whose `from` contains the current value has an `autoWhen` that holds,
OpenRegister SHALL fire none of them and SHALL log a warning naming every candidate. Declaration order
SHALL NOT be used to choose, because it is not preserved by every database Nextcloud supports.

A transition SHALL NOT fire automatically when an earlier-declared transition has the same `to` and a
`from` that also contains the current value. The save path identifies a transition by its from and to
values and takes the first match, so it would enforce the earlier transition's gates and run its
actions, not the automatic transition's. OpenRegister SHALL log a warning naming both transitions.

#### Scenario: Two rules that hold at once fire nothing
- **GIVEN** two transitions from `ontvangen`, each with an `autoWhen` that holds
- **WHEN** an object in `ontvangen` is saved
- **THEN** neither transition MUST be applied
- **AND** one warning MUST name both transitions

#### Scenario: One rule that holds beside one that does not fires
- **GIVEN** two transitions from `ontvangen`, of which only one has an `autoWhen` that holds
- **WHEN** an object in `ontvangen` is saved
- **THEN** exactly that transition MUST be applied

#### Scenario: A shadowed automatic transition does not fire
- **GIVEN** a transition `toewijzen` from `ontvangen` to `in-behandeling` with an `autoWhen` that holds
- **AND** an earlier-declared transition `starten` from `ontvangen` to `in-behandeling`
- **WHEN** an object in `ontvangen` is saved
- **THEN** `toewijzen` MUST NOT fire
- **AND** a warning MUST name both transitions

### Requirement: An automatic transition acts as the caller whose write triggered it

@e2e exclude identity plumbing, covered by PHPUnit

A `sync` automatic transition SHALL act as the identity that made the triggering write. Its
`authorization` list, its object `update` permission, the `user` key of its `condition` and `autoWhen`,
its guard's user id and its audit row SHALL all see that identity.

An `async` automatic transition SHALL carry that identity into the queued move and act as it when
applied. When the identity no longer resolves to an account, or resolves to a disabled one, the queued
move SHALL NOT be applied and a warning SHALL be logged.

An automatic transition SHALL NEVER act as a system principal or run with elevated rights. When the
triggering write had no identity, such as a CLI command without a session, the automatic transition
SHALL act as no one, and any gate that requires an identity SHALL refuse it.

#### Scenario: A sync move is authorized as the saving user
- **GIVEN** a transition with an `autoWhen` that holds and an `authorization` list naming group `behandelaars`
- **WHEN** a member of `behandelaars` saves the object
- **THEN** the transition MUST be applied and its audit row MUST name that user

#### Scenario: A queued move acts as the captured user
- **GIVEN** an `async` automatic transition decided on a save by user `behandelaar-1`
- **WHEN** the queued move is applied
- **THEN** it MUST be authorized and audited as `behandelaar-1`

#### Scenario: A queued move for a disabled account is not applied
- **GIVEN** an `async` automatic transition decided on a save by a user who is disabled before the move is processed
- **WHEN** the queued move is processed
- **THEN** it MUST NOT be applied and a warning MUST be logged

#### Scenario: A session-less write gets no elevated automatic move
- **GIVEN** a transition with an `autoWhen` that holds and an `authorization` list
- **WHEN** the object is saved by a caller with no session
- **THEN** the automatic transition MUST be refused and MUST NOT be applied

### Requirement: An automatic transition MUST be recorded as automatic

@e2e exclude event and audit fields are backend contracts, covered by PHPUnit

The `ObjectTransitionedEvent` of an automatic transition SHALL say it was automatic; the event for a
manual transition SHALL say it was not. The flag SHALL be additive and default to not automatic, so
every existing listener and dispatcher is unaffected. A flow triggered by the transition SHALL see the
flag on its run context beside `action`, `from` and `to`.

The audit row of an automatic transition SHALL identify the move as automatic and name the transition,
while attributing it to the identity it acted as. An auditor SHALL be able to tell a move a user asked
for from a move a rule made on that user's write.

#### Scenario: The transition event carries the automatic flag
- **GIVEN** an automatic transition that is applied
- **WHEN** its `ObjectTransitionedEvent` is dispatched
- **THEN** the event MUST report that the transition was automatic
- **AND** the event for the same transition fired by name MUST report that it was not

#### Scenario: A flow sees the automatic flag
- **GIVEN** a flow wired to the object state-change trigger
- **WHEN** an automatic transition fires it
- **THEN** the run context MUST carry the automatic flag

#### Scenario: The audit row marks the automatic move
- **GIVEN** an automatic transition that is applied on a save by user `behandelaar-1`
- **WHEN** its audit row is read
- **THEN** the row MUST identify the move as automatic, name the transition, and name `behandelaar-1`

### Requirement: A malformed or unsupported automatic transition MUST be refused at schema-save time

@e2e exclude schema-save validation, covered by PHPUnit

At schema-save time OpenRegister SHALL validate every transition that declares `autoWhen` or
`executionMode`, and SHALL refuse the schema save, not merely warn, when any of these holds:

- `autoWhen` is not a non-empty JSONLogic rule object, or is a rule object the expression engine cannot
  validate: code `lifecycle-autowhen-malformed`. A scalar, including `true` and a string in the
  `@self.<field> == '<value>'` form an `actions[]` entry uses, SHALL be refused with this code, because
  a scalar evaluates as a truthy literal and would fire on every write from that state.
- `executionMode` is present and is not exactly `sync` or `async`: code
  `lifecycle-execution-mode-malformed`. Case variants SHALL be refused, not normalised.
- A transition declaring `autoWhen` also declares an `inputs` entry with `required: true`: code
  `lifecycle-autowhen-requires-input`, because an automatic move carries no payload and would be refused
  on every attempt.
- A graph-mode `graph` block carries `autoWhen`: code `lifecycle-autowhen-graph-unsupported`, stating
  that graph-mode automatic transitions are not supported while graph-mode moves are unenforced on the
  ordinary save path.

Each error SHALL name the schema and the offending transition. These errors SHALL refuse the save
exactly as `lifecycle-condition-malformed` does. Every other lifecycle error SHALL keep its existing
advisory treatment.

At runtime the evaluation SHALL NOT trust save-time validation: a stored `autoWhen` that is present but
not a non-empty rule object SHALL count as not holding and SHALL be logged at warning level.

Every test covering this requirement and the loop cap SHALL be proven to fail against a deliberately
broken guard before it is accepted.

#### Scenario: A scalar autoWhen is refused
- **GIVEN** a transition declaring `autoWhen: true`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-malformed` naming the transition

#### Scenario: An action-dialect string is refused as autoWhen
- **GIVEN** a transition declaring `autoWhen: "@self.motivering != ''"`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-malformed`
- **AND** the message MUST point the author at the JSONLogic rule-object form

#### Scenario: An unknown operator is refused
- **GIVEN** a transition declaring an `autoWhen` rule object with an operator the expression engine does not know
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-malformed`

#### Scenario: An unknown executionMode is refused
- **GIVEN** a transition declaring `executionMode: "background"`, and another declaring `executionMode: "SYNC"`
- **WHEN** the schema is saved
- **THEN** each MUST be refused with code `lifecycle-execution-mode-malformed`

#### Scenario: An automatic transition with a required input is refused
- **GIVEN** a transition declaring `autoWhen` and `inputs: [{ "field": "motivering", "required": true }]`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-requires-input`

#### Scenario: autoWhen on a graph block is refused
- **GIVEN** an annotation declaring a `graph` block that carries `autoWhen`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-autowhen-graph-unsupported`

#### Scenario: A well-formed declaration passes
- **GIVEN** a transition declaring `autoWhen: { "!!": { "var": "object.motivering" } }` and `executionMode: "async"`
- **WHEN** the schema is saved
- **THEN** no automatic-transition error MUST be returned and the annotation's other rules MUST be unaffected

#### Scenario: A malformed message stays advisory
- **GIVEN** a transition declaring a valid `autoWhen` and a malformed `message`
- **WHEN** the schema is saved
- **THEN** the save MUST succeed with the existing `lifecycle-message-malformed` warning

#### Scenario: A stored malformed autoWhen does not fire
- **GIVEN** a stored transition whose `autoWhen` is a scalar, written by a path that skipped validation
- **WHEN** an object in its `from` state is saved
- **THEN** the transition MUST NOT fire and a warning MUST be logged

#### Scenario: The validation and cap tests are proven to fail first
- **GIVEN** the PHPUnit tests for this requirement and for the loop cap
- **WHEN** the scalar refusal, the revisit rule and the ceiling are each deliberately removed
- **THEN** the test guarding each MUST fail for that reason before it is restored

### Requirement: A lifecycle transition MAY declare a declarative condition that gates it

@e2e exclude backend lifecycle listener — covered by PHPUnit

A transition declared in `x-openregister-lifecycle.transitions` MAY carry an
optional `condition`, whose value is a JSONLogic rule object, and an optional
`message`. A `message` SHALL be either a non-empty string or a per-locale map
(`{"nl": "…", "en": "…"}`, optionally carrying `defaultLocale`), which is the
same shape `x-openregister-notifications` already uses for its `subject` and
`message`. Per ADR-007 a map SHOULD declare at least `nl` and `en`.

When a matched transition declares a `condition`, OpenRegister SHALL evaluate it
on the `ObjectService::saveObject()` path before the write. When the condition
holds, the transition SHALL proceed exactly as it does without one. When the
condition does not hold, the save SHALL be refused with the structured error
code `lifecycle-condition-unmet`, the lifecycle field SHALL NOT be mutated, and
no object write SHALL occur.

The refusal SHALL carry the transition's `message` when one is declared. A
string `message` SHALL be used verbatim. A map `message` SHALL be resolved to
the caller's language as reported by `IL10N::getLanguageCode()`, falling back to
the map's `defaultLocale`, then to `en`, then to the first declared locale. The
language SHALL come from `IL10N` rather than from `Accept-Language` negotiation,
so the message follows the user's configured language. An author's `message` SHALL pass through
untouched and SHALL NOT be translated by the engine. When a transition declares
no `message`, the refusal SHALL carry the engine's own generic message naming
the transition action and the lifecycle field, and that message SHALL be
translated through the app's translation layer. The refusal SHALL NOT expose the
expression itself to the caller.

The gate SHALL be reached by BOTH transition routes, because both converge on
the same save path: the named-action route (`POST /api/objects/{id}/transition`)
and a direct edit of the lifecycle field through an ordinary object save. A
transition WITHOUT a `condition` key SHALL behave exactly as before; the key is
additive and never required.

#### Scenario: A condition that holds lets the transition through
- **GIVEN** a transition `beslissen` with `from: ["in-behandeling"], to: "besloten"` and `condition: { "!!": { "var": "object.motivering" } }`
- **AND** an object whose `motivering` is a non-empty string
- **WHEN** the object's lifecycle field is saved as `besloten`
- **THEN** the transition MUST be applied and no condition error MUST be raised

#### Scenario: A condition that does not hold refuses the save
- **GIVEN** the same transition
- **AND** an object whose `motivering` is absent or empty
- **WHEN** the transition is attempted
- **THEN** the save MUST be refused with the structured error code `lifecycle-condition-unmet`
- **AND** the lifecycle field MUST retain its previous value
- **AND** no object write MUST occur

#### Scenario: The declared message is what the caller sees
- **GIVEN** a transition declaring `message: "Een besluit vereist een motivering."` whose condition does not hold
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry that message verbatim
- **AND** the refusal MUST NOT contain the JSONLogic expression

#### Scenario: A per-locale message is resolved to the caller's language
- **GIVEN** a transition declaring `message: { "nl": "Een besluit vereist een motivering.", "en": "A decision requires a motivation." }` whose condition does not hold
- **AND** a caller whose language is `nl`
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry the `nl` string verbatim
- **AND** the same refusal for a caller whose language is `de`, which the map does not declare, MUST fall back to `defaultLocale` when declared, otherwise to `en`, otherwise to the first declared locale

#### Scenario: A refused condition without a message still names the transition
- **GIVEN** a transition declaring a `condition` and no `message` whose condition does not hold
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-condition-unmet` and the engine's generic message naming the transition action and the lifecycle field
- **AND** that generic message MUST be produced through the app's translation layer, not as an untranslated literal

#### Scenario: The named-action route is gated identically to a direct field edit
- **GIVEN** a transition whose condition does not hold
- **WHEN** the transition is requested through `POST /api/objects/{id}/transition`
- **THEN** the request MUST be answered with HTTP 422 and the code `lifecycle-condition-unmet`
- **AND** the same attempt made by editing the lifecycle field through an ordinary object save MUST be refused with the same code

#### Scenario: A transition without a condition is unaffected
- **GIVEN** a transition declaring no `condition` key
- **WHEN** an otherwise valid transition is attempted
- **THEN** no condition MUST be evaluated and the transition MUST proceed

### Requirement: The condition data document exposes the object, its previous state, the caller and the transition

@e2e exclude backend lifecycle listener — covered by PHPUnit

A lifecycle condition SHALL be evaluated against a data document with exactly
four top-level keys:

- `object`: the object data as it would be written, including the new lifecycle
  field value;
- `previous`: the stored object data as it is before the write, including the
  old lifecycle field value;
- `user`: the acting caller, as `uid` (an empty string when there is no session
  user) and `groups` (the caller's Nextcloud group ids, an empty list when there
  is no session user);
- `transition`: the matched transition, as `action` (its declared name), `from`
  (the old lifecycle value) and `to` (the new lifecycle value).

The document SHALL NOT carry the flow engine's `json`, `binary`, `itemIndex`,
`itemCount`, `context` or `subject` keys: a schema author writing a lifecycle
rule is looking at an object, not at a flow item. A reference to a key that the
document does not carry SHALL resolve to null and therefore refuse the
transition, per the fail-closed rule below.

#### Scenario: A condition reads a field of the object being written
- **GIVEN** a condition `{ "!!": { "var": "object.motivering" } }`
- **WHEN** the transition is evaluated for an object carrying a non-empty `motivering`
- **THEN** the condition MUST hold

#### Scenario: A condition compares the new value against the previous one
- **GIVEN** a condition referencing `previous.bedrag` and `object.bedrag`
- **WHEN** the transition is evaluated
- **THEN** `previous.bedrag` MUST resolve to the stored value and `object.bedrag` to the value being written

#### Scenario: A condition reads the caller's group membership
- **GIVEN** a condition `{ "in": ["vergunningverleners", { "var": "user.groups" }] }`
- **AND** a caller belonging to that Nextcloud group
- **WHEN** the transition is evaluated
- **THEN** the condition MUST hold
- **AND** for a caller with no session, `user.uid` MUST be an empty string and `user.groups` MUST be an empty list

#### Scenario: A condition reads the matched transition
- **GIVEN** a condition referencing `transition.action`, `transition.from` and `transition.to`
- **WHEN** the transition `beslissen` moves `in-behandeling` to `besloten`
- **THEN** those keys MUST resolve to `beslissen`, `in-behandeling` and `besloten` respectively

#### Scenario: A flow-shaped reference does not resolve
- **GIVEN** a condition referencing `json.motivering`
- **WHEN** the transition is evaluated
- **THEN** the reference MUST resolve to null and the transition MUST be refused with `lifecycle-condition-unmet`

### Requirement: The condition is evaluated after the authorization gate and before the requires guard

@e2e exclude backend lifecycle listener — covered by PHPUnit

For a matched transition, OpenRegister SHALL evaluate the declarative gates in a
fixed order: the `authorization` list first, then the `condition`, then the
`requires` guard. A failure at any stage SHALL refuse the save immediately and
SHALL NOT evaluate any later stage.

The ordering is normative, not incidental. A `requires` guard is app code that
may read external state, write a log line or take a lock, so a caller whose
precondition is not met MUST be refused before any such side channel runs, for
the same reason `authorization` is already evaluated before `requires`.

#### Scenario: An unauthorized caller is refused before the condition runs
- **GIVEN** a transition declaring both a non-empty `authorization` list and a `condition`
- **AND** a caller who satisfies neither
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-transition-unauthorized`
- **AND** the condition MUST NOT be evaluated

#### Scenario: An unmet condition is refused before the guard is resolved
- **GIVEN** a transition declaring both a `condition` that does not hold and a `requires` guard tag
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-condition-unmet`
- **AND** the guard MUST NOT be resolved and its `check()` MUST NOT be invoked

#### Scenario: A met condition still runs the guard
- **GIVEN** a transition declaring a `condition` that holds and a `requires` guard that denies
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-guard-denied` with the guard's message

### Requirement: A malformed condition MUST be refused at schema-save time and MUST never fail open at runtime

@e2e exclude backend lifecycle listener — covered by PHPUnit

An expression that cannot be evaluated is treated as FALSE by OpenRegister's
expression engine. For a blocking condition, that turns an author's typo into a
permanent, silent, unexplained refusal of that transition, with no error raised
anywhere and nothing in the schema to look at. The engine MUST therefore refuse
such an expression at the moment it is authored.

At schema-save time, when a transition declares a `condition`, OpenRegister
SHALL validate it and SHALL return the structured error `lifecycle-condition-malformed`
when it is not a well-formed expression, naming the offending transition. A
transition-level `condition` SHALL be a JSONLogic rule object; a scalar,
including a string in the `@self.<field> == '<value>'` form that a transition's
`actions[]` entries use, SHALL be refused with the same code, because such a
string would evaluate as a truthy literal and silently authorize every
transition it was meant to gate. When a
transition declares a `message` that is neither a non-empty string nor a
per-locale map carrying at least one locale key with a non-empty string value,
OpenRegister SHALL return `lifecycle-message-malformed`; a `defaultLocale` that
names a locale the map does not declare SHALL be refused with the same code.
Every malformed `message` shape SHALL return that one code, so an author has a
single canonical error to look up, exactly as the notification dialect returns
one code for a broken body template. Both errors SHALL be
collected and returned alongside the annotation's other validation errors rather
than thrown, and SHALL cause the schema save to be refused, exactly as every
other lifecycle annotation error does.

At runtime the evaluation SHALL be fail-closed: an expression that cannot be
evaluated SHALL refuse the transition with `lifecycle-condition-unmet` and SHALL
NOT allow it. Save-time validation is what keeps that runtime rule from ever
firing on a stored schema; it is not a substitute for it.

Every test covering this requirement SHALL be proven to fail against a
deliberately broken condition before it is accepted. A test that asserts only
the passing case is green whether or not the engine refuses malformed
expressions, and therefore proves nothing about this requirement.

#### Scenario: A malformed condition is refused when the schema is saved
- **GIVEN** a transition declaring a `condition` that is not a well-formed expression, such as an unknown operator
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with an error carrying code `lifecycle-condition-malformed` and naming the transition
- **AND** the malformed expression MUST NOT be stored

#### Scenario: A well-formed condition passes save-time validation
- **GIVEN** a transition declaring `condition: { "!!": { "var": "object.motivering" } }`
- **WHEN** the schema is saved
- **THEN** no condition error MUST be returned
- **AND** the annotation's other validation rules MUST be unaffected

#### Scenario: An action-dialect condition string on a transition is refused
- **GIVEN** a transition declaring `condition: "@self.settlementMode == 'reimbursable'"`, the string form used by an action envelope one level deeper
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with `lifecycle-condition-malformed` naming the transition
- **AND** the message MUST point the author at the JSONLogic rule-object form
- **AND** the `condition` an entry of `actions[]` declares MUST keep its existing string dialect and its existing behaviour, unchanged

#### Scenario: A malformed message is refused at schema-save time
- **GIVEN** a transition declaring a `message` that is a number, an empty string, an empty map, a map whose only values are empty strings, or a map whose `defaultLocale` names an undeclared locale
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with an error carrying code `lifecycle-message-malformed`

#### Scenario: Both message shapes pass save-time validation
- **GIVEN** one transition declaring `message: "Een besluit vereist een motivering."` and another declaring `message: { "nl": "…", "en": "…" }`
- **WHEN** the schema is saved
- **THEN** neither MUST produce a `lifecycle-message-malformed` error

#### Scenario: A condition that cannot be evaluated at runtime refuses the transition
- **GIVEN** a stored transition whose condition cannot be evaluated for the object at hand
- **WHEN** the transition is attempted
- **THEN** the save MUST be refused with `lifecycle-condition-unmet`
- **AND** the transition MUST NOT be applied

#### Scenario: The malformed-condition test is proven to fail before it is accepted
- **GIVEN** the PHPUnit test asserting that a malformed condition is refused at schema-save time
- **WHEN** the validation of the `condition` key is deliberately removed
- **THEN** that test MUST fail
- **AND** the same MUST hold for the runtime fail-closed test when the refusal is deliberately inverted

### Requirement: A condition on a graph-mode lifecycle MUST be refused, not partially enforced

@e2e exclude backend lifecycle listener — covered by PHPUnit

A graph-mode lifecycle declares a `graph` block and no `transitions` map, so
there is no per-transition object on which to declare a `condition`. The only
available shape is a `condition` (and `message`) on the `graph` block itself,
applying to every move the graph derives.

That shape SHALL NOT be enforced in this change, and OpenRegister SHALL refuse
it: when an annotation declares a `graph` block carrying a `condition`, the
schema save SHALL be refused with the structured error code
`lifecycle-condition-graph-unsupported`, naming the schema and stating that
graph-mode conditions are not yet enforced.

The refusal is the requirement, and the reason is enforcement coverage. A
graph-mode move is validated only inside `TransitionEngine`, which derives the
candidate set and rejects anything outside it. On the ordinary save path there
is no graph enforcement at all: `LifecycleValidationListener` reads
`transitions`, finds it empty for a graph-mode annotation, and returns without
validating anything. A condition accepted on the `graph` block could therefore
only ever hold on the named-action route and would be silently absent when the
same lifecycle field is written directly. A gate that holds on one route and not
the other is worse than no gate, because the author believes the state is
unreachable. OpenRegister already takes this posture for a declared action that
resolves to no handler: it fails loudly rather than skipping silently.

This requirement SHALL be replaced by the enforcing behaviour once graph-mode
moves are validated on the ordinary save path. Until then the refusal keeps the
gap visible to the author who would otherwise depend on it.

#### Scenario: A condition on a graph block is refused at schema-save time
- **GIVEN** an annotation declaring a `graph` block that carries a `condition`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-condition-graph-unsupported`
- **AND** the message MUST state that graph-mode conditions are not yet enforced

#### Scenario: A graph-mode annotation without a condition is unaffected
- **GIVEN** an annotation declaring a `graph` block and no `condition`
- **WHEN** the schema is saved
- **THEN** it MUST validate exactly as it does today, with no new error

#### Scenario: A static transition condition is unaffected by graph-mode refusal
- **GIVEN** a schema declaring both a non-empty `transitions` map with a `condition` and a `graph` block with no `condition`
- **WHEN** the schema is saved
- **THEN** no `lifecycle-condition-graph-unsupported` error MUST be raised
- **AND** the static transition's condition MUST be validated and enforced as specified above

### Requirement: A named transition MUST be judged by its own declaration, not by a same-pair twin

@e2e exclude backend lifecycle listener — covered by PHPUnit

When a caller performs a transition by name through `TransitionEngine`, the
lifecycle listeners MUST gate and act on THAT transition: its `authorization`,
`condition`, `requires` and `actions`. This holds even when an earlier declared
transition shares the same `from` and `to` values. `TransitionEngine` SHALL
declare the action on a shared, request-scoped context for the duration of its
save and SHALL release it afterwards, including when the save fails. A declared
action SHALL be honoured only when it genuinely moves the old value to the new
one; it MUST NEVER make an otherwise undeclared move legal. A direct edit of
the lifecycle field, which names no action, SHALL keep resolving to the first
declared transition matching the values.

#### Scenario: A named action is gated by its own condition
- **GIVEN** transitions `openen` and `beslissen`, both from `in-behandeling` to `besloten`, declared in that order, where only `beslissen` declares a condition
- **WHEN** `beslissen` is performed by name and its condition does not hold
- **THEN** the save MUST be refused with `lifecycle-condition-unmet` naming `beslissen`

#### Scenario: A direct edit keeps first-match resolution
- **GIVEN** the same two transitions
- **WHEN** the lifecycle field is edited directly from `in-behandeling` to `besloten`
- **THEN** the edit MUST be resolved as `openen` and pass

#### Scenario: A declared action cannot legalise a move
- **GIVEN** a declared action whose `to` differs from the attempted new value
- **WHEN** the change is resolved
- **THEN** the declaration MUST be ignored and resolution MUST fall back to matching by value

#### Scenario: The declaration does not outlive the save
- **GIVEN** a named transition whose save throws
- **WHEN** the exception leaves `TransitionEngine`
- **THEN** no action MUST remain declared for that object

### Requirement: Bulk delete MUST batch-resolve object scopes with a single cross-table lookup

`ObjectService::deleteObjects()` SHALL resolve the entity (and thereby the
register/schema scope) of every permission-filtered UUID with ONE batched
cross-magic-table lookup (soft-deleted rows included) before deleting, and SHALL
pass each pre-resolved entity together with its concrete Register and Schema
entities into the delete handler so no per-object cross-table re-scan runs.

Identifiers the uuid-based batch lookup cannot resolve (numeric ids, slugs, URIs,
rows deleted concurrently) SHALL fall back to the legacy per-uuid resolution and
delete-handler call, preserving prior behaviour including per-pair cache
invalidation and skip-on-error semantics. Referential-integrity enforcement
(RESTRICT, CASCADE, SET_NULL, SET_DEFAULT) remains per object.

#### Scenario: Batch-resolved UUIDs skip the per-object lookup
- **GIVEN** a bulk delete of N objects whose UUIDs all resolve in the batched lookup
- **WHEN** `deleteObjects()` runs
- **THEN** exactly one cross-magic-table lookup is issued for all N UUIDs
- **AND** the delete handler receives each pre-resolved entity with concrete
  register/schema entities and performs no additional lookup for it
- **AND** each distinct (register, schema) pair is materialised as entities at most once

#### Scenario: Batch misses keep the legacy pipeline
- **GIVEN** a bulk delete where one identifier is a slug the uuid-based batch cannot match
- **WHEN** `deleteObjects()` runs
- **THEN** that identifier is resolved and deleted through the unchanged legacy
  per-uuid path
- **AND** a RESTRICT block on any object skips only that object and the bulk
  operation continues

### Requirement: Legacy cascade deletion MUST batch each level's targets

`DeleteObject::cascadeDeleteObjects()` SHALL collect all ids referenced by
`cascade: true` schema properties first, resolve them with ONE batched
cross-table lookup, and soft-delete the resolved targets with one
`UPDATE ... WHERE uuid IN (...)` statement per magic table (per-row deletion
metadata bound via a parameterised CASE expression) and ONE multi-row audit
INSERT — instead of feeding each id through the full per-object delete pipeline.

Per-object semantics SHALL be preserved: an object-updating event is dispatched
per target before the write (a hook stopping propagation skips that target; a
hook modifying the payload routes that target through the full-row save), an
object-updated event is dispatched per target after the write, caches are
invalidated per object, and cascade children remain sub-deletions that never
cascade further. Unresolved ids and total batch-write failures fall back to the
legacy per-id pipeline. Soft delete remains the only cascade disposition.

#### Scenario: Cascade children are soft-deleted with batched statements
- **GIVEN** a root object whose schema has a `cascade: true` array property
  referencing M children stored in one magic table
- **WHEN** the root object is deleted
- **THEN** the children are resolved with one batched lookup and soft-deleted with
  one UPDATE statement carrying per-child deletion metadata
- **AND** M per-object updating and updated events are dispatched
- **AND** M audit rows are persisted with one multi-row INSERT

#### Scenario: Hook rejection skips only the rejected child
- **GIVEN** a cascade where a pre-update hook stops propagation for one child
- **WHEN** the batched soft delete runs
- **THEN** that child is not soft-deleted and receives no updated event
- **AND** the remaining children are soft-deleted normally

#### Scenario: Batch failure falls back to the per-id pipeline
- **GIVEN** the batched soft-delete write fails entirely
- **WHEN** the cascade continues
- **THEN** every collected id is retried through the legacy per-id delete pipeline

## Cross-References
- **rbac-scopes** — RBAC checks are applied by `PermissionHandler` at the start of every pipeline stage
- **schema-hooks** — schema hooks fire via event dispatcher after each successful save
- **audit-trail-immutable** — `AuditHandler` records every mutation as an immutable audit trail entry
- **linked-entity-types** — `RelationHandler` and `RelationCascadeHandler` resolve and cascade linked entity relations
- **faceting-configuration** — `FacetHandler` builds facet aggregations from queried object sets
- **zoeken-filteren** — `SearchQueryHandler` and `QueryHandler` translate search parameters into database queries

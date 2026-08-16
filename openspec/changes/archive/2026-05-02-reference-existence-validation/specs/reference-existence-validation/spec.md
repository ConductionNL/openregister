---
status: implemented
---

# reference-existence-validation Specification

## Purpose
Add configurable validation that ensures objects referenced via `$ref` properties actually exist before saving. When a schema property has `$ref` pointing to another schema and `validateReference` is enabled, the save pipeline checks that the UUID stored in that property corresponds to an existing object in the target schema. This spec covers the full lifecycle of reference existence checking: single-object saves, bulk imports, GraphQL mutations, soft-deleted reference handling, circular reference detection, external URL references, validation caching, configurable strictness, admin bypass, async batch validation, and event-driven notification of validation failures.

**Source**: Core OpenRegister data integrity capability. Ensures that `$ref` pointers between objects are valid at write time, complementing the referential-integrity spec which handles cascading behavior at delete time.

**Cross-references**: referential-integrity (delete-time enforcement), deletion-audit-trail (audit logging), content-versioning (version impact), bulk-object-operations (import pipeline), graphql-api (mutation validation).

## ADDED Requirements

### Requirement: Schema properties MUST support a validateReference configuration
Schema property definitions MUST accept a `validateReference` boolean flag that controls whether referenced object existence is checked on save. When not specified, it MUST default to `false` (eventual consistency pattern). The flag MUST be supported on both scalar `$ref` properties and array properties with `items.$ref`.

#### Scenario: Property with validateReference enabled
- GIVEN a schema `order` with property:
  ```json
  {
    "assignee": {
      "type": "string",
      "$ref": "person-schema-id",
      "validateReference": true
    }
  }
  ```
- WHEN an object is saved with `assignee` = `"existing-person-uuid"`
- AND a person object with UUID `"existing-person-uuid"` exists in the referenced schema
- THEN the save MUST succeed

#### Scenario: Property with validateReference disabled (default)
- GIVEN a schema `order` with property:
  ```json
  {
    "assignee": {
      "type": "string",
      "$ref": "person-schema-id"
    }
  }
  ```
- WHEN an object is saved with `assignee` = `"nonexistent-uuid"`
- THEN the save MUST succeed (no existence check performed)
- AND `validateReference` defaults to `false` when not specified

### Requirement: Save MUST reject objects with invalid references when validateReference is enabled
When `validateReference` is `true`, the save pipeline MUST verify that the referenced UUID exists in the target schema. The check MUST use `MagicMapper::find()` with `_rbac: false` and `_multitenancy: false` to ensure system-level validation regardless of the current user's permissions. Non-existence errors (database errors) MUST be logged as warnings but MUST NOT block the save.

#### Scenario: Single-value reference to nonexistent object
- GIVEN a schema with `validateReference: true` on property `assignee` referencing schema `person`
- WHEN an object is saved with `assignee` = `"nonexistent-uuid"`
- AND no person object with UUID `"nonexistent-uuid"` exists
- THEN the save MUST fail with HTTP 422
- AND the error message MUST include the property name, the invalid UUID, and the target schema name
- AND the error message format MUST be: `"Referenced object 'nonexistent-uuid' not found in schema 'person' for property 'assignee'"`

#### Scenario: Array reference with one invalid UUID
- GIVEN a schema with property:
  ```json
  {
    "members": {
      "type": "array",
      "items": {
        "type": "string",
        "$ref": "person-schema-id"
      },
      "validateReference": true
    }
  }
  ```
- WHEN an object is saved with `members` = `["valid-uuid-1", "nonexistent-uuid", "valid-uuid-2"]`
- AND `valid-uuid-1` and `valid-uuid-2` exist but `nonexistent-uuid` does not
- THEN the save MUST fail with HTTP 422
- AND the error message MUST identify `nonexistent-uuid` as the invalid reference

#### Scenario: Array reference with all valid UUIDs
- GIVEN a schema with `validateReference: true` on an array property
- WHEN an object is saved with an array of UUIDs that all exist in the target schema
- THEN the save MUST succeed

#### Scenario: Null or empty reference value
- GIVEN a schema with `validateReference: true` on a non-required property
- WHEN an object is saved with the property set to `null` or `""`
- THEN the save MUST succeed (null/empty references are not validated)

#### Scenario: Empty string UUID in array is skipped
- GIVEN a schema with `validateReference: true` on an array property
- WHEN an object is saved with `members` = `["valid-uuid", "", "another-valid-uuid"]`
- THEN only `"valid-uuid"` and `"another-valid-uuid"` MUST be validated
- AND empty string entries MUST be skipped without error

### Requirement: Reference validation MUST resolve target schema via existing $ref resolution
The validation MUST use the same `resolveSchemaReference()` mechanism that SaveObject already uses for `$ref` resolution. This method supports numeric IDs, UUIDs, slugs, JSON Schema paths (`#/components/schemas/Name`), and full URLs. Resolved schema IDs MUST be cached in `$schemaReferenceCache` for performance across multiple validations in the same request.

#### Scenario: $ref as schema ID
- GIVEN a property with `$ref: "42"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST use `resolveSchemaReference("42")` to find the schema by numeric ID

#### Scenario: $ref as schema UUID
- GIVEN a property with `$ref: "550e8400-e29b-41d4-a716-446655440000"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST use `resolveSchemaReference()` to find the schema by UUID

#### Scenario: $ref as schema slug
- GIVEN a property with `$ref: "person"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST resolve `"person"` to the schema by case-insensitive slug match

#### Scenario: $ref as JSON Schema path
- GIVEN a property with `$ref: "#/components/schemas/Contactgegevens"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST extract `"Contactgegevens"` from the path and resolve by slug

#### Scenario: $ref as URL
- GIVEN a property with `$ref: "https://example.com/schemas/person"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST extract `"person"` from the URL path and resolve by slug

#### Scenario: Unresolvable $ref logs warning but does not block save
- GIVEN a property with `$ref: "nonexistent-schema"` and `validateReference: true`
- WHEN `resolveSchemaReference()` returns `null`
- THEN a warning MUST be logged with the property name and reference value
- AND the save MUST proceed without blocking (graceful degradation)

### Requirement: Reference validation MUST work with the object's register context
The existence check MUST look for the referenced object in the correct register. The target register is determined by: (1) the `register` property on the schema property definition (explicit cross-register), or (2) the object's own register (same-register default). When the target register cannot be resolved, a warning MUST be logged and validation MUST be skipped for that property.

#### Scenario: Same-register reference
- GIVEN an object in register `procest` with a `$ref` property pointing to schema `person`
- AND `person` schema exists in register `procest`
- WHEN the reference is validated
- THEN the existence check MUST query register `procest` for the person object

#### Scenario: Cross-register reference with explicit register
- GIVEN a property with:
  ```json
  {
    "owner": {
      "type": "string",
      "$ref": "person-schema-id",
      "register": "shared-register-id",
      "validateReference": true
    }
  }
  ```
- WHEN the reference is validated
- THEN the existence check MUST query the register specified in `register` config, not the object's own register

#### Scenario: Cross-register reference with unresolvable register
- GIVEN a property with `register: "deleted-register-id"` and `validateReference: true`
- WHEN the register cannot be found via `getCachedRegister()`
- THEN a warning MUST be logged with the property name and register ID
- AND the reference validation MUST be skipped for that property (graceful degradation)

### Requirement: Reference validation MUST NOT impact update operations for unchanged references
On updates (PUT/PATCH), properties whose values have not changed MUST NOT be re-validated. This is critical for data consistency: if a referenced object has been soft-deleted after the initial save, an update that does not change the reference value MUST NOT fail. The comparison MUST use strict equality (`===`) between old and new values.

#### Scenario: Update with unchanged reference
- GIVEN an existing object with `assignee` = `"person-uuid"` and `validateReference: true`
- AND the referenced person has since been deleted
- WHEN the object is updated with `assignee` = `"person-uuid"` (same value)
- THEN the save MUST succeed (unchanged values are not re-validated)

#### Scenario: Update with changed reference
- GIVEN an existing object with `assignee` = `"old-person-uuid"`
- WHEN the object is updated with `assignee` = `"new-person-uuid"`
- AND `new-person-uuid` does not exist
- THEN the save MUST fail with HTTP 422

#### Scenario: Update with changed array reference
- GIVEN an existing object with `members` = `["uuid-1", "uuid-2"]` and `validateReference: true`
- WHEN the object is updated with `members` = `["uuid-1", "uuid-3"]`
- AND `["uuid-1", "uuid-2"]` !== `["uuid-1", "uuid-3"]` (array changed)
- THEN ALL UUIDs in the new array MUST be validated (including `uuid-1` which was already present)
- AND if `uuid-3` does not exist, the save MUST fail with HTTP 422

### Requirement: Soft-deleted references MUST be treated as nonexistent
When `validateReference` is `true` and the referenced object has been soft-deleted (has `deletedAt` metadata set), the reference MUST be treated as nonexistent. The `MagicMapper::find()` method used for validation MUST exclude soft-deleted objects from its results by default.

#### Scenario: Reference to soft-deleted object on create
- GIVEN a person object `person-1` that has been soft-deleted (has `deletedAt` in metadata)
- AND a schema with `validateReference: true` on property `assignee` referencing `person`
- WHEN a new order is created with `assignee` = `"person-1-uuid"`
- THEN the save MUST fail with HTTP 422
- AND the error message MUST indicate the referenced object was not found

#### Scenario: Reference to soft-deleted object on update with changed value
- GIVEN an existing order with `assignee` = `"person-1-uuid"` (valid at creation time)
- AND person `person-1` has since been soft-deleted
- WHEN the order is updated with `assignee` = `"person-1-uuid"` (same value, unchanged)
- THEN the save MUST succeed (unchanged reference bypass)

#### Scenario: Reference to hard-deleted object
- GIVEN a person object that has been permanently removed from the database
- AND a schema with `validateReference: true` on property `assignee`
- WHEN a new order is created referencing that person's UUID
- THEN `MagicMapper::find()` MUST throw `DoesNotExistException`
- AND the save MUST fail with HTTP 422

### Requirement: Batch reference validation MUST be optimized for bulk imports
When objects are imported in bulk via `ImportService` or `SaveObjects` (bulk save pipeline), reference validation MUST be batched to avoid N+1 query patterns. The system MUST collect all unique reference UUIDs across all objects in the batch, validate them in a single pass per target schema, and cache results for the duration of the import operation.

#### Scenario: Bulk import with 100 objects referencing the same schema
- GIVEN 100 order objects being imported, each with `assignee` referencing the `person` schema
- AND the 100 objects reference 20 unique person UUIDs
- WHEN the bulk import processes reference validation
- THEN the system MUST collect all 20 unique UUIDs first
- AND MUST validate them in batched queries (batch size <= 50 per query)
- AND the total database queries for reference validation MUST NOT exceed ceil(20/50) = 1 query
- AND each UUID's existence result MUST be cached for reuse by subsequent objects in the batch

#### Scenario: Bulk import with mixed valid and invalid references
- GIVEN 50 objects being imported with `validateReference: true`
- AND 5 of the 50 objects reference nonexistent UUIDs
- WHEN the bulk import processes reference validation
- THEN the system MUST collect all validation errors before reporting
- AND the error response MUST include all 5 failed objects with their respective invalid UUIDs
- AND the 45 valid objects MUST still be saved (partial success model for imports)

#### Scenario: Bulk import with cross-schema references in a single batch
- GIVEN a batch of 30 objects where 10 reference `person`, 10 reference `product`, and 10 reference `category`
- WHEN batch reference validation runs
- THEN the system MUST group UUIDs by target schema
- AND MUST execute at most 3 batched validation queries (one per target schema)

### Requirement: Validation error reporting MUST include structured diagnostic information
When reference validation fails, the error response MUST include machine-readable diagnostic information beyond the human-readable message. This enables API consumers to programmatically handle validation failures.

#### Scenario: Single validation error with structured response
- GIVEN a save that fails reference validation on property `assignee`
- WHEN the HTTP 422 response is returned
- THEN the response body MUST include:
  ```json
  {
    "message": "Referenced object 'nonexistent-uuid' not found in schema 'person' for property 'assignee'",
    "error": "validation_error",
    "details": {
      "property": "assignee",
      "referenceUuid": "nonexistent-uuid",
      "targetSchema": "person",
      "targetRegister": "procest",
      "validationType": "reference_existence"
    }
  }
  ```

#### Scenario: Multiple validation errors collected in a single response
- GIVEN a schema with `validateReference: true` on properties `assignee` and `reviewer`
- AND both properties reference nonexistent UUIDs
- WHEN the object is saved
- THEN the save MUST fail with HTTP 422
- AND the error response MUST include details for BOTH failed properties
- AND the `details` field MUST be an array with entries for each failed property

### Requirement: Circular reference chains MUST be detected during validation
When two or more schemas have mutual `$ref` properties with `validateReference: true`, the system MUST detect circular reference chains during validation to prevent infinite validation loops. A visited-set pattern MUST track which objects are currently being validated in the call stack.

#### Scenario: Two schemas with mutual references and cascade creation
- GIVEN schema `incident` has property `notes` with `$ref: "note"`, `validateReference: true`, and `inversedBy: "incident"`
- AND schema `note` has property `incident` with `$ref: "incident"`, `validateReference: true`
- WHEN an incident is created with inline note objects (cascade creation)
- THEN the cascade creation handler MUST create the notes first
- AND reference validation on the notes' `incident` property MUST detect the parent is being created in the same transaction
- AND the validation MUST succeed (parent object is in the current save context)

#### Scenario: Self-referencing schema
- GIVEN schema `category` has property `parentCategory` with `$ref: "category"` and `validateReference: true`
- WHEN a category is created with `parentCategory` pointing to an existing category
- THEN the validation MUST succeed
- AND the system MUST NOT enter an infinite loop checking references

#### Scenario: Deeply nested circular chain
- GIVEN schemas A -> B -> C -> A, each with mutual `$ref` and `validateReference: true`
- WHEN object A is created with inline cascade creation of B and C
- THEN the validation depth MUST be bounded (maximum 10 levels, consistent with `ReferentialIntegrityService::MAX_DEPTH`)
- AND a warning MUST be logged if the depth limit is reached

### Requirement: External URL references MUST support configurable validation
When a `$ref` property contains a full URL pointing to an external system, the system MUST support optional HTTP-based existence validation. This MUST be controlled by a `validateExternalReference` boolean flag (separate from `validateReference`) and MUST respect timeout and retry configuration.

#### Scenario: External URL reference with validation enabled
- GIVEN a property with:
  ```json
  {
    "sourceDocument": {
      "type": "string",
      "$ref": "https://api.example.com/documents",
      "validateExternalReference": true,
      "externalValidationTimeout": 5000
    }
  }
  ```
- WHEN an object is saved with `sourceDocument` = `"https://api.example.com/documents/doc-123"`
- THEN the system MUST perform an HTTP HEAD request to the URL
- AND if the response status is 200-299, the validation MUST succeed
- AND if the response status is 404, the validation MUST fail with HTTP 422
- AND if the request times out (> 5000ms), the validation MUST log a warning and succeed (fail-open)

#### Scenario: External URL reference with validation disabled (default)
- GIVEN a property with `$ref` pointing to an external URL and no `validateExternalReference` flag
- WHEN an object is saved with a URL value
- THEN no HTTP request MUST be made to validate the URL
- AND the save MUST succeed regardless of the URL's validity

#### Scenario: External reference validation respects Nextcloud proxy settings
- GIVEN a Nextcloud instance configured with an HTTP proxy
- WHEN external reference validation performs an HTTP request
- THEN the request MUST use the proxy configuration from Nextcloud's `IConfig` (`proxy`, `proxyuserpwd`)

### Requirement: Validation results MUST be cached within a request scope
To avoid repeated database lookups when multiple objects reference the same target, validation results MUST be cached for the duration of the HTTP request. The `$schemaReferenceCache` in `SaveObject` MUST be extended to cache existence check results alongside schema resolution results.

#### Scenario: Two objects referencing the same UUID in a single request
- GIVEN two objects are saved in the same HTTP request (e.g., cascade creation)
- AND both reference `person-uuid` with `validateReference: true`
- WHEN the first object validates `person-uuid` and confirms it exists
- THEN the second object's validation of `person-uuid` MUST use the cached result
- AND only 1 database query MUST be executed for the existence check (not 2)

#### Scenario: Cache invalidation on object creation within the same request
- GIVEN a cascade creation that first creates a child object, then validates a parent's reference to that child
- WHEN the child object is created successfully
- THEN the existence cache MUST be updated to include the newly created child's UUID
- AND subsequent validation of references to that child MUST succeed

#### Scenario: Cache scope limited to current request
- GIVEN a validated reference from a previous HTTP request
- WHEN a new HTTP request begins
- THEN the existence cache MUST be empty (no cross-request caching)
- AND all references MUST be re-validated against the database

### Requirement: Admin users MUST be able to bypass reference validation
System administrators MUST be able to bypass reference validation when performing data maintenance operations (e.g., restoring backups, migrating data between registers). This MUST be controlled via a `_skipValidation` parameter on the API, restricted to admin users only.

#### Scenario: Admin bypasses validation via API parameter
- GIVEN an admin user making a POST request with `_skipValidation: true`
- AND the object references a nonexistent UUID with `validateReference: true`
- WHEN the save is processed
- THEN reference validation MUST be skipped entirely
- AND the save MUST succeed with the invalid reference stored

#### Scenario: Non-admin user attempts to bypass validation
- GIVEN a non-admin user making a POST request with `_skipValidation: true`
- WHEN the save is processed
- THEN the `_skipValidation` parameter MUST be ignored
- AND reference validation MUST proceed normally
- AND if the reference is invalid, the save MUST fail with HTTP 422

#### Scenario: Admin bypass logged for audit trail
- GIVEN an admin uses `_skipValidation: true` to save an object with invalid references
- WHEN the save succeeds
- THEN an audit trail entry MUST be created with `action: reference_validation_bypassed`
- AND the entry MUST include the admin user ID, property names, and invalid UUIDs

### Requirement: Reference validation MUST work in GraphQL mutations
GraphQL create and update mutations that flow through `ObjectService::saveObject()` MUST trigger the same reference validation as REST API saves. Validation errors MUST be surfaced as GraphQL errors with the `VALIDATION_ERROR` code via `GraphQLResolver::resolveCreate()` and `GraphQLResolver::resolveUpdate()`.

#### Scenario: GraphQL create mutation with invalid reference
- GIVEN a GraphQL mutation:
  ```graphql
  mutation {
    createOrder(input: { assignee: "nonexistent-uuid", title: "Test" }) {
      id
      assignee
    }
  }
  ```
- AND the `order` schema has `validateReference: true` on `assignee`
- WHEN the mutation is executed
- THEN `ObjectService::saveObject()` MUST throw `ValidationException`
- AND `GraphQLResolver::resolveCreate()` MUST catch the exception
- AND MUST return a GraphQL error with `extensions.code: "VALIDATION_ERROR"`
- AND the error message MUST include the property name and invalid UUID

#### Scenario: GraphQL update mutation with changed invalid reference
- GIVEN an existing order with `assignee: "valid-uuid"`
- AND a GraphQL mutation updating `assignee` to `"nonexistent-uuid"`
- WHEN the mutation is executed
- THEN the same validation and error handling MUST apply as for create mutations

#### Scenario: GraphQL batch mutation with partial failures
- GIVEN a GraphQL mutation that creates multiple objects in sequence
- AND one object has an invalid reference while others are valid
- WHEN the mutation is executed
- THEN the valid objects MUST be created successfully
- AND the invalid object MUST return a GraphQL error with `VALIDATION_ERROR`
- AND partial results MUST be returned per the GraphQL specification

### Requirement: Async validation MUST be supported for large batch operations
For batch operations exceeding a configurable threshold (default: 500 objects), the system MUST support asynchronous reference validation via a Nextcloud background job. The initial save MUST proceed with a `validationStatus: pending` flag, and the background job MUST validate references post-save and flag invalid objects.

#### Scenario: Batch import exceeding async threshold
- GIVEN 1000 objects being imported with `validateReference: true`
- AND the async validation threshold is set to 500
- WHEN the import processes reference validation
- THEN the system MUST save all objects immediately with `_validationStatus: "pending"` in metadata
- AND a `BackgroundValidationJob` MUST be queued via `IJobList::add()`
- AND the API response MUST include `validationJobId` for status polling

#### Scenario: Background validation job completes successfully
- GIVEN a `BackgroundValidationJob` processes 1000 objects
- AND 50 objects have invalid references
- WHEN the job completes
- THEN the 50 invalid objects MUST have `_validationStatus: "failed"` set in metadata
- AND the 950 valid objects MUST have `_validationStatus: "valid"` set
- AND a notification MUST be sent to the importing user via Nextcloud's `INotificationManager`

#### Scenario: Background validation job with transient errors
- GIVEN the database is temporarily unavailable during background validation
- WHEN the job encounters a connection error
- THEN the job MUST be retried up to 3 times with exponential backoff
- AND objects that could not be validated MUST have `_validationStatus: "retry_pending"`

### Requirement: Validation events MUST be dispatched for notification and extensibility
The reference validation pipeline MUST dispatch Nextcloud events via `IEventDispatcher` at key points, allowing other apps and listeners to react to validation outcomes.

#### Scenario: Validation failure event dispatched
- GIVEN a save that fails reference validation
- WHEN the `ValidationException` is about to be thrown
- THEN a `ReferenceValidationFailedEvent` MUST be dispatched with:
  - The object data that was being saved
  - The property name, invalid UUID, and target schema
  - The register and schema context
- AND other apps MAY listen to this event for custom notification or logging

#### Scenario: Validation success event dispatched for monitored schemas
- GIVEN a schema with `configuration.emitValidationEvents: true`
- AND a save succeeds with all references validated
- WHEN the save completes
- THEN a `ReferenceValidationSucceededEvent` MUST be dispatched with the validated property names and UUIDs
- AND this event MUST only be dispatched when `emitValidationEvents` is enabled (performance optimization)

#### Scenario: Event listeners do not block the save pipeline
- GIVEN a registered listener for `ReferenceValidationFailedEvent`
- AND the listener throws an exception
- WHEN the event is dispatched
- THEN the exception MUST be caught and logged
- AND the original validation error MUST still be returned to the client
- AND the save pipeline MUST NOT be affected by listener failures

### Requirement: Schema-configurable validation strictness levels MUST be supported
Schemas MUST support a `validationStrictness` configuration that controls the severity of reference validation failures. Three levels MUST be supported: `strict` (fail on invalid reference, default when `validateReference: true`), `warn` (log warning but allow save), and `off` (no validation).

#### Scenario: Strict validation (default)
- GIVEN a schema property with `validateReference: true` and no `validationStrictness` set
- WHEN an object is saved with a nonexistent reference
- THEN the save MUST fail with HTTP 422 (same as current behavior)

#### Scenario: Warn-level validation
- GIVEN a schema property with:
  ```json
  {
    "assignee": {
      "type": "string",
      "$ref": "person",
      "validateReference": true,
      "validationStrictness": "warn"
    }
  }
  ```
- WHEN an object is saved with `assignee` = `"nonexistent-uuid"`
- THEN the save MUST succeed
- AND a warning MUST be logged: `"[SaveObject] Reference validation warning: Referenced object 'nonexistent-uuid' not found in schema 'person' for property 'assignee'"`
- AND the response MUST include a `_warnings` array with the validation warning
- AND `_validationStatus` in metadata MUST be set to `"warnings"`

#### Scenario: Off-level validation overrides validateReference
- GIVEN a schema property with `validateReference: true` and `validationStrictness: "off"`
- WHEN an object is saved with a nonexistent reference
- THEN no validation check MUST be performed
- AND the save MUST succeed silently

## Current Implementation Status

**Substantially implemented.** Core requirements are in place with room for enhancement:

- `lib/Service/Object/SaveObject.php`:
  - `validateReferences()` (line ~3351) -- iterates schema properties, finds those with `$ref` and `validateReference: true`, checks existence
  - `validateReferenceExists()` (line ~3428) -- validates individual UUID against target schema using `resolveSchemaReference()` and `MagicMapper::find()` with `_rbac: false`, `_multitenancy: false`
  - `resolveSchemaReference()` (line ~336) -- resolves `$ref` by numeric ID, UUID, slug, JSON Schema path, or URL, with `$schemaReferenceCache` for performance
  - Called in both `createObject()` (line ~3186) and `updateObject()` (line ~3264)
  - On updates, unchanged references are skipped (compares old vs new data with strict equality)
  - Null/empty values are skipped (not validated)
  - Cross-register reference support via `register` property config with `getCachedRegister()` fallback
  - Unresolvable schemas or registers log warnings but do not block saves (graceful degradation)
- Array references are validated (each UUID in array checked individually)
- Returns HTTP 422 via `ValidationException` with descriptive error messages including property name, UUID, and target schema slug
- GraphQL mutations (`GraphQLResolver::resolveCreate()` and `resolveUpdate()`) catch `ValidationException` and surface as GraphQL errors with `VALIDATION_ERROR` code
- Non-existence errors (e.g., database errors) are logged as warnings but do not block saves

**What is NOT yet implemented:**
- Batch reference validation optimization for bulk imports (currently validates one UUID at a time)
- Structured error response with machine-readable `details` object (currently only has `message` string)
- Async validation for large batches via `BackgroundValidationJob`
- Validation events via `IEventDispatcher` (`ReferenceValidationFailedEvent`, `ReferenceValidationSucceededEvent`)
- `_skipValidation` admin bypass parameter
- ~~`validationStrictness` levels (warn, off)~~ -- shipped Phase 5: `validateReference` accepts string-shorthand `'warn' | 'error' | 'strict' | 'block' | 'off'`, plus the canonical `validationStrictness: 'strict' | 'warn' | 'off'` companion field. Warn-mode logs a warning and dispatches `ReferenceValidationFailedEvent` but does not throw HTTP 422. Resolution lives in `SaveObject::resolveReferenceStrictness()`
- `validateExternalReference` for URL-based references
- Multiple validation error collection (currently throws on first invalid reference)
- Request-scoped existence result caching (schema resolution is cached, but individual UUID existence is not)
- Soft-deleted reference handling is implicit (depends on `MagicMapper::find()` behavior)

## Standards & References
- JSON Schema `$ref` keyword (RFC draft-bhutton-json-schema-01)
- OpenRegister internal schema property format (custom `validateReference` extension to JSON Schema)
- HTTP 422 Unprocessable Entity (RFC 4918)
- GraphQL specification (June 2018) -- error handling in mutations
- Nextcloud IEventDispatcher (OCP\EventDispatcher\IEventDispatcher)
- Nextcloud IJobList (OCP\BackgroundJob\IJobList) for async validation jobs
- Nextcloud INotificationManager (OCP\Notification\INotificationManager) for validation result notifications

## Specificity Assessment
- **Specific enough to implement?** Yes -- the core scenarios match existing code behavior and new scenarios provide clear GIVEN/WHEN/THEN for each enhancement.
- **Missing/ambiguous:**
  - Exact batch size for bulk reference validation queries (suggested: 50, consistent with `RelationHandler::bulkLoadRelationshipsBatched()`)
  - Whether `_skipValidation` should also skip JSON Schema validation or only reference validation
  - How `validationStrictness: "warn"` interacts with `hardValidation` schema setting
  - Cache eviction strategy for request-scoped existence cache when objects are created mid-request via cascade
- **Open questions:**
  - Should external URL validation support OAuth2 bearer tokens for authenticated APIs?
  - Should async validation results be exposed via a dedicated API endpoint or only via object metadata?

## Nextcloud Integration Analysis

**Status**: Implemented (core), Enhancement opportunities identified

**Existing Implementation**: `SaveObject.php` contains `validateReferences()` which iterates schema properties to find those with `$ref` and `validateReference: true`, then checks existence via `validateReferenceExists()`. The `resolveSchemaReference()` method resolves `$ref` by numeric ID, UUID, slug, JSON Schema path, or URL with aggressive caching in `$schemaReferenceCache`. Validation is called in both `createObject()` and `updateObject()` flows. On updates, unchanged references are skipped by comparing old vs new data. Array references are validated individually per UUID. Null/empty values are skipped. Cross-register reference support is available via the `register` property configuration. HTTP 422 responses include descriptive error messages with property name, UUID, and target schema name. GraphQL mutations in `GraphQLResolver` catch `ValidationException` and surface them as GraphQL errors with `VALIDATION_ERROR` extension code.

**Nextcloud Core Integration Points**:
- **IDBConnection**: Reference validation runs within the save transaction, ensuring checks occur before data is committed. The `MagicMapper::find()` call used for existence checks operates within Nextcloud's database abstraction layer.
- **IEventDispatcher** (pending): Dispatch `ReferenceValidationFailedEvent` and `ReferenceValidationSucceededEvent` for extensibility. Other apps can listen for validation failures to trigger notifications or remediation workflows.
- **IJobList** (pending): Queue `BackgroundValidationJob` for async validation of large batches, using Nextcloud's cron infrastructure.
- **INotificationManager** (pending): Send notifications to users when async validation completes, indicating which objects have invalid references.
- **ICache (OCP\ICache)** (pending): Cache existence check results in Nextcloud's distributed cache (Redis/APCu) for request-scoped optimization, especially beneficial during bulk operations.
- **LoggerInterface (PSR-3)**: All validation warnings and errors are logged via Nextcloud's logger, visible in the admin log viewer.
- **IConfig**: External URL validation MUST use Nextcloud's proxy settings from `IConfig` for HTTP requests.

**Recommendation**: The reference existence validation is functional for single-object saves and works correctly through both REST and GraphQL APIs. Priority enhancements: (1) batch reference validation for imports to reduce N+1 queries; (2) request-scoped existence caching alongside schema caching; (3) structured error responses with machine-readable details; (4) `IEventDispatcher` integration for validation events; (5) `validationStrictness` levels for flexible validation policies.

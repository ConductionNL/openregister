---
retrofit: true
---

# Object Lifecycle

## Purpose

Extend the object-lifecycle capability with the HTTP REST contract exposed by
`ObjectsController` — the canonical `/api/objects/{register}/{schema}/...` CRUD
and sub-resource surface. The existing REQs (REQ-001..REQ-005) document the
internal layered save pipeline; these added requirements document the observed
controller behavior that sits in front of that pipeline: route shape, status
codes, parameter handling, and error envelopes. This is reverse-specced from
the existing implementation; no behavior is changed.

## ADDED Requirements

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

---
status: draft
---
# Files Render Extension

## Purpose

Define the contract for when and how `@self.files` appears on rendered objects across all OpenRegister consumers. The contract is symmetric across show and list endpoints, defaults to a cheap representation (file IDs only), and exposes full file metadata only when explicitly requested via the existing `_extend` mechanism. This eliminates today's asymmetry where show endpoints unconditionally pay the full file-lookup cost while list endpoints emit no `@self.files` field at all.

## Current State

- `RenderObject::renderEntity` (`lib/Service/Object/RenderObject.php:908`) unconditionally calls `$this->renderFiles($entity)` on every render, attaching full formatted file metadata (id, path, title, accessUrl, downloadUrl, type, extension, size, hash, published, modified, labels) to `@self.files`.
- `renderFiles()` issues at least one `FileMapper::getFilesForObject` query and one `SystemTagMapper::getTagIdsForObjects` query per object — paid on every show response, regardless of whether the consumer needs files.
- List endpoints (`PublicationsController::index`, `ObjectService::searchObjectsPaginated`, etc.) bypass `renderEntity` and emit no `@self.files` field at all.
- `RenderObject::renderEntity` already supports a `_extend` parameter and a `normalizeMap` (line 1015) that maps shorthand `_x` extension keys to canonical `@self.x` paths. Currently only `_schema` ⇄ `@self.schema` and `_register` ⇄ `@self.register` are normalized.
- `ObjectEntity::getObjectArray()` (`lib/Db/ObjectEntity.php:748`) emits the entity's `files` property under `@self.files` when serialized.

## ADDED Requirements

### Requirement: `@self.files` SHALL be present on every rendered object

Every JSON-rendered object that goes through OpenRegister's render pipeline (whether produced by show endpoints, list endpoints, or any other render path) SHALL include an `@self.files` field. The field SHALL be an array. An object that has no files SHALL emit `@self.files: []`.

#### Scenario: Object with no files on a show endpoint
- **GIVEN** an object with zero attached files
- **WHEN** a consumer requests the object via a show endpoint without any extend parameters
- **THEN** the response SHALL include `@self.files: []` (empty array, not absent, not null)

#### Scenario: Object with no files on a list endpoint
- **GIVEN** a paginated list response that includes an object with zero attached files
- **WHEN** a consumer requests the list without any extend parameters
- **THEN** the result row's `@self.files` SHALL be `[]`

#### Scenario: Object with files but no extend
- **GIVEN** an object with N attached files
- **WHEN** a consumer requests the object without `_extend[]=@self.files` and without `_extend[]=_files`
- **THEN** `@self.files` SHALL be a JSON array of N integers, each integer being a file ID

### Requirement: Default `@self.files` SHALL be a list of file IDs only

When no extend parameter covers `@self.files`, the field SHALL contain integer file IDs only — no objects, no paths, no URLs, no metadata. The order of IDs is unspecified but SHALL be stable across repeat calls within the same render context.

#### Scenario: Default shape on show endpoint
- **GIVEN** an object with three attached files (IDs 123, 456, 789)
- **WHEN** the consumer requests the object via a show endpoint with no extend parameter
- **THEN** `@self.files` SHALL equal `[123, 456, 789]` (or any stable permutation thereof) and contain only integers

#### Scenario: Default shape on list endpoint
- **GIVEN** a paginated list of 30 objects, each with attached files
- **WHEN** the consumer requests the list with no extend parameter
- **THEN** every result row's `@self.files` SHALL be an array of integer file IDs
- **AND** the file-ID lookup for the entire page SHALL be served by a single batched query (no N+1 over the page)

### Requirement: `_extend[]=@self.files` SHALL produce full file metadata

When the request's `_extend` parameter (after normalization) contains `@self.files`, the rendered `@self.files` SHALL contain fully-formatted file objects identical to today's `RenderObject::renderFiles()` output.

#### Scenario: Show endpoint with extend
- **GIVEN** an object with one attached file
- **WHEN** the consumer requests the object with `?_extend[]=@self.files`
- **THEN** `@self.files` SHALL be an array of one object
- **AND** the object SHALL include the keys `id`, `path`, `title`, `accessUrl`, `downloadUrl`, `type`, `extension`, `size`, `hash`, `published`, `modified`, `labels`

#### Scenario: List endpoint with extend
- **GIVEN** a paginated list response
- **WHEN** the consumer requests the list with `?_extend[]=@self.files`
- **THEN** every result row's `@self.files` SHALL contain fully-formatted file objects with the same keys as the show endpoint's full form

### Requirement: `_extend[]=_files` SHALL be equivalent to `_extend[]=@self.files`

The shorthand `_files` SHALL be treated as an alias for `@self.files` via the existing `normalizeMap` mechanism in `RenderObject::renderEntity`. Both spellings SHALL produce identical responses given identical other parameters.

#### Scenario: Shorthand and dotted form are equivalent
- **GIVEN** the same object on the same endpoint
- **WHEN** one consumer requests it with `?_extend[]=@self.files` and another with `?_extend[]=_files`
- **THEN** both responses SHALL have identical `@self.files` content (same set of file objects, same keys, same values)

#### Scenario: Mixed extend parameters
- **WHEN** a consumer requests `?_extend[]=_files&_extend[]=@self.schema`
- **THEN** the response SHALL include full file metadata at `@self.files` AND the resolved schema object at `@self.schema`

### Requirement: Default-shape lookup MUST use a batched file-ID query

The implementation of the lightweight default SHALL use a single batched lookup to retrieve file IDs for any number of objects in a render call. A new method `FileMapper::getFileIdsForObjects(array $uuids): array<string, int[]>` (or equivalent) SHALL serve this lookup in one query.

#### Scenario: List page with N objects produces one file-ID query
- **GIVEN** a list endpoint returning a page of 50 objects
- **WHEN** the consumer requests the list with no extend parameter
- **THEN** the implementation SHALL issue exactly ONE database query to retrieve file IDs for all 50 objects (regardless of how many files each object has)
- **AND** SHALL NOT issue per-object queries for the lightweight default

#### Scenario: Show endpoint with no extend produces one file-ID query
- **GIVEN** a show endpoint
- **WHEN** the consumer requests the object with no extend parameter
- **THEN** the implementation SHALL issue exactly ONE database query for the file IDs of that single object
- **AND** SHALL NOT issue the SystemTag queries that the full-metadata path requires

### Requirement: `RenderObject::renderFiles()` MUST run only when `@self.files` is in extend

The call to `RenderObject::renderFiles()` at `lib/Service/Object/RenderObject.php:908` SHALL be gated on whether the normalized `_extend` array contains `@self.files`. The body of `renderFiles()` SHALL NOT be modified by this change.

#### Scenario: renderFiles is skipped without extend
- **WHEN** `renderEntity` is invoked with an empty or absent `_extend`
- **THEN** `renderFiles()` SHALL NOT be invoked
- **AND** the rendered object's `@self.files` SHALL be the lightweight ID-only form

#### Scenario: renderFiles runs only with extend
- **WHEN** `renderEntity` is invoked with `_extend` containing (after normalization) `@self.files`
- **THEN** `renderFiles()` SHALL be invoked
- **AND** the rendered object's `@self.files` SHALL contain full file metadata objects

### Requirement: Performance contract for list-with-extend SHALL be documented as discouraged

When a list endpoint is requested with `_extend[]=@self.files` (or `_files`), the implementation SHALL render full file metadata for every row. This causes per-row file lookups (N+1 over the page). This usage SHALL be loudly documented as discouraged because of degraded performance.

#### Scenario: Documentation calls out the perf cost
- **WHEN** a developer reads the OpenRegister API documentation for `_extend[]=@self.files`
- **THEN** the documentation SHALL state that using this extension on list endpoints is heavily discouraged and will result in degraded performance

#### Scenario: List with extend still works correctly
- **GIVEN** a list endpoint and `?_extend[]=@self.files`
- **WHEN** the consumer makes the request
- **THEN** every row's `@self.files` SHALL contain full file metadata (correctness preserved despite perf cost)

### Requirement: This contract SHALL apply uniformly across all render-output endpoints

The behavior described above is a property of OpenRegister's render pipeline, not of any individual consumer. Any consumer that produces JSON output via `RenderObject::renderEntity` or `ObjectService::searchObjectsPaginated` SHALL inherit this contract automatically without consumer-side code changes.

#### Scenario: opencatalogi PublicationsController::show inherits the contract
- **GIVEN** opencatalogi's `PublicationsController::show` (which calls `ObjectService::renderEntity`)
- **WHEN** the show endpoint is requested without extend
- **THEN** the response's `@self.files` SHALL be a list of file IDs
- **AND** opencatalogi requires no code changes to receive this behavior

#### Scenario: opencatalogi PublicationsController::index inherits the contract
- **GIVEN** opencatalogi's `PublicationsController::index` (which calls `ObjectService::searchObjectsPaginated`)
- **WHEN** the list endpoint is requested without extend
- **THEN** every result row's `@self.files` SHALL be a list of file IDs
- **AND** opencatalogi requires no code changes to receive this behavior

### Requirement: This change SHALL be flagged as a BREAKING change for show consumers

Any consumer that today reads the full file-metadata object structure on a show response without specifying `_extend` SHALL receive integers instead of objects after this change. This MUST be documented prominently.

#### Scenario: CHANGELOG entry
- **WHEN** the openregister CHANGELOG is updated for this release
- **THEN** the entry SHALL clearly state: `@self.files` no longer contains full file metadata by default. Add `_extend[]=@self.files` (or `_extend[]=_files`) to opt in.

#### Scenario: opencatalogi documentation updated
- **WHEN** the opencatalogi public-facing documentation for `/publications/{catalogSlug}/{id}` and `/publications/{catalogSlug}` is updated
- **THEN** the documentation SHALL describe the new default (file IDs), the opt-in syntax (both `_extend[]=@self.files` and `_extend[]=_files`), and the perf warning for list+extend
- **AND** opencatalogi code SHALL NOT require modification (docs-only change)

### Requirement: Out-of-scope behaviors SHALL remain untouched

This change SHALL NOT modify the behavior of related but separate file-handling mechanisms:

- `RenderObject::renderFileProperties` (`lib/Service/Object/RenderObject.php:911`) — schema-property file hydration that replaces file IDs with file objects inside object data when a schema property is typed `file`. This is a different mechanism with a different location in the JSON.
- Any dedicated attachments endpoint such as `PublicationsController::attachments()` in opencatalogi.

#### Scenario: renderFileProperties continues to hydrate file properties
- **GIVEN** an object whose schema declares a property of type `file`
- **WHEN** `renderEntity` is invoked (with or without extend)
- **THEN** `renderFileProperties` SHALL still replace file IDs in that property with full file objects, exactly as today

#### Scenario: PublicationsController::attachments unchanged
- **GIVEN** a request to opencatalogi's `attachments` endpoint
- **WHEN** the endpoint runs
- **THEN** it SHALL return the same response shape it returns today, unaffected by this change

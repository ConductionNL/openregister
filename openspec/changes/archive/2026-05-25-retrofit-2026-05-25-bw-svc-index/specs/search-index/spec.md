# Search Index (delta)

## Purpose

The `search-index` capability spec (REQ-1..5) covers the high-level facade and the major orchestration flows but leaves the backend value-transformation, schema-mirroring conflict logic, and file-chunk indexing paths undocumented. The 2026-05-25 Bucket-Wide scan flagged these as uncovered. These three requirements capture observed behavior in `DocumentBuilder`, `SchemaHandler`, and `FileHandler` so future changes to the coercion/conflict/chunking logic are recognised as behavior changes. No production code changes.

**Source**: Reverse-spec retrofit of shipped code — `lib/Service/Index/` backend handlers. Behavior is documented as observed, not changed.

## ADDED Requirements

### Requirement: DocumentBuilder coerces, validates, and reshapes object data into backend-safe documents

`DocumentBuilder` MUST transform raw `ObjectEntity` data into a backend-safe document by coercing each value to its declared SOLR field type, validating type compatibility, truncating oversized strings, reconstructing dot-notation array relations, and resolving register/schema references to integer IDs. The transformation MUST be lossy-safe — incompatible or unresolvable values are skipped or coerced rather than aborting the document build.

#### Rationale

SOLR is strongly typed and has a hard 32 KB byte ceiling on indexed string fields, while OpenRegister object bodies are schema-loose JSON that can carry base64 blobs, mixed-type arrays, and relations stored only as dot-notation keys (`standaarden.0`). Coercing, validating, and truncating at document-build time keeps a single malformed property from failing an entire batch index, and resolving register/schema slugs to integer IDs keeps `register:5` style filtering working regardless of how the reference was stored.

#### Scenario: convertValueForSolr coerces by declared type and skips non-numeric values

- **GIVEN** a value `"abc"` for a field declared `integer`
- **WHEN** `DocumentBuilder::convertValueForSolr("abc", "integer")` runs
- **THEN** the method MUST return `null` (non-numeric skipped, not cast to `0`)
- **AND** a numeric `"42"` for an `integer` field MUST return the int `42`
- **AND** a `date`/`datetime` value MUST be formatted as `Y-m-d\TH:i:s\Z`

#### Scenario: isValueCompatibleWithSolrType rejects type mismatches but allows arrays element-wise

- **GIVEN** a non-numeric string and a SOLR field type of `pint`
- **WHEN** `DocumentBuilder::isValueCompatibleWithSolrType($value, 'pint')` runs
- **THEN** the method MUST return `false`
- **AND** for an array value the method MUST recurse, returning `true` only if every element is compatible
- **AND** unknown SOLR field types MUST default to `true` (permissive)

#### Scenario: truncateFieldValue caps strings at the SOLR byte ceiling

- **GIVEN** a string longer than 32766 bytes
- **WHEN** `DocumentBuilder::truncateFieldValue($value)` runs
- **THEN** the result MUST be UTF-8-safe truncated to under the limit and suffixed with `...[TRUNCATED]`
- **AND** values within the limit MUST be returned unchanged
- **AND** non-string values MUST be returned unchanged

#### Scenario: extractArraysFromRelations rebuilds arrays from dot-notation keys

- **GIVEN** relations `["standaarden.1" => "b", "standaarden.0" => "a", "nested.x" => "y"]`
- **WHEN** `DocumentBuilder::extractArraysFromRelations($relations)` runs
- **THEN** the result MUST contain `standaarden => ["a", "b"]` (sorted by numeric index, re-keyed sequentially)
- **AND** non-numeric indices (`nested.x`) MUST be skipped, not added as array elements

#### Scenario: resolveRegisterToId returns integer IDs and falls back to 0

- **GIVEN** a numeric register value
- **WHEN** `DocumentBuilder::resolveRegisterToId($value)` runs
- **THEN** the method MUST return it cast to int
- **AND** a slug/name value MUST be resolved via `RegisterMapper::find()` to its ID
- **AND** an empty or unresolvable value MUST return `0` (the same contract holds for `resolveSchemaToId` via `SchemaMapper`)

---

### Requirement: SchemaHandler resolves cross-schema field-type conflicts and provisions vector fields

`SchemaHandler::mirrorSchemas()` MUST analyse every OpenRegister schema's properties before applying any field and, when the same field name resolves to different SOLR types across schemas, MUST resolve the conflict to the most permissive type using the hierarchy `string > text > float > integer > boolean`. `SchemaHandler::ensureVectorFieldType()` MUST provision a `knn_vector` dense-vector field type (idempotently) for vector-similarity search.

#### Rationale

A single SOLR collection mirrors fields from every schema, so a field named `code` that is an integer in one schema and a string in another would otherwise fail to index against whichever type was created first. Choosing the most permissive type (string can hold everything; integer cannot hold text) lets one collection serve heterogeneous schemas without per-schema collections. The `knn_vector` provisioning is the prerequisite for semantic/vector search and must be a no-op when the type already exists so re-runs of the mirror are safe.

#### Scenario: mirrorSchemas resolves a field-type conflict to the most permissive type

- **GIVEN** field `code` resolves to `integer` in one schema and `string` in another
- **WHEN** `SchemaHandler::mirrorSchemas()` analyses the schemas
- **THEN** the conflict MUST be recorded and resolved to `string` (most permissive)
- **AND** the resolved type MUST be used when generating the SOLR field definition
- **AND** the run MUST report `resolved_conflicts` in its result

#### Scenario: ensureVectorFieldType is idempotent

- **GIVEN** a collection that already has a `knn_vector` field type
- **WHEN** `SchemaHandler::ensureVectorFieldType($collection)` runs
- **THEN** the method MUST detect the existing type via `getFieldTypes()` and return `true` without re-creating it
- **AND** when absent, it MUST create a `solr.DenseVectorField` with the requested `vectorDimension` and `similarityFunction`

---

### Requirement: FileHandler indexes database-resident file chunks into the backend file collection

`FileHandler` MUST index file-content chunks — produced separately by the text-extraction flow and persisted via `ChunkMapper` — into the search backend's file collection. It MUST NOT extract text itself; it only reads existing chunks, maps each chunk to a document, submits them via `SearchBackendInterface::index()`, and marks successfully indexed chunks as indexed in the database.

#### Rationale

Text extraction (OCR, PDF parsing) is expensive and runs on its own schedule, writing chunks to the database. Decoupling indexing from extraction lets the index be (re)built from already-extracted chunks without re-parsing files, and lets a backfill (`processUnindexedChunks`) catch up chunks that were extracted while the backend was unavailable. Marking chunks indexed only after a successful submit keeps the backfill idempotent.

#### Scenario: processUnindexedChunks groups by file, indexes, and marks chunks

- **GIVEN** `ChunkMapper::findUnindexed()` returns chunks for two file IDs
- **WHEN** `FileHandler::processUnindexedChunks()` runs
- **THEN** chunks MUST be grouped by their source file ID and each group submitted via `indexFileChunks()`
- **AND** on a successful index the chunks MUST be marked `indexed` via `ChunkMapper::update()`
- **AND** a per-file failure MUST increment `failed` and record an error without aborting the remaining files

#### Scenario: indexFileChunks maps chunks to documents and reports the indexed count

- **GIVEN** a file ID, an array of chunk entities, and file metadata
- **WHEN** `FileHandler::indexFileChunks($fileId, $chunks, $metadata)` runs
- **THEN** each chunk MUST become a document carrying `file_id`, `chunk_index`, `total_chunks`, `chunk_text`, owner/organisation/language, and created/updated timestamps
- **AND** the documents MUST be submitted via `SearchBackendInterface::index()`
- **AND** the result MUST report `success` and an `indexed` count equal to the document count on success

## Non-Functional

- **i18n (ADR-007)**: No user-facing strings (ADR-007 n/a) — all three behaviors are backend index plumbing; SOLR field names, document keys, and the `...[TRUNCATED]` marker are machine-facing.
- **Performance**: Byte-limit truncation and per-property coercion run at document-build time to keep one malformed property from failing an entire batch index; chunk indexing is decoupled from text extraction so the index can be (re)built without re-parsing files.
- **Backward compatibility**: Reverse-spec of already-shipped `DocumentBuilder`/`SchemaHandler`/`FileHandler` code; no production behavior change.

## Acceptance Criteria

- The reverse-spec'd methods (`DocumentBuilder` coercion/validation/truncation/array-reconstruction/ID-resolution, `SchemaHandler::mirrorSchemas`/`ensureVectorFieldType`, `FileHandler::processUnindexedChunks`/`indexFileChunks`) carry `@spec` annotations to REQ-6/REQ-7/REQ-8.
- The scenarios above hold for the shipped implementation.

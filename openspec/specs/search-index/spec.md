---
status: done
retrofit: true
---

# Search Index

## Purpose

@e2e exclude backend Solr/search index service — covered by PHPUnit

OpenRegister provides full-text search, faceted browsing, and bulk re-indexing over its object store via a pluggable search backend (Solr today, Elasticsearch in parallel, with the `SearchBackendInterface` keeping the contract backend-agnostic). The `search-index` capability covers everything from the high-level `IndexService` facade down through the Solr-specific primitives (`SolrCollectionManager`, `SolrDocumentIndexer`, `SolrQueryExecutor`, `SolrFacetProcessor`, `SolrHttpClient`), the schema/document builders (`SchemaHandler`, `DocumentBuilder`, `SchemaMapper`), the bulk-indexing driver (`BulkIndexer`), the configuration plumbing (`ConfigurationHandler`), and the tenant-collection setup orchestrator (`SetupHandler`).

This spec was reverse-engineered from code under `lib/Service/IndexService.php` and `lib/Service/Index/**` by the `retrofit-2026-05-24-search-index` ghost change after the Bucket 2a coverage scan flagged the layer as having zero spec coverage. Code already exists — requirements describe **what the code does**, not what we wish it did. See **Notes** for the stub/drift observations.

**Standards**: Apache Solr 8/9 (SolrCloud), Elasticsearch, Guzzle HTTP client, PSR-3 logger.
**Cross-references**:
- **object-lifecycle** — `IndexService::indexObject()` is invoked from the save pipeline (and removed via `deleteObject()` from the delete pipeline).
- **zoeken-filteren** — frontend search calls land on `IndexService::searchObjectsPaginated()`.
- **faceting-configuration** — frontend facet calls land on `SolrFacetProcessor` via the backend.
- **aggregations-backend-native** — `SearchBackendInterface::aggregate()` is the cross-backend aggregation entry point.
## Requirements
### Requirement: Search backend operations route through SearchBackendInterface

All search/index runtime operations exposed by `IndexService` (the facade in `lib/Service/IndexService.php`) MUST be performed against a `SearchBackendInterface` implementation, never against a concrete backend class. `IndexService` SHALL NOT make direct Solr or Elasticsearch HTTP calls — it delegates `indexObject()`, `deleteObject()`, `isAvailable()`, `warmupIndex()`, `collectionExists()`, `indexFiles()`, `getStats()`, etc. to `$this->searchBackend`. Concrete Solr-side primitives (`SolrCollectionManager`, `SolrDocumentIndexer`, `SolrQueryExecutor`, `SolrFacetProcessor`, `SolrHttpClient`) implement the backend's I/O and are only reachable through the interface.

#### Rationale

The interface boundary makes the indexing path testable with mocks (no live Solr required for unit tests) and keeps the per-backend HTTP/connection concerns isolated from the higher-level orchestration. Adding a new backend (Postgres `pg_trgm`, OpenSearch, …) only requires implementing the interface; no `IndexService` change is needed.

#### Scenario: indexObject delegates to the injected backend

- **GIVEN** an `IndexService` is constructed with a `SearchBackendInterface` instance
- **WHEN** the caller invokes `IndexService::indexObject($object, $commit)`
- **THEN** the call MUST be forwarded as `$this->searchBackend->indexObject(object: $object, commit: $commit)` with no other side effects
- **AND** the boolean return value MUST be passed through unchanged

#### Scenario: isAvailable swallows backend exceptions

- **GIVEN** the backend's `isAvailable()` throws `Exception`
- **WHEN** `IndexService::isAvailable()` is invoked
- **THEN** the exception MUST be caught and logged at `error` level with the message text
- **AND** the method MUST return `false`

#### Scenario: SolrQueryExecutor::search returns an empty result when no collection is active

- **GIVEN** `SolrCollectionManager::getActiveCollectionName()` returns `null`
- **WHEN** `SolrQueryExecutor::search($params)` is invoked
- **THEN** a `warning` log MUST be emitted with message `[SolrQueryExecutor] No active collection for search`
- **AND** the return MUST be the empty shape `['response' => ['numFound' => 0, 'docs' => []]]`

#### Scenario: SolrQueryExecutor builds Solr query parameters with defaults

- **GIVEN** an OpenRegister query `[]` (no `_search`, `_offset`, `_limit`)
- **WHEN** `SolrQueryExecutor::buildSolrQuery($query)` runs
- **THEN** the result MUST include `q: '*:*'`, `start: 0`, `rows: 30`
- **AND** include `sort` only if `_order` is set, and `fl` only if `_fields` is set
- **AND** if `_fields` is an array it MUST be joined with `','` before becoming `fl`

---

### Requirement: Schemas are mirrored to backend collections as flat field definitions

`SchemaHandler` SHALL mirror every OpenRegister schema's `properties[]` into the backend collection by deriving a Solr field definition `{name, type, indexed: true, stored: true, multiValued}` per property and submitting it through `SearchBackendInterface::addOrUpdateField()`. Core metadata fields (`id`, `uuid`, `name`, `title`, `summary`, `description`, `created`, `updated`, `published`, `deleted`, `owner`, `organisation`, `register`, `schema`) MUST be applied first via `getCoreMetadataFields()` and then user-schema fields. Relations stored on the `ObjectEntity` MUST be flattened by `DocumentBuilder::flattenRelationsForSolr()` to a multi-valued list of string values before being indexed; nested arrays, objects, and null values MUST be skipped. The dedicated `SchemaMapper` class in `lib/Service/Index/` is a stub (`mapToBackendSchema()` returns `[]`, `mapFieldType()` is identity) — actual mapping happens inside `SchemaHandler` via `determineSolrFieldType()`, not in `SchemaMapper`.

#### Rationale

Solr is fundamentally flat — nested JSON cannot be queried in the same way that an `ObjectEntity` `relations` map can. Flattening to multi-valued string fields lets `register:5 AND modules:foo` style queries work without the consumer having to know how the relation was stored in PHP. Core metadata first guarantees that every collection has the same baseline regardless of which user schema is mirrored.

#### Scenario: getCoreMetadataFields returns the 14-field metadata baseline

- **GIVEN** the schema mirror runs against a fresh collection
- **WHEN** `SchemaHandler::getCoreMetadataFields()` is called
- **THEN** the result MUST contain entries for `id`, `uuid`, `name`, `title`, `summary`, `description`, `created`, `updated`, `published`, `deleted`, `owner`, `organisation`, `register`, `schema`
- **AND** every field MUST have `indexed: true` and `stored: true`
- **AND** `id` MUST additionally carry `required: true`

#### Scenario: applySolrFields counts created vs updated outcomes

- **GIVEN** an array of three field configs (one new, one already present, one update)
- **WHEN** `SchemaHandler::applySolrFields($solrFields, $force)` runs
- **THEN** every config MUST be passed to `$this->searchBackend->addOrUpdateField()`
- **AND** the return MUST be `['created' => $n, 'updated' => $m]` where `$n` counts `'created'` results and `$m` counts `'updated'` results
- **AND** individual field exceptions MUST be logged at `error` level and MUST NOT abort the loop

#### Scenario: flattenRelationsForSolr keeps only scalar values

- **GIVEN** relations `["modules.0" => "foo", "modules.1" => 42, "nested" => ["x" => 1], "missing" => null]`
- **WHEN** `DocumentBuilder::flattenRelationsForSolr($relations)` runs
- **THEN** the result MUST be `["foo", "42"]` (only string/numeric values, cast to string)
- **AND** non-scalar entries (arrays, objects, null) MUST be skipped silently

#### Scenario: SchemaMapper is a no-op stub

- **GIVEN** any OpenRegister schema array
- **WHEN** `SchemaMapper::mapToBackendSchema($schema)` is called
- **THEN** the method MUST return `[]`
- **AND** `SchemaMapper::mapFieldType($fieldType)` MUST return its input unchanged

---

### Requirement: Bulk indexing fetches searchable-schema objects from the database in batches

`BulkIndexer::bulkIndexFromDatabase()` SHALL drive bulk-index runs by streaming objects whose schema has `searchable === true` from the database in batches. The flow MUST: (1) short-circuit returning `success: false` when `SearchBackendInterface::isAvailable()` is false; (2) compute `totalSearchableObjects` and `estimatedBatches` up front and log the plan; (3) iterate fetching `currentBatchSize` rows at offset `0, batchSize, 2*batchSize, ...` until `count(objects) < currentBatchSize` or `totalIndexed >= maxObjects`; (4) for each object call `DocumentBuilder::createDocument()` (skipping objects whose schema raises `Schema is not searchable` and counting them in `skipped_non_searchable`); (5) call `SearchBackendInterface::index($documents)` once per batch; (6) on any other `Exception`, throw `RuntimeException` whose message includes `Indexed: N, Batches: M` for partial-progress reporting.

#### Rationale

Bulk re-indexing the entire register (which can run into millions of rows for the larger municipal datasets) cannot load the result set into memory. Batching gives bounded memory and lets the operator cap a run with `maxObjects` for staging or smoke tests. The partial-progress information in the `RuntimeException` message is what surfaces in the admin UI when something goes wrong mid-run.

#### Scenario: Backend unavailability aborts before fetching

- **GIVEN** `SearchBackendInterface::isAvailable()` returns `false`
- **WHEN** `BulkIndexer::bulkIndexFromDatabase()` runs
- **THEN** the method MUST return `['success' => false, 'error' => 'Search backend is not available', 'indexed' => 0, 'batches' => 0]`
- **AND** no DB fetch MUST occur

#### Scenario: maxObjects caps total indexed count

- **GIVEN** `batchSize = 100` and `maxObjects = 250` with 1000 searchable objects in the DB
- **WHEN** `bulkIndexFromDatabase(batchSize: 100, maxObjects: 250)` runs
- **THEN** at most 3 batches MUST be fetched (sizes 100, 100, 50)
- **AND** `totalIndexed` MUST NOT exceed 250
- **AND** the final result `indexed` MUST equal `totalIndexed`

#### Scenario: Non-searchable schema is skipped, not fatal

- **GIVEN** a batch where `DocumentBuilder::createDocument()` throws `RuntimeException("Schema is not searchable")` for one object
- **WHEN** the batch is processed
- **THEN** the offending object MUST increment `results['skipped_non_searchable']`
- **AND** the surrounding loop MUST continue processing the remaining objects in the batch
- **AND** the failure MUST NOT propagate as a thrown exception

---

### Requirement: Search-backend configuration is loaded once from SettingsService and exposed via tenant-aware helpers

`ConfigurationHandler` (in `lib/Service/Index/`) SHALL load Solr configuration exactly once in its constructor by calling `SettingsService::getSolrSettings()` and SHALL initialize a Guzzle HTTP client preconfigured with `timeout: 30`, `connect_timeout: 10`, `verify: false`, `allow_redirects: true`, `http_errors: false`. When both `username` and `password` are present, the client MUST be configured with HTTP Basic auth. If `SettingsService::getSolrSettings()` throws, the handler MUST fall back to `['enabled' => false]` and log a `warning`. The handler exposes:

- `getTenantSpecificCollectionName($baseCollectionName)` — currently returns the base name unchanged (legacy multi-tenant suffix logic was simplified out).
- `getConfigStatus($key)` — returns the string `'✓ Configured'` when the key is set and non-empty, else `'✗ Not configured'`.

#### Rationale

Loading once in the constructor (rather than per-call) keeps every downstream call (`buildSolrBaseUrl()`, `getEndpointUrl()`, `getConfigStatus()`) cheap and self-contained. The fall-back to `['enabled' => false]` ensures that a missing/broken `SettingsService` doesn't crash the whole app — the rest of `IndexService` reads `enabled === true` as the gate for actually performing I/O. `verify: false` exists so SolrCloud running on a self-signed certificate inside a Kubernetes cluster works out of the box (Nextcloud's stock HTTP client refuses `allow_local_address`, see the TODO comment in `initializeHttpClient`).

#### Scenario: getSolrSettings failure is non-fatal

- **GIVEN** `SettingsService::getSolrSettings()` throws an `Exception`
- **WHEN** `new ConfigurationHandler(...)` runs
- **THEN** `$this->solrConfig` MUST become `['enabled' => false]`
- **AND** a `warning` log MUST be emitted with message `[ConfigurationHandler] Failed to load SOLR settings`
- **AND** the constructor MUST complete without re-throwing

#### Scenario: getTenantSpecificCollectionName is the identity function

- **GIVEN** any base collection name (e.g., `'openregister'`)
- **WHEN** `getTenantSpecificCollectionName('openregister')` is called
- **THEN** the return MUST be `'openregister'` (no tenant suffix is appended at this layer)

#### Scenario: getConfigStatus uses the checkmark / cross convention

- **GIVEN** `solrConfig = ['host' => 'solr.local', 'port' => 8983]`
- **WHEN** `getConfigStatus('host')` is called
- **THEN** the return MUST be the literal string `'✓ Configured'`
- **AND** `getConfigStatus('password')` MUST return `'✗ Not configured'`

---

### Requirement: SetupHandler::setupSolr orchestrates a five-step tenant-collection bootstrap

`SetupHandler::setupSolr()` SHALL run a six-step (tracked as `total_steps: 6` in `setupProgress`) idempotent bootstrap that initializes the tenant collection on a SolrCloud cluster. The steps MUST be: (1) `verifySolrConnectivity()`, (2) `ensureTenantConfigSet()`, (3) `forceConfigSetPropagation($tenantConfigSetName)`, (4) `ensureTenantCollectionExists()`, (5) `configureSchemaFields()`, (6) `validateSetup()`. Every step MUST be wrapped in `trackStep()` so the `setupProgress.steps[]` array contains a `{step_number, step_name, status, description, details}` entry. The first failure MUST short-circuit the method, populate `$this->lastErrorDetails` with at minimum `{operation, step, step_name, error_type, error_message, troubleshooting}`, and return `false`. Schema field configuration MUST iterate `getObjectEntityFieldDefinitions()` (the `self_*` prefixed metadata fields starting with `self_tenant`, `self_object_id`, `self_uuid`, `self_register`, `self_schema`, …) and try `addSchemaFieldWithResult()` first, falling back to `replaceSchemaFieldWithResult()` only when the failure message contains `'already exists'` or `'Field'`. ConfigSet propagation errors are recognised by `isConfigSetPropagationError()` matching the messages `'configset does not exist'`, `'Config does not exist'`, `'Could not find configSet'`, `'configSet not found'`, `'ConfigSet propagation timeout'`.

#### Rationale

SolrCloud setup is genuinely multi-step: nodes need to agree (via ZooKeeper) that a configSet exists before a collection can be created against it, and the propagation can lag by several seconds on first install. Tracking each step with a status + troubleshooting payload lets the admin UI render a meaningful progress indicator and lets bug reports come in with enough context to act on without grepping the server log. The add-then-replace pattern for schema fields makes the bootstrap idempotent — re-running `setupSolr()` after a partial failure does not crash on already-present fields.

#### Scenario: Connectivity failure aborts setup at step 1

- **GIVEN** `verifySolrConnectivity()` returns `false`
- **WHEN** `setupSolr()` runs
- **THEN** step 1 MUST be tracked with status `'failed'`
- **AND** `lastErrorDetails` MUST be populated with `operation: 'verifySolrConnectivity'`, `step: 1`, `error_type: 'connectivity_failure'`, and a `troubleshooting` array
- **AND** the method MUST return `false` without invoking steps 2–6

#### Scenario: ConfigSet propagation error pattern matching

- **GIVEN** a SolrCloud error message `'Could not find configSet openregister_xyz'`
- **WHEN** `isConfigSetPropagationError($message)` is called
- **THEN** the method MUST return `true` so the caller knows to retry with delay
- **AND** an unrelated error like `'Underlying core creation failed'` MUST return `false` (no retry — fail immediately)

#### Scenario: configureSchemaFields tries add then falls back to replace

- **GIVEN** a field that already exists in the collection and `addSchemaFieldWithResult()` returns `success: false, error: 'Field already exists'`
- **WHEN** `addOrUpdateSchemaFieldWithTracking()` processes that field
- **THEN** `replaceSchemaFieldWithResult()` MUST be invoked
- **AND** if the replace succeeds, the wrapper MUST return `['success' => true, 'action' => 'updated', 'details' => ...]`

#### Scenario: getObjectEntityFieldDefinitions includes the self_tenant required field

- **GIVEN** the SetupHandler bootstraps schema fields
- **WHEN** `getObjectEntityFieldDefinitions()` is called
- **THEN** the result MUST contain a `self_tenant` entry with `type: 'string'`, `required: true`, `docValues: true`
- **AND** include `self_object_id` (type `pint`), `self_register` (`pint`, `docValues: true`), and `self_schema` (`pint`, `docValues: true`) as the per-tenant identity/classification trio

---

### Requirement: Asynchronous file text extraction MUST run as a queued background job

When file text extraction is enabled, the system MUST extract text from uploaded files asynchronously via a one-time `QueuedJob` (`FileTextExtractionJob`) rather than blocking the user request that created or modified the file. The job MUST be a no-op when extraction is disabled in configuration, MUST validate that a `file_id` argument is present, and MUST delegate the actual extraction to `TextExtractionService::extractFile()`. Failures MUST be logged and MUST NOT propagate as uncaught exceptions out of the job.

#### Scenario: Extraction is skipped when disabled
- **GIVEN** the `fileManagement` app-config either has no value or declares `extractionScope === 'none'`
- **WHEN** `FileTextExtractionJob::run()` executes
- **THEN** the job MUST log an info message that extraction is disabled
- **AND** it MUST return without calling `TextExtractionService`

#### Scenario: Missing file_id is rejected
- **GIVEN** the job is queued without a `file_id` argument
- **WHEN** `run()` executes
- **THEN** it MUST log an error naming the missing argument
- **AND** it MUST return without attempting extraction

#### Scenario: Text is extracted for a valid file id
- **GIVEN** extraction is enabled and the job argument carries a valid `file_id`
- **WHEN** `run()` executes
- **THEN** it MUST call `TextExtractionService::extractFile(fileId: <id>, forceReExtract: false)`
- **AND** it MUST log start and successful-completion entries including a processing-time metric

#### Scenario: Extraction failure is contained
- **GIVEN** `TextExtractionService::extractFile()` throws an exception
- **WHEN** `run()` executes
- **THEN** the exception MUST be caught and logged at error level with the file id and the error message
- **AND** the job MUST NOT re-throw (the failure does not crash the cron worker)

#### Notes
- The job is queued from the file create/modify path so extraction never blocks the user request — this is the asynchronous complement to the synchronous Solr indexing covered by the bulk-indexing REQ.

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

### Requirement: File Text Extraction and Indexing HTTP Surface

The system MUST expose an HTTP surface for the file text-extraction-and-indexing
pipeline so administrators and the Files UI can extract text from files, index
the resulting chunks into the configured search backend, inspect extraction and
chunking statistics, search over file contents, and anonymise detected PII.

Text extraction MUST support per-file (re-)extraction and a bounded bulk
extraction over pending files, and MUST return HTTP `501` when file management
or extraction is disabled in configuration. Chunk indexing MUST process
unindexed chunks into the search backend and report indexing counts. The
surface MUST expose extraction statistics and chunking statistics. File search
MUST support semantic (vector-similarity) and hybrid (keyword + vector) modes
over the `file` entity type, each returning a `{success, query, total, results,
search_type}` envelope and rejecting an empty `query` with HTTP `400`. The
Files-sidebar endpoints MUST return the OpenRegister objects referencing a given
Nextcloud file id and that file's extraction status. File-index administration
MUST expose: read/update of file settings, file-collection field discovery and
creation, index warmup, per-file and bulk (re)indexing, file-index and
file-extraction statistics, and connection tests for the configured extraction
and anonymisation backends (Dolphin / Presidio / OpenAnonymiser). Anonymisation
MUST create a new anonymised copy of a file from previously detected entities,
leaving the original unchanged, and MUST reject files that are already
anonymised or have no detected entities.

#### Scenario: Force per-file text extraction
- **GIVEN** file management is enabled with an extraction scope other than `none`
- **WHEN** a POST request is sent to extract text for a file id
- **THEN** the controller MUST force re-extraction via the extraction service and return success

#### Scenario: Extraction disabled yields 501
- **GIVEN** file management is absent or its `extractionScope` is `none`
- **WHEN** per-file extraction is requested
- **THEN** the response MUST be HTTP `501` with `{success:false, message:"Text extraction disabled"}`

#### Scenario: Bulk extraction is bounded
- **GIVEN** a bulk-extract request with a `limit` above the cap
- **WHEN** the controller invokes the extraction service
- **THEN** the processed batch MUST be capped (at most 500 files) and the response MUST report `processed`/`failed`/`total`

#### Scenario: Chunk indexing reports counts
- **GIVEN** extracted file chunks awaiting indexing
- **WHEN** the process-and-index endpoint is invoked
- **THEN** unindexed chunks MUST be processed into the search backend and the response MUST carry the indexing result

#### Scenario: Semantic file search rejects empty query
- **GIVEN** a semantic-search request with an empty `query`
- **WHEN** the endpoint is invoked
- **THEN** the response MUST be HTTP `400` with `{success:false, message:"Query parameter is required"}`

#### Scenario: Anonymisation guards already-anonymised files
- **GIVEN** a file whose name already contains `_anonymized`
- **WHEN** the anonymise endpoint is invoked
- **THEN** the response MUST be HTTP `400` and no new anonymised copy MUST be created

### Requirement: Adaptive Post-Import Search-Index Warmup Scheduling
On import completion the service MUST schedule a one-time background Solr warmup job whose warmup mode and maximum-object cap are derived from the number of objects imported, MUST skip scheduling entirely when nothing was imported, and MUST treat a scheduling failure as non-fatal to the import.

`ImportService::scheduleSolrWarmup()` MUST compute the total objects imported across all sheets and MUST return `false` without scheduling when that total is zero. `ImportService::getRecommendedWarmupMode()` MUST select a warmup mode by import-size tier (large imports get the fastest mode, medium imports a balanced mode, small imports the safe mode). `ImportService::scheduleSmartSolrWarmup()` MUST use the recommended mode and a size-derived object cap (bounded by a hard maximum), MUST default to a delayed schedule with an immediate-run override, and MUST delegate to `scheduleSolrWarmup()`. A failure to enqueue the job MUST be logged and MUST NOT abort or roll back the completed import.

#### Scenario: Large import schedules a fast, capped warmup
- **GIVEN** an import that created a large number of objects
- **WHEN** the smart warmup is scheduled
- **THEN** the recommended mode MUST be the fastest tier
- **AND** the warmup object cap MUST be bounded by the hard maximum

#### Scenario: Empty import skips warmup
- **GIVEN** an import that created and updated zero objects
- **WHEN** warmup scheduling runs
- **THEN** no job MUST be enqueued and the call MUST return false

#### Scenario: Scheduling failure does not fail the import
- **GIVEN** the background job queue rejects the warmup job
- **WHEN** scheduling fails
- **THEN** the failure MUST be logged and the import MUST remain successful

### Requirement: DocumentBuilder produces a flat Solr document with metadata, scalar payload, and a `_text` blob fallback

`DocumentBuilder::createDocument(ObjectEntity $object)` SHALL build a Solr-ready associative array containing (a) the seven core metadata fields `id`, `object_id`, `uuid`, `schema`, `register`, `created`, `updated` populated from `ObjectEntity` getters, (b) every key/value pair from `ObjectEntity::getObject()` (skipping `null` values) with each value passed through `convertValueForSolr(value: $value, fieldType: 'auto')`, and (c) a `_text` field containing `json_encode($objectData)` so that the raw object body remains full-text searchable even when individual fields are not separately indexed. The `id` field MUST be the object's `uuid` as a string (NOT the database row id), enabling deterministic re-indexing. `created` and `updated` MUST be formatted with `format('Y-m-d\TH:i:s\Z')` and a `null` `DateTime` MUST resolve to `null` (via the safe-call operator), not to an empty string.

#### Rationale

`id` as UUID guarantees that re-indexing the same object overwrites the prior document (Solr uses `id` as the document key). The `_text` fallback is what makes free-text search work for fields that the schema mirror skipped because they were too dynamic to type. Filtering `null` upstream avoids Solr rejecting the document with "missing required field"-style errors when the object happens to have a `null` payload value.

#### Scenario: createDocument populates the seven core metadata fields from getters

- **GIVEN** an `ObjectEntity` with `id=42`, `uuid='abc-123'`, `schema=7`, `register=3`, `created=DateTime('2026-05-24 10:00:00')`, `updated=DateTime('2026-05-25 09:00:00')`, `object=['title' => 'X']`
- **WHEN** `DocumentBuilder::createDocument($object)` runs
- **THEN** the result MUST contain `id: 'abc-123'`, `object_id: 42`, `uuid: 'abc-123'`, `schema: 7`, `register: 3`
- **AND** `created: '2026-05-24T10:00:00Z'` and `updated: '2026-05-25T09:00:00Z'`
- **AND** `_text` MUST equal `json_encode(['title' => 'X'])`

#### Scenario: createDocument skips null payload values

- **GIVEN** an `ObjectEntity` whose `getObject()` returns `['present' => 'foo', 'absent' => null]`
- **WHEN** `createDocument()` is called
- **THEN** the result MUST contain key `present` with the converted value
- **AND** the result MUST NOT contain key `absent`

#### Scenario: createDocument tolerates a null `getUpdated()`

- **GIVEN** an `ObjectEntity` whose `getUpdated()` returns `null`
- **WHEN** `createDocument()` is called
- **THEN** the result key `updated` MUST be `null` (the `?->format()` safe-call resolves to null)
- **AND** no exception MUST be thrown

---

### Requirement: DocumentBuilder converts values by field type and truncates oversize strings

`DocumentBuilder::convertValueForSolr($value, string $fieldType)` SHALL coerce values into Solr-friendly representations based on the lowercased field type: `integer`/`int` casts numeric values to `(int)` and returns `null` (with debug log) for non-numeric input; `float`/`double`/`number` casts numeric to `(float)` and returns `null` for non-numeric; `boolean`/`bool` casts via `(bool)`; `date`/`datetime` formats a `\DateTime` instance as `'Y-m-d\TH:i:s\Z'` and parses strings via `DateTime::createFromFormat('Y-m-d H:i:s', $value)` (passing the original string through unchanged if the parse fails); `array` wraps scalars in a single-element array; any other type (including `auto`) falls through to `(string) $value`. A `null` input MUST always return `null` regardless of declared `$fieldType`.

`DocumentBuilder::truncateFieldValue($value, string $fieldName='')` SHALL enforce Solr's 32 766-byte limit for indexed string fields: non-string inputs MUST be returned unchanged; strings up to 32 766 bytes MUST be returned unchanged; strings exceeding the limit MUST be truncated via `mb_strcut($value, 0, 32766 - 100, 'UTF-8')` with the suffix `'...[TRUNCATED]'` appended, AND an `info` log MUST be emitted with `original_bytes`, `truncated_bytes`, and `truncation_point: 32666`. `shouldTruncateField($fieldName, $fieldDefinition)` MUST return `true` when (a) the field's `type` or `format` is `file`/`binary`/`data-url`/`base64`/`image`/`document`, (b) the lowercased field name is one of `logo`, `image`, `icon`, `thumbnail`, `content`, `body`, `description`, or (c) the field name contains the substring `'base64'`.

#### Rationale

Solr will silently drop or 400 on documents whose values don't match the field type — coercing in PHP keeps the indexer's "successful-index" semantics honest. Returning `null` rather than an empty string for non-numeric→numeric conversions lets the document-build step skip the field entirely (see REQ-6's null-skip scenario). The 32 KiB limit is Solr's actual byte cap for indexed string fields; the 100-byte safety margin prevents the truncation marker itself from pushing the value back over the limit on multi-byte UTF-8 boundaries. The `shouldTruncateField()` heuristic targets the file/image/base64 patterns that, in practice, are the only ones that hit the limit in the OpenRegister datasets.

#### Scenario: integer conversion accepts numeric strings, skips non-numeric

- **GIVEN** `convertValueForSolr(value: '42', fieldType: 'integer')`
- **THEN** the result MUST be `42` (int)
- **AND** `convertValueForSolr(value: 'forty-two', fieldType: 'integer')` MUST return `null`
- **AND** a `debug` log SHOULD be emitted for the non-numeric case

#### Scenario: datetime conversion parses Y-m-d H:i:s and falls through on mismatch

- **GIVEN** `convertValueForSolr(value: '2026-05-24 10:00:00', fieldType: 'datetime')`
- **THEN** the result MUST be `'2026-05-24T10:00:00Z'`
- **AND** `convertValueForSolr(value: '24/05/2026', fieldType: 'datetime')` MUST return `'24/05/2026'` unchanged

#### Scenario: truncateFieldValue caps at 32666 bytes with UTF-8-safe cut

- **GIVEN** a string `$big` of length 50 000 bytes (all ASCII)
- **WHEN** `truncateFieldValue($big, 'description')` is called
- **THEN** the result length MUST be at most `32666 + strlen('...[TRUNCATED]')`
- **AND** the result MUST end with `'...[TRUNCATED]'`
- **AND** an `info` log MUST be emitted with `truncation_point: 32666`

#### Scenario: shouldTruncateField fires on image/base64 patterns

- **GIVEN** `fieldName='logo'`, `fieldDefinition=[]`
- **THEN** `shouldTruncateField('logo', [])` MUST return `true`
- **AND** `shouldTruncateField('photo_base64', [])` MUST return `true`
- **AND** `shouldTruncateField('user_avatar', ['format' => 'image'])` MUST return `true`
- **AND** `shouldTruncateField('first_name', [])` MUST return `false`

---

### Requirement: SolrDocumentIndexer routes every CRUD operation through the active collection's `/update` endpoint

Every write-path method on `SolrDocumentIndexer` (`indexObject`, `bulkIndexObjects`, `indexDocuments`, `deleteObject`, `deleteByQuery`, `commit`, `clearIndex`, `optimize`) SHALL first resolve the active collection via `SolrCollectionManager::getActiveCollectionName()`, and SHALL short-circuit (returning `false`, or the array shape `{success: false, ...}` where the method returns an array) with a `warning` log when no active collection is set. The URL pattern for write operations MUST be `{httpClient->getEndpointUrl(collection)}/update?commit={true|false}` (with the literal strings `'true'` or `'false'`, NOT booleans). `commit()` MUST POST an empty body to `/update?commit=true`. `optimize()` MUST POST an empty body to `/update?optimize=true`. `deleteByQuery($query)` MUST POST the body `{delete: {query: $query}}` and, when `$returnDetails === true`, return `{success, query, result}` instead of a bare boolean. `deleteObject($objectId)` MUST POST `{delete: {query: 'id:' . $objectId}}` (NOT a delete-by-id command, because OpenRegister allows numeric and UUID ids interchangeably and querying by `id:` works for both). `getDocumentCount()` MUST GET `/select?q=*:*&rows=0&wt=json` and return `$data['response']['numFound']` (or `0` on the response shape miss).

Per-operation exceptions MUST be caught: write methods log at `error` level and return the failure shape; the surrounding pipeline (`BulkIndexer`, `IndexService`) is NOT responsible for re-throwing.

#### Rationale

Centralising the URL pattern and the no-collection short-circuit in the indexer keeps every method's no-op semantics consistent — callers don't need to check `isAvailable()` before each operation. Using `id:` query for deletes (rather than `<id>...</id>` delete-by-id syntax) avoids the integer-vs-UUID id-type mismatch that historically broke deletes in OpenRegister-on-Solr. The string-typed `commit=true|false` query parameter is what Solr's HTTP API accepts; booleans coerce to `1`/`0` and Solr treats them as commit=false.

#### Scenario: indexObject short-circuits with warning when no active collection

- **GIVEN** `SolrCollectionManager::getActiveCollectionName()` returns `null`
- **WHEN** `SolrDocumentIndexer::indexObject($object, true)` is called
- **THEN** the method MUST return `false`
- **AND** a `warning` log MUST be emitted with message `[SolrDocumentIndexer] No active collection for indexing`
- **AND** `SolrHttpClient::post()` MUST NOT be called

#### Scenario: deleteObject uses an `id:` delete-by-query, not delete-by-id

- **GIVEN** an active collection `'openregister'` and an `objectId` of `42`
- **WHEN** `SolrDocumentIndexer::deleteObject(42, false)` is called
- **THEN** the URL MUST be `{endpoint}/update?commit=false`
- **AND** the POST body MUST be `['delete' => ['query' => 'id:42']]`

#### Scenario: deleteByQuery returns details only when `returnDetails=true`

- **GIVEN** an active collection and a successful POST
- **WHEN** `deleteByQuery('register:5', false, false)` is called
- **THEN** the return MUST be the bare boolean `true`
- **WHEN** `deleteByQuery('register:5', false, true)` is called
- **THEN** the return MUST be the array `['success' => true, 'query' => 'register:5', 'result' => <post response>]`

#### Scenario: getDocumentCount returns 0 on no active collection without HTTP call

- **GIVEN** `getActiveCollectionName()` returns `null`
- **WHEN** `getDocumentCount()` is called
- **THEN** the return MUST be `0`
- **AND** no HTTP call MUST be issued

---

### Requirement: ObjectHandler::searchObjects builds a Solr query with OpenRegister's start/rows/q shape and converts the response to {results, total, start}

`ObjectHandler::searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true, bool $deleted=false)` SHALL: (1) build a Solr query via the private `buildSolrQuery()` with defaults `q: '*:*'`, `start: 0`, `rows: 10` (note: a different default to `SolrQueryExecutor`'s `rows: 30`); (2) when `$deleted === false`, append `-deleted:true` to the `fq` filter array; (3) call `SearchBackendInterface::search($solrQuery)`; (4) convert the response to `{results: <docs>, total: <numFound>, start: <start>}` via `convertToOpenRegisterFormat()`. The `_rbac` and `_multitenancy` flags MUST be accepted but currently produce no additional filters (TODO markers in the code MUST be preserved as documented stubs, not silent omissions).

`ObjectHandler::commit()` SHALL delegate to `SearchBackendInterface::commit()`, log `'Successfully committed to Solr'` at `info` level when the backend returns `true`, and on exception MUST log at `error` and re-throw (NOT swallow).

`ObjectHandler::reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null)` SHALL delegate the full call to `SearchBackendInterface::reindexAll($maxObjects, $batchSize, $collectionName)`, log start at `info`, and on exception MUST log at `error` and return `['success' => false, 'error' => $exceptionMessage]` (NOT throw).

#### Rationale

The two different default `rows` values (10 in `ObjectHandler`, 30 in `SolrQueryExecutor::buildSolrQuery`) is intentional drift between the legacy "small-list dashboard" path and the newer paginated-search path; both are observed behaviours that must remain compatible with their respective callers. The `_rbac`/`_multitenancy` TODOs are explicit hooks for an upcoming change spec and must remain visible as stubs — silently dropping the flags would make it harder to add the filtering later. `commit()` re-throws because committing is invoked synchronously from save-pipeline paths that need to surface the failure; `reindexAll()` swallows because it runs from a long-running background context where re-throwing would crash the cron.

#### Scenario: searchObjects defaults to q=*:*, start=0, rows=10

- **GIVEN** an empty query `[]` with `deleted=false`
- **WHEN** `ObjectHandler::searchObjects([])` runs
- **THEN** the Solr query passed to `SearchBackendInterface::search()` MUST contain `q: '*:*'`, `start: 0`, `rows: 10`
- **AND** `fq` MUST contain `'-deleted:true'`

#### Scenario: searchObjects returns the OpenRegister envelope shape

- **GIVEN** the backend returns `['response' => ['docs' => [{id:1}], 'numFound' => 100, 'start' => 20]]`
- **WHEN** `searchObjects()` runs
- **THEN** the return MUST be `['results' => [{id:1}], 'total' => 100, 'start' => 20]`
- **AND** keys NOT in the envelope (e.g., `responseHeader`, `facet_counts`) MUST NOT be propagated

#### Scenario: commit re-throws on backend failure

- **GIVEN** `SearchBackendInterface::commit()` throws `Exception('connection refused')`
- **WHEN** `ObjectHandler::commit()` is called
- **THEN** an `error` log MUST be emitted
- **AND** the exception MUST be re-thrown unchanged

#### Scenario: reindexAll swallows backend exceptions into a result array

- **GIVEN** `SearchBackendInterface::reindexAll(...)` throws `Exception('timeout')`
- **WHEN** `ObjectHandler::reindexAll()` is called
- **THEN** the return MUST be `['success' => false, 'error' => 'timeout']`
- **AND** the exception MUST NOT propagate

---

### Requirement: SolrQueryExecutor::searchPaginated translates OpenRegister pagination into Solr and returns a {results, total, limit, offset, page, pages} envelope

`SolrQueryExecutor::searchPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true, bool $deleted=false)` SHALL translate OpenRegister query keys into Solr keys using the following rules in `buildSolrQuery()`: `q := $query['_search'] ?? '*:*'`; `start := (int)($query['_offset'] ?? $query['_start'] ?? 0)` (offset wins over start when both are present); `rows := (int)($query['_limit'] ?? $query['_rows'] ?? 30)`; if `_order` is set, `sort := translateSortField($query['_order'])` which accepts either a string (passed through unchanged) or an associative `{field => direction}` map joined with `', '` and direction lowercased to `'asc'`/`'desc'`; if `_fields` is set, `fl := $query['_fields']` and if it is an array, it MUST be `implode(',', ...)`-joined. When `$deleted === false`, the filter `'-deleted:true'` MUST be appended to `fq`. The request MUST set `wt: 'json'` before the call to `search()`.

The result MUST be converted via `convertToPaginatedFormat($solrResult, $query)` to the envelope `{results: <docs>, total: <numFound>, limit: <_limit | 30>, offset: <response.start | 0>, page: <_page | 1>, pages: <ceil(numFound / limit)>}` where `pages = 0` when `limit <= 0` (no division-by-zero panic).

#### Rationale

The `_offset > _start` precedence reflects OpenRegister's API evolution — newer callers send `_offset`, legacy callers send `_start`, and both are accepted. `_order` as a string passes through because Solr already accepts `"field asc, other desc"` natively; the map form exists so PHP callers don't have to format the sort string themselves. `pages = 0` on `limit <= 0` is the observed behaviour (the early-return guard around `ceil(numFound / limit)`) and is what frontends like `zoeken-filteren` rely on to decide whether to render a paginator.

#### Scenario: _offset wins over _start when both are present

- **GIVEN** `$query = ['_offset' => 50, '_start' => 10, '_limit' => 25]`
- **WHEN** `searchPaginated($query)` runs
- **THEN** the Solr request MUST have `start: 50`, `rows: 25`
- **AND** the resulting envelope MUST have `limit: 25`

#### Scenario: _order as associative map joins direction-lowered pairs

- **GIVEN** `$query = ['_order' => ['title' => 'ASC', 'created' => 'desc']]`
- **WHEN** the internal `translateSortField()` runs
- **THEN** the Solr `sort` MUST be `'title asc, created desc'`
- **AND** a string `_order` MUST pass through unchanged

#### Scenario: _fields as array becomes comma-joined fl

- **GIVEN** `$query = ['_fields' => ['id', 'title', 'summary']]`
- **WHEN** the Solr query is built
- **THEN** `fl` MUST equal the string `'id,title,summary'`

#### Scenario: convertToPaginatedFormat returns pages=0 on limit<=0

- **GIVEN** a Solr response with `numFound: 500` and `$query = ['_limit' => 0]`
- **WHEN** the paginated envelope is built
- **THEN** the result MUST contain `pages: 0` (no division-by-zero)
- **AND** `total: 500`, `limit: 0`, `page: 1` (default)

## Notes

These are intentional observations of stub/drift behavior captured during the 2026-05-24 reverse-spec — they are NOT bugs to fix in this retrofit, but signal future work:

- **BulkIndexer::bulkIndexObjects() is a TODO wrapper.** It returns `['success' => false, 'message' => 'Method not yet extracted to BulkIndexer']` and logs a warning. The implemented bulk-index entry point is `bulkIndexFromDatabase()` (REQ-003 above). Not spec'd.
- **SchemaMapper is a stub.** `mapToBackendSchema()` returns `[]` and `mapFieldType()` is the identity function. The real schema-to-Solr mapping lives in `SchemaHandler::determineSolrFieldType()` and `DocumentBuilder::convertValueForSolr()`. REQ-002's last scenario captures the no-op contract explicitly so that any future change to `SchemaMapper` is recognised as a behaviour change.
- **WarmupHandler::warmupIndex() is a thin wrapper** around `SearchBackendInterface::warmupIndex()`. The actual warmup body lives in the per-backend implementation (and the interface doc-comment is the canonical contract for what warmup means). This file exists primarily so the constructor wires `BulkIndexer + IDBConnection` for a future direct-bulk path that bypasses the backend.
- **`ConfigurationHandler` and `SolrHttpClient` carry duplicate logic.** Both build the Solr base URL, both initialize a Guzzle client. `SolrHttpClient` is the newer of the two and is what the `Solr*` primitives depend on; `ConfigurationHandler` is retained for the higher-level `IndexService` flow. Treat them as parallel configurations during retrofit — REQ-004 covers `ConfigurationHandler`; the `SolrHttpClient` constructor wiring is folded into REQ-001 as a backend-internal concern.
- **`getTenantSpecificCollectionName()` is the identity function** but the surrounding API (and SetupHandler's `getTenantCollectionName()` / `getTenantId()`) still treats it as if it could append a suffix. The legacy multi-tenant suffix logic was simplified out; the regex extraction in `SetupHandler::getTenantId()` against `_nc_([a-f0-9]+)$` falls back to `'default'` and is currently always returning `'default'` because no upstream caller appends the suffix anymore. Captured under REQ-004 as observed behaviour.

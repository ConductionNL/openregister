---
status: implemented
retrofit: true
---

# Search Index

## Purpose

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

## Notes

These are intentional observations of stub/drift behavior captured during the 2026-05-24 reverse-spec — they are NOT bugs to fix in this retrofit, but signal future work:

- **BulkIndexer::bulkIndexObjects() is a TODO wrapper.** It returns `['success' => false, 'message' => 'Method not yet extracted to BulkIndexer']` and logs a warning. The implemented bulk-index entry point is `bulkIndexFromDatabase()` (REQ-003 above). Not spec'd.
- **SchemaMapper is a stub.** `mapToBackendSchema()` returns `[]` and `mapFieldType()` is the identity function. The real schema-to-Solr mapping lives in `SchemaHandler::determineSolrFieldType()` and `DocumentBuilder::convertValueForSolr()`. REQ-002's last scenario captures the no-op contract explicitly so that any future change to `SchemaMapper` is recognised as a behaviour change.
- **WarmupHandler::warmupIndex() is a thin wrapper** around `SearchBackendInterface::warmupIndex()`. The actual warmup body lives in the per-backend implementation (and the interface doc-comment is the canonical contract for what warmup means). This file exists primarily so the constructor wires `BulkIndexer + IDBConnection` for a future direct-bulk path that bypasses the backend.
- **`ConfigurationHandler` and `SolrHttpClient` carry duplicate logic.** Both build the Solr base URL, both initialize a Guzzle client. `SolrHttpClient` is the newer of the two and is what the `Solr*` primitives depend on; `ConfigurationHandler` is retained for the higher-level `IndexService` flow. Treat them as parallel configurations during retrofit — REQ-004 covers `ConfigurationHandler`; the `SolrHttpClient` constructor wiring is folded into REQ-001 as a backend-internal concern.
- **`getTenantSpecificCollectionName()` is the identity function** but the surrounding API (and SetupHandler's `getTenantCollectionName()` / `getTenantId()`) still treats it as if it could append a suffix. The legacy multi-tenant suffix logic was simplified out; the regex extraction in `SetupHandler::getTenantId()` against `_nc_([a-f0-9]+)$` falls back to `'default'` and is currently always returning `'default'` because no upstream caller appends the suffix anymore. Captured under REQ-004 as observed behaviour.

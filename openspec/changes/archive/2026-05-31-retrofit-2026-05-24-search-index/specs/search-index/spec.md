# search-index — Spec Delta

## ADDED Requirements

### Requirement: Search backend operations route through SearchBackendInterface

All search/index runtime operations exposed by `IndexService` (the facade in `lib/Service/IndexService.php`) MUST be performed against a `SearchBackendInterface` implementation, never against a concrete backend class. `IndexService` SHALL NOT make direct Solr or Elasticsearch HTTP calls — it delegates `indexObject()`, `deleteObject()`, `isAvailable()`, `warmupIndex()`, `collectionExists()`, `indexFiles()`, `getStats()`, etc. to `$this->searchBackend`. Concrete Solr-side primitives (`SolrCollectionManager`, `SolrDocumentIndexer`, `SolrQueryExecutor`, `SolrFacetProcessor`, `SolrHttpClient`) implement the backend's I/O and are only reachable through the interface.

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

### Requirement: Schemas are mirrored to backend collections as flat field definitions

`SchemaHandler` SHALL mirror every OpenRegister schema's `properties[]` into the backend collection by deriving a Solr field definition `{name, type, indexed: true, stored: true, multiValued}` per property and submitting it through `SearchBackendInterface::addOrUpdateField()`. Core metadata fields (`id`, `uuid`, `name`, `title`, `summary`, `description`, `created`, `updated`, `published`, `deleted`, `owner`, `organisation`, `register`, `schema`) MUST be applied first via `getCoreMetadataFields()` and then user-schema fields. Relations stored on the `ObjectEntity` MUST be flattened by `DocumentBuilder::flattenRelationsForSolr()` to a multi-valued list of string values before being indexed; nested arrays, objects, and null values MUST be skipped. The dedicated `SchemaMapper` class in `lib/Service/Index/` is a stub (`mapToBackendSchema()` returns `[]`, `mapFieldType()` is identity) — actual mapping happens inside `SchemaHandler` via `determineSolrFieldType()`, not in `SchemaMapper`.

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

### Requirement: Bulk indexing fetches searchable-schema objects from the database in batches

`BulkIndexer::bulkIndexFromDatabase()` SHALL drive bulk-index runs by streaming objects whose schema has `searchable === true` from the database in batches. The flow MUST: (1) short-circuit returning `success: false` when `SearchBackendInterface::isAvailable()` is false; (2) compute `totalSearchableObjects` and `estimatedBatches` up front and log the plan; (3) iterate fetching `currentBatchSize` rows at offset `0, batchSize, 2*batchSize, ...` until `count(objects) < currentBatchSize` or `totalIndexed >= maxObjects`; (4) for each object call `DocumentBuilder::createDocument()` (skipping objects whose schema raises `Schema is not searchable` and counting them in `skipped_non_searchable`); (5) call `SearchBackendInterface::index($documents)` once per batch; (6) on any other `Exception`, throw `RuntimeException` whose message includes `Indexed: N, Batches: M` for partial-progress reporting.

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

### Requirement: Search-backend configuration is loaded once from SettingsService and exposed via tenant-aware helpers

`ConfigurationHandler` (in `lib/Service/Index/`) SHALL load Solr configuration exactly once in its constructor by calling `SettingsService::getSolrSettings()` and SHALL initialize a Guzzle HTTP client preconfigured with `timeout: 30`, `connect_timeout: 10`, `verify: false`, `allow_redirects: true`, `http_errors: false`. When both `username` and `password` are present, the client MUST be configured with HTTP Basic auth. If `SettingsService::getSolrSettings()` throws, the handler MUST fall back to `['enabled' => false]` and log a `warning`. The handler exposes:

- `getTenantSpecificCollectionName($baseCollectionName)` — currently returns the base name unchanged (legacy multi-tenant suffix logic was simplified out).
- `getConfigStatus($key)` — returns the string `'✓ Configured'` when the key is set and non-empty, else `'✗ Not configured'`.

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

### Requirement: SetupHandler::setupSolr orchestrates a five-step tenant-collection bootstrap

`SetupHandler::setupSolr()` SHALL run a six-step (tracked as `total_steps: 6` in `setupProgress`) idempotent bootstrap that initializes the tenant collection on a SolrCloud cluster. The steps MUST be: (1) `verifySolrConnectivity()`, (2) `ensureTenantConfigSet()`, (3) `forceConfigSetPropagation($tenantConfigSetName)`, (4) `ensureTenantCollectionExists()`, (5) `configureSchemaFields()`, (6) `validateSetup()`. Every step MUST be wrapped in `trackStep()` so the `setupProgress.steps[]` array contains a `{step_number, step_name, status, description, details}` entry. The first failure MUST short-circuit the method, populate `$this->lastErrorDetails` with at minimum `{operation, step, step_name, error_type, error_message, troubleshooting}`, and return `false`. Schema field configuration MUST iterate `getObjectEntityFieldDefinitions()` (the `self_*` prefixed metadata fields starting with `self_tenant`, `self_object_id`, `self_uuid`, `self_register`, `self_schema`, …) and try `addSchemaFieldWithResult()` first, falling back to `replaceSchemaFieldWithResult()` only when the failure message contains `'already exists'` or `'Field'`. ConfigSet propagation errors are recognised by `isConfigSetPropagationError()` matching the messages `'configset does not exist'`, `'Config does not exist'`, `'Could not find configSet'`, `'configSet not found'`, `'ConfigSet propagation timeout'`.

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

## Notes

- `BulkIndexer::bulkIndexObjects()` is currently a TODO wrapper that returns `['success' => false, 'message' => 'Method not yet extracted to BulkIndexer']` and emits a `warning`. It is intentionally **not** spec'd — the implemented bulk-index entry point is `bulkIndexFromDatabase()` (REQ-003).
- The `SchemaMapper` class under `lib/Service/Index/` is a stub. REQ-002 captures that fact rather than promoting it to a real mapper contract; the actual mapping logic lives in `SchemaHandler::determineSolrFieldType()` and `DocumentBuilder::convertValueForSolr()`.
- `IndexService` is a facade — `indexFiles()`, `warmupIndex()`, `indexFileChunks()` all forward to specialised handlers (`FileHandler`, `WarmupHandler`/backend, `FileHandler` again). The `WarmupHandler` itself also forwards `warmupIndex()` to the backend; the actual warmup body lives in the per-backend implementation, not in `WarmupHandler`.
- `ConfigurationHandler` and `SolrHttpClient` carry duplicate logic (both build the base URL, both initialize a Guzzle client). `SolrHttpClient` is the newer of the two and is what the `Solr*` primitives depend on; `ConfigurationHandler` is retained for the higher-level `IndexService` flow. Treat them as parallel configurations during retrofit — REQ-004 covers `ConfigurationHandler`; the `SolrHttpClient` constructor wiring is folded into REQ-001 as a backend-internal concern.

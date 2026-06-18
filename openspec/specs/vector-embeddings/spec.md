---
status: done
---

# vector-embeddings Specification

## Purpose

@e2e exclude backend vector/KNN search service — covered by PHPUnit
TBD - created by archiving change retrofit-2026-05-25-bw-svc-mid3. Update Purpose after archive.
## Requirements
### Requirement: The system MUST execute vector queries via semantic KNN/cosine similarity and hybrid Reciprocal-Rank-Fusion search

`VectorEmbeddings::semanticSearch(string $query, int $limit = 10, array $filters = [], ?string $provider = null)` MUST first generate a query embedding for `$query` via `generateEmbedding()` (vector-embeddings REQ-001), resolve the configured backend via `getVectorSearchBackend()` (vector-embeddings REQ-002), and delegate to `VectorSearchHandler::semanticSearch(array $queryEmbedding, int $limit, array $filters, string $backend)`.

`VectorSearchHandler::semanticSearch` MUST route by backend: when `$backend === 'solr'` it MUST execute a dense-vector KNN query through the Solr backend (`IndexService::getBackend()`, which MUST report `isAvailable() === true` or the call MUST throw `Solr service is not available`); otherwise it MUST fetch candidate vectors from `openregister_vectors` (honouring the `$filters` predicate), `unserialize()` each stored embedding BLOB, compute `cosineSimilarity()` against the query embedding, sort by similarity descending, and return the top `$limit`. A vector row whose deserialised embedding is not an array MUST be skipped (not fatal). When the database path finds no vectors the call MUST return `[]`. Any thrown error MUST be re-wrapped as `Semantic search failed: {message}`.

Each result entry MUST carry `vector_id`, `entity_type`, `entity_id`, `similarity`, `chunk_index`, `total_chunks`, `chunk_text`, `metadata` (JSON-decoded from the row, `[]` when absent), `model`, and `dimensions`.

`VectorEmbeddings::hybridSearch(string $query, array $solrFilters = [], int $limit = 20, array $weights = ['solr' => 0.5, 'vector' => 0.5], ?string $provider = null)` MUST generate the query embedding, read pre-computed Solr results from `$solrFilters['solr_results']` (default `[]`), and delegate to `VectorSearchHandler::hybridSearch(array $queryEmbedding, array $solrResults, int $limit, array $weights, string $backend)`.

`VectorSearchHandler::hybridSearch` MUST normalise the supplied `solr` / `vector` weights to sum to 1 (when their sum is `> 0`), run the vector leg via `semanticSearch` with `limit * 2` candidates ONLY when `vectorWeight > 0`, fuse the vector and Solr result sets via Reciprocal Rank Fusion (`reciprocalRankFusion()`), and return the top `$limit` fused results. A failure in the vector leg MUST be logged and tolerated (the Solr leg still contributes) — it MUST NOT abort hybrid search. The response MUST include `results`, `total`, `search_time_ms`, a `source_breakdown` of `vector_only` / `solr_only` / `both` counts, and the normalised `weights`.

#### Scenario: Database semantic search ranks by cosine similarity and caps at limit
- **GIVEN** backend resolves to `'php'` and `openregister_vectors` holds 50 candidate rows for the filter
- **WHEN** `VectorEmbeddings::semanticSearch('find me', 10)` runs
- **THEN** the query MUST first be embedded via `generateEmbedding('find me')`
- **AND** cosine similarity MUST be computed against each deserialised stored embedding
- **AND** the result MUST be the 10 highest-similarity entries sorted descending, each carrying `vector_id`, `entity_type`, `entity_id`, `similarity`, `chunk_text`, `model`, and `dimensions`

#### Scenario: Unparseable stored embedding is skipped, not fatal
- **GIVEN** one of the fetched vector rows deserialises to a non-array value
- **WHEN** the database semantic-search loop processes it
- **THEN** that row MUST be skipped with a warning log
- **AND** the remaining rows MUST still be scored and returned

#### Scenario: Empty vector table returns empty result on the database path
- **GIVEN** backend is not `'solr'` and `fetchVectors($filters)` returns `[]`
- **WHEN** `VectorSearchHandler::semanticSearch(...)` runs
- **THEN** the call MUST return `[]`
- **AND** no cosine-similarity computation MUST be attempted

#### Scenario: Hybrid search tolerates a failing vector leg
- **GIVEN** `weights = ['solr' => 0.5, 'vector' => 0.5]` and the vector `semanticSearch` call throws
- **WHEN** `VectorSearchHandler::hybridSearch(...)` runs
- **THEN** the failure MUST be logged and swallowed (no exception propagates)
- **AND** the Solr results MUST still be fused and returned via Reciprocal Rank Fusion
- **AND** the response MUST include `results`, `total`, `search_time_ms`, `source_breakdown`, and the normalised `weights`

#### Scenario: Hybrid weights are normalised to sum to one
- **GIVEN** `weights = ['solr' => 3, 'vector' => 1]`
- **WHEN** `hybridSearch(...)` normalises them
- **THEN** the effective weights MUST be `['solr' => 0.75, 'vector' => 0.25]`
- **AND** the returned `weights` field MUST reflect the normalised values

### Requirement: The system MUST generate text embeddings through a pluggable multi-provider backend (OpenAI / Fireworks AI / Ollama)

The `VectorizationService::generateEmbedding(string $text, ?string $provider = null)` entrypoint MUST resolve the active embedding configuration from `SettingsService::getLLMSettingsOnly()` when `$provider` is `null`, otherwise honour the explicit override. The configured provider MUST be one of `openai`, `fireworks`, or `ollama`; any other provider name MUST throw `Unsupported embedding provider: {provider}` from `EmbeddingGeneratorHandler::getGenerator()`. The handler MUST cache generator instances keyed by `"{provider}_{model}"` and reuse them across calls.

Each provider has a distinct construction path: OpenAI MUST route through one of `OpenAIADA002EmbeddingGenerator`, `OpenAI3SmallEmbeddingGenerator`, or `OpenAI3LargeEmbeddingGenerator` based on the model name; unknown OpenAI models MUST throw `Unsupported OpenAI model: {model}`. Fireworks MUST be wrapped in an anonymous LLPhant-compatible adapter that posts directly to `{base_url}/embeddings` with `Authorization: Bearer {api_key}` and returns `data[0].embedding`. Ollama MUST instantiate `OllamaEmbeddingGenerator` with `url = rtrim(base_url, '/') + '/api/'`.

`generateEmbedding()` MUST return `['embedding' => array<float>, 'model' => string, 'dimensions' => int]`. The dimensions value MUST come from `EmbeddingGeneratorHandler::getDefaultDimensions()` which maps `text-embedding-ada-002` and `text-embedding-3-small` to 1536, `text-embedding-3-large` to 3072, `ollama-default` to 384, and any unknown model to a fallback of 1536.

Batch generation (`generateBatchEmbeddings(array $texts)`) MUST iterate texts serially (no provider-side batching is attempted) and MUST tolerate per-text failures: a failed call MUST yield `['embedding' => null, 'dimensions' => 0, 'error' => string]` for that index without aborting the batch. The aggregate result MUST be returned in input order.

The admin-facing test affordance (`testEmbedding(string $provider, array $config, string $testText = 'Test.')`) MUST validate the supplied config (`provider` and `model` required; `api_key` required for `openai` and `fireworks`), generate one embedding, and return `['success' => bool, 'message' => string, 'data' => ['provider', 'model', 'vectorLength', 'sampleValues' => first 5 floats, 'testText']]`. On failure the response MUST be `['success' => false, 'error' => string, 'message' => "Failed to generate embedding: ..."]` — the call MUST NOT throw.

#### Scenario: Default provider resolved from settings on simple generate call
- **GIVEN** `SettingsService::getLLMSettingsOnly()` returns `embeddingProvider: "openai"` and `openaiConfig: {model: "text-embedding-3-small", apiKey: "sk-..."}`
- **WHEN** `VectorizationService::generateEmbedding('hello')` is invoked with no provider override
- **THEN** `EmbeddingGeneratorHandler::createOpenAIGenerator('text-embedding-3-small', ...)` MUST be invoked
- **AND** the cached generator MUST be reused on the next call with the same provider/model pair
- **AND** the response MUST be `['embedding' => array<float>, 'model' => 'text-embedding-3-small', 'dimensions' => 1536]`

#### Scenario: Unsupported provider throws on getGenerator
- **GIVEN** a caller invokes `generateEmbedding('hello', 'cohere')`
- **WHEN** `EmbeddingGeneratorHandler::getGenerator(['provider' => 'cohere', ...])` runs
- **THEN** an `Exception` with message `Unsupported embedding provider: cohere` MUST be thrown
- **AND** no entry MUST be written to `$generatorCache`

#### Scenario: Custom-config test rejects missing API key for OpenAI / Fireworks
- **GIVEN** the admin LLM settings page POSTs `{provider: "openai", model: "text-embedding-3-large", apiKey: ""}` to the test endpoint
- **WHEN** `VectorEmbeddings::generateEmbeddingWithCustomConfig(...)` validates the payload
- **THEN** an `Exception` with message `API key is required for openai` MUST be thrown
- **AND** the wrapping `testEmbedding()` MUST catch it and return `['success' => false, 'error' => 'API key is required for openai', 'message' => 'Failed to generate embedding: ...']`

#### Scenario: Batch generation tolerates per-text failure
- **GIVEN** three texts `['ok-1', 'ok-2', 'ok-3']` are passed to `generateBatchEmbeddings`
- **AND** the second call to `EmbeddingGeneratorInterface::embedText` throws an HTTP-500 error
- **WHEN** the batch loop completes
- **THEN** the result MUST be an array of length 3 in input order
- **AND** index 1 MUST be `['embedding' => null, 'model' => string, 'dimensions' => 0, 'error' => string]`
- **AND** indices 0 and 2 MUST be successful `['embedding' => array<float>, 'model' => string, 'dimensions' => int]` entries

#### Scenario: Fireworks adapter posts directly without LLPhant client wrapping
- **GIVEN** the configured provider is `fireworks` with `model: "thenlper/gte-base"` and `base_url: "https://api.fireworks.ai/inference/v1"`
- **WHEN** `EmbeddingGeneratorHandler::createFireworksGenerator(...)` is invoked
- **THEN** an anonymous class implementing `EmbeddingGeneratorInterface` MUST be returned
- **AND** calling `embedText('hello')` on that instance MUST issue a `POST https://api.fireworks.ai/inference/v1/embeddings` with `Authorization: Bearer {api_key}` and body `{model: "thenlper/gte-base", input: "hello"}`
- **AND** the embedding length for `thenlper/gte-base` MUST be reported as 768 by `getEmbeddingLength()`

### Requirement: The system MUST route vector storage to a configured backend (Postgres BLOB or Solr atomic update) with UTF-8 sanitisation

`VectorEmbeddings::storeVector(...)` MUST resolve the backend by reading `llmSettings.vectorConfig.backend` from `SettingsService::getLLMSettingsOnly()`, defaulting to `'php'` on missing/error. The backend value MUST then be passed to `VectorStorageHandler::storeVector(..., string $backend)` which MUST dispatch to either `storeVectorInSolr` (when backend === `'solr'`) or `storeVectorInDatabase` (default).

**Database path** (`storeVectorInDatabase`) MUST insert a row into `openregister_vectors` with: `entity_type`, `entity_id`, `chunk_index`, `total_chunks`, `chunk_text` (after sanitisation), `embedding` (PHP-`serialize()`'d to a BLOB via `PDO::PARAM_LOB`), `embedding_model`, `embedding_dimensions`, `metadata` (JSON-encoded when non-empty), and `created_at` / `updated_at` set to `date('Y-m-d H:i:s')`. The autoincrement `id` MUST be returned. Sanitisation (`sanitizeText`) MUST: (1) `mb_convert_encoding($text, 'UTF-8', 'UTF-8')`, (2) strip `\x00-\x08\x0B\x0C\x0E-\x1F\x7F` control characters, (3) `iconv('UTF-8', 'UTF-8//IGNORE', $text)`, (4) collapse whitespace via `preg_replace('/\s+/u', ' ', $text)`, (5) `trim()`.

**Solr path** (`storeVectorInSolr`) MUST resolve the target collection by entity type: `file` / `files` MUST use `settings.solr.fileCollection`; anything else MUST use `settings.solr.objectCollection` (or fall back to `settings.solr.collection`). A missing collection MUST throw `Solr collection not configured for entity type: {entityType}`. The Solr vector field name MUST come from `settings.llm.vectorConfig.solrField`, defaulting to `'_embedding_'`. The document ID MUST be `entity_id` for objects and `"{entity_id}_chunk_{chunk_index}"` for files. The Solr atomic update payload MUST be `[{id, {vectorField}: {set: embedding}, _embedding_model_: {set: model}, _embedding_dim_: {set: dimensions}, self_updated: {set: gmdate('Y-m-d\TH:i:s\Z')}}]`. The backend instance MUST be an instance of `SolrBackend` (cast from `IndexService::getBackend()`) and MUST report `isAvailable() === true`; otherwise the call MUST throw. On Solr response with `responseHeader.status !== 0` (or missing), the call MUST throw `Solr atomic update failed: {responseJson}`. The Solr path MUST return the string document ID; `VectorStorageHandler::storeVector` then MUST return `crc32(documentId)` as the int facade value.

Any failure in either path MUST be re-wrapped as `Vector storage failed: {message}` (or `Solr vector storage failed: ...`) before being thrown.

#### Scenario: Default backend is PHP (database) when settings unavailable
- **GIVEN** `SettingsService::getLLMSettingsOnly()` throws an exception
- **WHEN** `VectorEmbeddings::getVectorSearchBackend()` is invoked
- **THEN** a warning MUST be logged `Failed to get vector search backend, defaulting to PHP`
- **AND** the resolved backend MUST be `'php'`
- **AND** `VectorStorageHandler::storeVectorInDatabase(...)` MUST be the path taken

#### Scenario: Database insert serialises embedding as a PHP BLOB
- **GIVEN** an embedding `[0.1, 0.2, 0.3]` for object `'abc-uuid'` with model `text-embedding-3-small`
- **WHEN** `storeVectorInDatabase(...)` runs
- **THEN** the inserted row's `embedding` column MUST contain `serialize([0.1, 0.2, 0.3])` bound as `PDO::PARAM_LOB`
- **AND** the inserted row's `embedding_dimensions` MUST equal 3
- **AND** the function MUST return the autoincrement `id` of the new row

#### Scenario: Solr file-type document ID embeds chunk index
- **GIVEN** a file vector with `entity_id = 'file-uuid'`, `chunk_index = 2`, `entityType = 'file'`
- **WHEN** `storeVectorInSolr(...)` builds the atomic-update document
- **THEN** the document `id` MUST equal `"file-uuid_chunk_2"`
- **AND** the payload MUST include `_embedding_dim_: {set: dimensions}` and `self_updated: {set: gmdate('Y-m-d\TH:i:s\Z')}`
- **AND** `VectorStorageHandler::storeVector` MUST return `crc32("file-uuid_chunk_2")` to the caller

#### Scenario: Solr unavailable triggers re-wrap
- **GIVEN** backend is `'solr'` and `IndexService::getBackend()->isAvailable()` returns `false`
- **WHEN** `storeVectorInSolr(...)` is invoked
- **THEN** an `Exception` with message `Solr vector storage failed: Solr service is not available` MUST be thrown
- **AND** the original message MUST be wrapped in the outer `storeVector` catch as `Vector storage failed: Solr vector storage failed: Solr service is not available`

#### Scenario: sanitizeText strips control chars and normalises whitespace
- **GIVEN** input `"hello\x00world\t\t\nfoo"`
- **WHEN** `VectorStorageHandler::sanitizeText(...)` is invoked
- **THEN** the output MUST equal `"helloworld foo"` (NUL byte removed, tab+newline collapsed to single space, trimmed)

### Requirement: The system MUST orchestrate batch vectorisation through a pluggable strategy interface with per-entity error capture

`VectorizationService` MUST expose `registerStrategy(string $entityType, VectorizationStrategyInterface $strategy)` to bind a strategy to an entity-type identifier (e.g. `'object'`, `'file'`). `vectorizeBatch(string $entityType, array $options = [])` MUST resolve the strategy via `getStrategy()`, MUST throw `No vectorization strategy registered for entity type: {entityType}` when none is registered, and MUST invoke `strategy->fetchEntities($options)` to obtain the work list.

When `fetchEntities` returns `[]`, the call MUST short-circuit with `['success' => true, 'message' => 'No entities found to vectorize', 'entity_type', 'total_entities' => 0, 'total_items' => 0, 'vectorized' => 0, 'failed' => 0]`. Otherwise the orchestrator MUST iterate entities, calling `vectorizeEntity()` for each, and MUST accumulate per-entity errors into a top-level `errors` array without aborting the batch on a single failure.

`vectorizeEntity()` MUST first call `strategy->extractVectorizationItems($entity)`. When items is `[]`, the entity MUST be skipped (returning zero counts). For non-empty items, the orchestrator MUST select serial vs parallel mode based on `options.mode` (default `'serial'`) and `options.batch_size` (default 50): parallel MUST batch into chunks of `batch_size` and call `VectorEmbeddings::generateBatchEmbeddings()`, while serial MUST call `generateEmbedding()` per item.

After embedding generation, successful vectors MUST be passed to `storeVector()` which MUST delegate to `strategy->prepareVectorMetadata($entity, $item)` to assemble the storage payload (`entity_type`, `entity_id`, `chunk_index`, `total_chunks`, `chunk_text`, `additional_metadata`) before calling `VectorEmbeddings::storeVector(...)`. Per-item failures (null embedding or thrown exception) MUST be recorded in the `errors` array as `['entity_id' => ..., 'item_index' => ..., 'error' => ...]` and incremented in the `failed` counter.

The aggregate response MUST be `['success' => true, 'message' => "Batch vectorization completed: {V} vectorized, {F} failed", 'entity_type', 'total_entities' => count(entities), 'total_items', 'vectorized', 'failed', 'errors']`. Top-level orchestration failures (e.g. strategy fetch throws) MUST be re-thrown after error logging — they MUST NOT be swallowed.

`VectorizationStrategyInterface` MUST define the four-method contract: `fetchEntities(array $options): array`, `extractVectorizationItems(mixed $entity): array`, `prepareVectorMetadata(mixed $entity, array $item): array`, `getEntityIdentifier(mixed $entity): string|int`. The metadata return MUST carry `entity_type`, `entity_id`, optional `chunk_index` (default 0), optional `total_chunks` (default 1), optional `chunk_text`, optional `additional_metadata`.

#### Scenario: Unregistered entity type throws on vectorizeBatch
- **GIVEN** no strategy has been registered for `'image'`
- **WHEN** `VectorizationService::vectorizeBatch('image', [])` is invoked
- **THEN** an `Exception` with message `No vectorization strategy registered for entity type: image` MUST be thrown
- **AND** the exception MUST be re-thrown after error logging — the orchestrator MUST NOT return a normal result

#### Scenario: Empty fetch returns success with zero counts
- **GIVEN** a registered strategy whose `fetchEntities([])` returns `[]`
- **WHEN** `vectorizeBatch('object', [])` is invoked
- **THEN** the response MUST be `['success' => true, 'message' => 'No entities found to vectorize', 'entity_type' => 'object', 'total_entities' => 0, 'total_items' => 0, 'vectorized' => 0, 'failed' => 0]`
- **AND** no calls to `strategy->extractVectorizationItems` MUST be made

#### Scenario: Per-entity failure does not abort the batch
- **GIVEN** three entities are returned by `fetchEntities`
- **AND** `vectorizeEntity` for the second entity throws (strategy `extractVectorizationItems` raises)
- **WHEN** the orchestrator loop completes
- **THEN** the result MUST report `total_entities: 3`
- **AND** the `errors` array MUST contain exactly one entry for the failing entity: `['entity_id' => <id>, 'error' => <message>]`
- **AND** entities 1 and 3 MUST have contributed normally to `vectorized` / `failed` / `total_items`

#### Scenario: Parallel mode batches via generateBatchEmbeddings; serial mode loops generateEmbedding
- **GIVEN** an entity that yields 10 items, with `options = ['mode' => 'parallel', 'batch_size' => 4]`
- **WHEN** `vectorizeEntity()` processes the items
- **THEN** `VectorEmbeddings::generateBatchEmbeddings()` MUST be invoked exactly three times (chunks of 4, 4, 2)
- **AND** `VectorEmbeddings::generateEmbedding()` MUST NOT be called on this path
- **AND** with `options = ['mode' => 'serial']` the inverse MUST hold (10 calls to `generateEmbedding`, 0 to `generateBatchEmbeddings`)

#### Scenario: Per-item null embedding records error and increments failed
- **GIVEN** parallel mode returns an embedding result where index 2 has `embedding => null`
- **WHEN** the orchestrator inspects the result
- **THEN** `failed` MUST be incremented for that item
- **AND** an entry `['entity_id', 'item_index' => 2, 'error' => <err msg or 'Embedding generation failed'>]` MUST be appended to `errors`
- **AND** `storeVector` MUST NOT be called for that item

### Requirement: The system MUST vectorise OpenRegister objects via a strategy that JSON-serialises content and folds register/schema/uuid metadata

`ObjectVectorizationStrategy` MUST implement `VectorizationStrategyInterface`. `fetchEntities($options)` MUST call `ObjectService::searchObjects(query: ['_limit' => $options['batch_size'] ?? 25, '_source' => 'database'], _rbac: false, _multitenancy: false, ids: null, uses: null, views: $options['views'] ?? null)` and MUST return the resulting list as an array (or `[]` when the return is not an array).

`extractVectorizationItems($entity)` MUST always produce exactly one item per object: `[['text' => json_encode($objectData, JSON_PRETTY_PRINT), 'index' => 0]]`. `$objectData` MUST come from `$entity->jsonSerialize()` (or `$entity` itself when already an array). `SettingsService::getObjectSettingsOnly()` MUST be consulted for serialisation config (`includeMetadata`, `includeRelations`, `maxNestingDepth`), but the current implementation MUST emit those values in debug logs and then ignore them (the actual encoding is unconditional `json_encode(..., JSON_PRETTY_PRINT)`).

`prepareVectorMetadata($entity, $item)` MUST extract a title using the fallback chain: (1) `objectData['title']`, (2) `['name']`, (3) `['_name']`, (4) `['summary']`, (5) `extractFirstStringField()` — the first non-`_`/non-`@`-prefixed key whose value is a string of length 1–99 and whose key is not in the skip set `[id, uuid, description, Beschrijving, beschrijving, content, text]`, (6) terminal fallback `"Object #{$objectId}"`. Description MUST be extracted via the chain: `description`, `_description`, `Beschrijving`, `beschrijving`, `summary`, `_summary`, default `""`.

Register / schema / UUID / URI MUST be folded from multiple possible locations into the metadata: `register` from `_register` → `register` → `@self.register`; `schema` from `_schema` → `schema` → `@self.schema`; `uuid` from `uuid` → `_uuid` → `@self.id`; `uri` from `uri` → `_uri` → `@self.uri`. The returned metadata MUST be:

```
['entity_type' => 'object',
 'entity_id' => (string) $objectId,           // 'unknown' when id is missing
 'chunk_index' => 0,
 'total_chunks' => 1,
 'chunk_text' => substr($item['text'], 0, 500),
 'additional_metadata' => [
   'object_id', 'object_title', 'title', 'name', 'description',
   'register', 'register_id', 'schema', 'schema_id', 'uuid', 'uri'
 ]]
```

`title` and `object_title` and `name` MUST all carry the same resolved title value. `register` and `register_id` MUST carry the same value (likewise `schema` / `schema_id`). The `chunk_text` field MUST be truncated to 500 chars of the serialised JSON.

`getEntityIdentifier($entity)` MUST return `objectData['id']` when set, else the string `'unknown'`.

#### Scenario: Single item produced per object regardless of size
- **GIVEN** an `ObjectEntity` whose `jsonSerialize()` returns a 50 KB object
- **WHEN** `extractVectorizationItems($entity)` is invoked
- **THEN** the result MUST be an array of length exactly 1
- **AND** that item MUST be `['text' => <JSON-encoded object, pretty-printed>, 'index' => 0]`
- **AND** the JSON MUST include all top-level keys present in the source object

#### Scenario: Title fallback chain reaches `extractFirstStringField` when standard keys missing
- **GIVEN** an object `{label: "Resident #42", description: "Some long text", other: 12345}`
- **WHEN** `prepareVectorMetadata` extracts the title
- **THEN** the title MUST be `"Resident #42"` (matched via `extractFirstStringField` because `title`, `name`, `_name`, and `summary` are all missing, and `label` is the first eligible non-skip key)
- **AND** the title MUST NOT be `12345` (integer is rejected) and MUST NOT be `"Some long text"` (description is in the skip set)

#### Scenario: Register/schema folded from multiple legacy locations
- **GIVEN** an object whose top-level lacks `register`/`schema` but has `@self: {register: "9", schema: "12", id: "abc-uuid"}`
- **WHEN** `prepareVectorMetadata` runs
- **THEN** the returned `additional_metadata` MUST have `register: "9"`, `register_id: "9"`, `schema: "12"`, `schema_id: "12"`, `uuid: "abc-uuid"`
- **AND** the same values MUST be present even if the object instead used `_register: "9"`, `_schema: "12"`, `_uuid: "abc-uuid"`

#### Scenario: Missing id falls back to entity_id 'unknown'
- **GIVEN** an object whose `jsonSerialize()` returns no `id` key
- **WHEN** `getEntityIdentifier($entity)` is invoked
- **THEN** the result MUST be the string `'unknown'`
- **AND** the metadata `entity_id` MUST also be `'unknown'`
- **AND** the metadata `object_title` MUST be `"Object #unknown"`

#### Scenario: chunk_text is truncated to 500 chars
- **GIVEN** a serialised object JSON of length 12 000 chars
- **WHEN** `prepareVectorMetadata` populates `chunk_text`
- **THEN** the resulting `chunk_text` MUST be exactly the first 500 chars of `$item['text']`

### Requirement: The system MUST expose vector statistics and surface embedding-model rotation needs

`VectorizationService::getVectorStats()` MUST resolve the configured backend (same logic as REQ-002), then delegate to `VectorStatsHandler::getStats(string $backend)`. On `'solr'` the handler MUST query the configured object/file collections via `_embedding_:* facet=true facet.field=_embedding_model_` and aggregate counts; on any other backend it MUST run three queryBuilder calls on `openregister_vectors` (total via `COUNT(*)`, breakdown via `GROUP BY entity_type`, breakdown via `GROUP BY embedding_model`). The returned shape MUST be `['total_vectors', 'by_type' => array<string,int>, 'by_model' => array<string,int>, 'object_vectors' => int, 'file_vectors' => int]`. Solr responses MUST additionally include `'source' => 'solr' | 'solr_error' | 'solr_unavailable'`. On any exception the handler MUST return `['total_vectors' => 0, 'by_type' => [], 'by_model' => []]` rather than throw.

`VectorEmbeddings::checkEmbeddingModelMismatch()` MUST compare the currently-configured embedding model (from `SettingsService::getLLMSettingsOnly()`, mapped by provider: `openaiConfig.model`, `ollamaConfig.model`, `fireworksConfig.embeddingModel`) against the distinct `embedding_model` values found in `openregister_vectors`. The response MUST be one of:

- `['has_vectors' => false, 'mismatch' => false, 'message' => 'No embedding model configured']` — when no current model is configured.
- `['has_vectors' => false, 'mismatch' => false, 'current_model' => string, 'message' => 'No vectors exist yet']` — when the table is empty.
- `['has_vectors' => true, 'mismatch' => bool, 'current_model', 'existing_models' => array<string>, 'total_vectors' => int, 'null_model_count' => int, 'mismatched_models' => array<string>, 'message' => string]` — when vectors exist. `mismatch` MUST be `true` if any stored model differs from current OR if `null_model_count > 0`.

The user-facing `message` MUST be one of three strings depending on state: `Multiple embedding models detected. Consider re-embedding all vectors with a single model.` (any drift), `{N} vectors have no model information.` (only NULL models), `All vectors use the same embedding model.` (no drift). On exception the response MUST be `['has_vectors' => false, 'mismatch' => false, 'error' => string]`.

`VectorEmbeddings::clearAllEmbeddings()` MUST count the rows in `openregister_vectors`, return `['success' => true, 'deleted' => 0, 'message' => 'No vectors to delete']` when empty, otherwise `DELETE FROM openregister_vectors` and return `['success' => true, 'deleted' => int, 'message' => "Deleted {N} vectors successfully"]`. On exception the response MUST be `['success' => false, 'error' => string, 'message' => 'Failed to clear embeddings: ...']` — the call MUST NOT throw.

#### Scenario: Database stats return total + by-type + by-model breakdown
- **GIVEN** `openregister_vectors` contains 100 rows: 70 with `entity_type='object'`, 30 with `entity_type='file'`, split across models `text-embedding-3-small` (60) and `nomic-embed-text` (40)
- **WHEN** `VectorizationService::getVectorStats()` is invoked with backend = `'php'`
- **THEN** the response MUST be `['total_vectors' => 100, 'by_type' => ['object' => 70, 'file' => 30], 'by_model' => ['text-embedding-3-small' => 60, 'nomic-embed-text' => 40], 'object_vectors' => 70, 'file_vectors' => 30]`
- **AND** no `'source'` key MUST be present (that key is Solr-only)

#### Scenario: Solr stats include `source` discriminator
- **GIVEN** backend = `'solr'` and `IndexService::getBackend()->isAvailable() === false`
- **WHEN** `getVectorStats()` is invoked
- **THEN** the response MUST include `'source' => 'solr_unavailable'` and all numeric fields zeroed
- **AND** on a successful Solr call the response MUST include `'source' => 'solr'`
- **AND** on a thrown error inside Solr query path the response MUST include `'source' => 'solr_error'`

#### Scenario: Model mismatch flagged when stored vectors use a different model than current settings
- **GIVEN** current `openaiConfig.model` is `text-embedding-3-large` and the vectors table holds 50 rows with `embedding_model = text-embedding-ada-002`
- **WHEN** `checkEmbeddingModelMismatch()` is invoked
- **THEN** the response MUST be `['has_vectors' => true, 'mismatch' => true, 'current_model' => 'text-embedding-3-large', 'existing_models' => ['text-embedding-ada-002'], 'total_vectors' => 50, 'null_model_count' => 0, 'mismatched_models' => ['text-embedding-ada-002'], 'message' => 'Multiple embedding models detected. Consider re-embedding all vectors with a single model.']`

#### Scenario: NULL embedding_model counted separately and surfaces re-embed need
- **GIVEN** the vectors table holds 30 rows with `embedding_model = text-embedding-3-large` (matching current settings) and 5 rows with `embedding_model IS NULL`
- **WHEN** `checkEmbeddingModelMismatch()` is invoked
- **THEN** the response MUST include `mismatch: true` and `null_model_count: 5` and `mismatched_models: []`
- **AND** the message MUST be `5 vectors have no model information.`

#### Scenario: clearAllEmbeddings on empty table is a no-op
- **GIVEN** `openregister_vectors` is empty
- **WHEN** `clearAllEmbeddings()` is invoked
- **THEN** the response MUST be `['success' => true, 'deleted' => 0, 'message' => 'No vectors to delete']`
- **AND** no `DELETE` statement MUST be issued

#### Scenario: clearAllEmbeddings deletes all rows and returns count
- **GIVEN** the table holds 123 rows
- **WHEN** `clearAllEmbeddings()` is invoked
- **THEN** the table MUST be emptied via `DELETE FROM openregister_vectors`
- **AND** the response MUST be `['success' => true, 'deleted' => 123, 'message' => 'Deleted 123 vectors successfully']`


# Retrofit — Phase 4c new capability `vector-embeddings`

## Why

The 2026-05-24 coverage scan identified `lib/Service/VectorizationService.php` and the six files under `lib/Service/Vectorization/` (48 methods total) as Bucket 2c — code exists, but no capability spec describes embedding generation, vector storage, batch vectorisation, or vector statistics. `chat-ai/spec.md` references RAG and embeddings at the consumer level (REQ-001 mentions "retrieves relevant context from registered objects and Nextcloud files using the active agent's RAG configuration") but it does not specify how embeddings are produced, where vectors live, how they are indexed, or how a model rotation is detected. The `search` capability (PR #1791 — not merged on this branch) describes the *query* layer (KNN / cosine / RRF in `VectorSearchHandler`), not the *generation* + *storage* + *batch* layer.

This change mints a new `vector-embeddings` capability to close that gap.

## What the cluster actually contains

Seven files, 48 methods, decomposing into five coherent behavioral domains:

| Domain                                  | Files / methods                                                                                                                                                                                                                                                                                                                                                          |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1. Multi-provider embedding generation  | `Handlers/EmbeddingGeneratorHandler.php`: `getGenerator`, `getDefaultDimensions`, `createOpenAIGenerator`, `createFireworksGenerator`, `createOllamaGenerator`. `Vectorization/VectorEmbeddings.php`: `generateEmbedding`, `generateEmbeddingWithCustomConfig`, `generateBatchEmbeddings`, `testEmbedding`, `getEmbeddingConfig`.                                          |
| 2. Vector storage backend routing       | `Handlers/VectorStorageHandler.php`: `storeVector`, `storeVectorInDatabase`, `storeVectorInSolr`, `getSolrCollectionForEntityType`, `getSolrVectorField`, `sanitizeText`. `Vectorization/VectorEmbeddings.php`: `storeVector`, `getVectorSearchBackend`.                                                                                                                  |
| 3. Strategy-driven batch vectorisation  | `VectorizationService.php`: `registerStrategy`, `vectorizeBatch`, `vectorizeEntity`, `storeVector`, `getStrategy`. `Strategies/VectorizationStrategyInterface.php`: `fetchEntities`, `extractVectorizationItems`, `prepareVectorMetadata`, `getEntityIdentifier`.                                                                                                          |
| 4. Object vectorisation strategy        | `Strategies/ObjectVectorizationStrategy.php`: `fetchEntities`, `extractVectorizationItems`, `prepareVectorMetadata`, `extractSelfKeys`, `extractFirstStringField`, `getEntityIdentifier`, `serializeObject`.                                                                                                                                                              |
| 5. Vector statistics + model rotation   | `Handlers/VectorStatsHandler.php`: `getStats`, `getStatsFromDatabase`, `getStatsFromSolr`, `countVectorsInCollection`. `Vectorization/VectorEmbeddings.php`: `getVectorStats`, `checkEmbeddingModelMismatch`, `clearAllEmbeddings`. `VectorizationService.php` facade: `getVectorStats`, `testEmbedding`, `checkEmbeddingModelMismatch`, `clearAllEmbeddings`.             |

## Approach

One new capability: `vector-embeddings`, with 5 REQs (one per domain). Each REQ describes observed behavior at the public-API boundary (`VectorizationService` facade) and dives into the handler/strategy detail in scenarios.

The facade methods on `VectorizationService` and `VectorEmbeddings` that **delegate** to `VectorSearchHandler::semanticSearch` / `::hybridSearch` (i.e. `VectorizationService::semanticSearch`, `::hybridSearch`, `VectorEmbeddings::semanticSearch`, `::hybridSearch`) are intentionally **NOT** specced here — the query-execution layer is owned by `search/spec.md` REQ-002 (PR #1791). The four facade entrypoints route a query through `generateEmbedding()` (in scope) then hand off to `VectorSearchHandler` (out of scope), so annotating them here would create a double-spec.

For each REQ, scenarios describe observed behavior on the current implementation (not aspirational). The Notes section flags inconsistencies (serialized blob format, hardcoded fallback dimensions, missing pgvector / Postgres-native path, etc.).

## Affected code units

**vector-embeddings — 42 method annotations across 7 files:**

REQ-001 — multi-provider embedding generation:
- `lib/Service/VectorizationService.php::generateEmbedding`, `::testEmbedding`
- `lib/Service/Vectorization/VectorEmbeddings.php::generateEmbedding`, `::generateEmbeddingWithCustomConfig`, `::generateBatchEmbeddings`, `::testEmbedding`, `::getEmbeddingConfig`
- `lib/Service/Vectorization/Handlers/EmbeddingGeneratorHandler.php::getGenerator`, `::getDefaultDimensions`, `::createOpenAIGenerator`, `::createFireworksGenerator`, `::createOllamaGenerator`

REQ-002 — vector storage backend routing:
- `lib/Service/Vectorization/VectorEmbeddings.php::storeVector`, `::getVectorSearchBackend`
- `lib/Service/Vectorization/Handlers/VectorStorageHandler.php::storeVector`, `::storeVectorInDatabase`, `::storeVectorInSolr`, `::getSolrCollectionForEntityType`, `::getSolrVectorField`, `::sanitizeText`

REQ-003 — strategy-driven batch vectorisation:
- `lib/Service/VectorizationService.php::registerStrategy`, `::vectorizeBatch`, `::vectorizeEntity`, `::storeVector`, `::getStrategy`
- `lib/Service/Vectorization/Strategies/VectorizationStrategyInterface.php::fetchEntities`, `::extractVectorizationItems`, `::prepareVectorMetadata`, `::getEntityIdentifier`

REQ-004 — object vectorisation strategy:
- `lib/Service/Vectorization/Strategies/ObjectVectorizationStrategy.php::fetchEntities`, `::extractVectorizationItems`, `::prepareVectorMetadata`, `::extractSelfKeys`, `::extractFirstStringField`, `::getEntityIdentifier`, `::serializeObject`

REQ-005 — vector statistics + model rotation:
- `lib/Service/VectorizationService.php::getVectorStats`, `::checkEmbeddingModelMismatch`, `::clearAllEmbeddings`
- `lib/Service/Vectorization/VectorEmbeddings.php::getVectorStats`, `::checkEmbeddingModelMismatch`, `::clearAllEmbeddings`
- `lib/Service/Vectorization/Handlers/VectorStatsHandler.php::getStats`, `::getStatsFromDatabase`, `::getStatsFromSolr`, `::countVectorsInCollection`

## Out of scope

- **Vector *query* execution.** `VectorSearchHandler::semanticSearch` / `::hybridSearch` are owned by `search/spec.md` REQ-002 (PR #1791). The four facade entrypoints in `VectorizationService` / `VectorEmbeddings` that delegate to those handler methods are deliberately **DROP**ed from this run so we do not double-spec the KNN / cosine / RRF contract. Once #1791 merges, those four facade methods can be `@spec`-annotated against `search/spec.md` REQ-002 in a tiny follow-up.
- **Settings UI / config storage.** `SettingsService::getLLMSettingsOnly` and the admin LLM configuration view live in `chat-ai` (settings shape) and are not respecced here.
- **Solr index lifecycle.** Collection creation, schema upload, and Solr connectivity health are owned by the index/search capability and are not re-described here. This cap consumes Solr through `IndexService->getBackend()` only.
- **File vectorisation strategy.** No `FileVectorizationStrategy.php` exists yet in this tree — only `ObjectVectorizationStrategy` is observable. The `Strategies/` directory and `VectorizationStrategyInterface` document the extension point, but file-specific behavior is left for a future retrofit run when the file strategy code lands.
- Any reshaping or "fixing" of observed behavior. Drift (PHP-`serialize()`'d blobs, hardcoded fallback dimensions, missing pgvector path, asymmetric Solr/DB chunk handling) is flagged in the Notes section, not silently corrected.

Source: `/tmp/or-scan/rspec-newcap-vector-embeddings-and-semantic-search.json` (48 methods, 7 files). Retrofit playbook: `.github/docs/claude/retrofit.md`. Sibling reference: PR #1791 (`search`) and `chat-ai/spec.md`.

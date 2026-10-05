## ADDED Requirements

### Requirement: FileSearchController MUST scope semantic search to file entities and MUST NOT silently ignore the scope filter

`FileSearchController::semanticSearch()` (route `POST /api/search/files/semantic`) MUST pass `filters: ['entity_type' => 'file']` to `VectorizationService::semanticSearch()` — using the `entity_type` (snake_case) key that `VectorSearchHandler::fetchVectors()` actually reads. A request MUST NOT return results from non-file entity types (e.g. `object`) when this endpoint is called.

#### Scenario: Semantic file search excludes object-type vectors
- **GIVEN** `openregister_vectors` holds vectors for both `entity_type = 'object'` and `entity_type = 'file'`, and the query text is more similar to a stored `object` vector than to any `file` vector
- **WHEN** `POST /api/search/files/semantic` is called with that query
- **THEN** the `filters` passed to `VectorSearchHandler::fetchVectors()` MUST include `entity_type => 'file'`
- **AND** the returned `results` MUST only contain entries with `entity_type = 'file'`

#### Scenario: Missing query parameter returns 400
- **GIVEN** a request to `POST /api/search/files/semantic` with no `query` parameter
- **WHEN** the controller handles the request
- **THEN** the response MUST be a 400 JSON response `{success: false, message: 'Query parameter is required'}`

### Requirement: FileSearchController MUST return a correctly-shaped, correctly-counted hybrid search response

`FileSearchController::hybridSearch()` (route `POST /api/search/files/hybrid`) MUST call `ChunkMapper::searchByKeyword()` (scoped to `source_type = 'file'`) to obtain ranked keyword results, then call `VectorizationService::hybridSearch(query, keywordResults: <those results>, limit, weights)`. The controller MUST destructure the service's response and return a flat JSON body: `{success: true, query, results: <service response's 'results' array>, total: <service response's 'total' integer>, search_time_ms, source_breakdown, weights, search_type: 'hybrid'}`. The endpoint MUST NOT nest the service's full response object inside the `results` key, and `total` MUST NOT be computed from `count()` over the service's outer response array.

#### Scenario: Hybrid search response total matches the actual result count
- **GIVEN** a hybrid search query that produces 7 fused results
- **WHEN** `POST /api/search/files/hybrid` is called
- **THEN** the response `total` MUST equal `7`
- **AND** the response `results` MUST be a flat array of 7 result entries (not a nested object containing `results`/`total`/`weights`/etc.)

#### Scenario: Hybrid search keyword arm is populated from real chunk text matches
- **GIVEN** `openregister_chunks` contains a file chunk whose `text_content` matches the query term but whose embedding is not the top vector match
- **WHEN** `POST /api/search/files/hybrid` is called with `keyword_weight` > 0
- **THEN** that chunk's entity MUST appear in the fused results (contributed via the keyword arm)
- **AND** the response `source_breakdown` MUST report it under `keyword_only` or `both`, never silently dropped

#### Scenario: Missing query parameter returns 400
- **GIVEN** a request to `POST /api/search/files/hybrid` with no `query` parameter
- **WHEN** the controller handles the request
- **THEN** the response MUST be a 400 JSON response `{success: false, message: 'Query parameter is required'}`

### Requirement: The system MUST provide a ranked keyword-search path over chunk text, backed by a Postgres GIN index

`ChunkMapper::searchByKeyword(string $query, int $limit, array $filters = []): array` MUST, on PostgreSQL, query `WHERE to_tsvector('simple', text_content) @@ plainto_tsquery('simple', :query)`, ranked by `ts_rank(to_tsvector('simple', text_content), plainto_tsquery('simple', :query)) DESC`, honouring an optional `source_type` filter, and limited to `$limit` rows — the expression form matching the functional GIN index `idx_or_chunks_text_search_gin` (a STORED `tsvector` generated column is not viable: an unknown column type on a prefix-matched table breaks Doctrine's schema introspection for every subsequent Nextcloud migration — implementation amendment 2026-07-06, see design.md). Each returned row MUST be shaped as `['entity_type' => 'file', 'entity_id' => <source_id>, 'score' => <ts_rank value>, 'chunk_text' => <text_content>, 'chunk_index', 'metadata' => []]` so it is directly consumable by `VectorSearchHandler::reciprocalRankFusion()`'s `$keywordResults` parameter. On any non-PostgreSQL platform, or when the query fails, the method MUST return `[]` and log a warning rather than throw.

#### Scenario: Keyword search ranks by ts_rank relevance
- **GIVEN** PostgreSQL is the active platform and three chunks contain the query term with differing term frequency/density
- **WHEN** `ChunkMapper::searchByKeyword('quarterly report', 10)` is invoked
- **THEN** the three matching chunks MUST be returned ordered by `ts_rank` descending
- **AND** each entry MUST carry `entity_type: 'file'`, `entity_id`, `score`, `chunk_text`, `chunk_index`

#### Scenario: Non-PostgreSQL platform returns empty result, not an error
- **GIVEN** the active platform is MariaDB
- **WHEN** `ChunkMapper::searchByKeyword('quarterly report', 10)` is invoked
- **THEN** the call MUST return `[]`
- **AND** a warning MUST be logged noting the keyword-search path is unavailable on this platform
- **AND** no exception MUST propagate to the caller

### Requirement: Chunks MUST be automatically vectorized in the background as they are extracted

A recurring background job MUST scan `openregister_chunks` WHERE `vectorized = false` (via `ChunkMapper::findUnvectorized()`) in bounded batches, generate embeddings via `VectorizationService`'s existing batch-embedding path, store them via `VectorStorageHandler::storeVector()`, and mark each successfully-processed chunk `vectorized = true`. A per-chunk failure MUST NOT abort the batch — the job MUST continue processing the remaining chunks in that run and pick up the failed chunk again on its next scheduled run.

#### Scenario: Unvectorized chunks become searchable without manual action
- **GIVEN** a file was uploaded and text-extracted (its chunks exist in `openregister_chunks` with `vectorized = false`)
- **AND** no admin has manually triggered batch vectorization
- **WHEN** the background job's next scheduled run completes
- **THEN** those chunks MUST have `vectorized = true`
- **AND** corresponding rows MUST exist in `openregister_vectors`
- **AND** a subsequent semantic search MUST be able to match content from that file

#### Scenario: A single failed embedding does not block the rest of the batch
- **GIVEN** a batch of 50 unvectorized chunks where the embedding provider call fails for one chunk
- **WHEN** the background job processes the batch
- **THEN** the 49 other chunks MUST be successfully vectorized and marked `vectorized = true`
- **AND** the failing chunk MUST remain `vectorized = false` for retry on the next scheduled run
- **AND** the failure MUST be logged, not thrown

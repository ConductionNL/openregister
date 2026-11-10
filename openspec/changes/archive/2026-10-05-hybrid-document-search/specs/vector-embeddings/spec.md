## MODIFIED Requirements

### Requirement: The system MUST execute vector queries via semantic KNN/cosine similarity and hybrid Reciprocal-Rank-Fusion search

`VectorEmbeddings::semanticSearch(string $query, int $limit = 10, array $filters = [], ?string $provider = null)` MUST first generate a query embedding for `$query` via `generateEmbedding()` (vector-embeddings REQ-001) and delegate to `VectorSearchHandler::semanticSearch(array $queryEmbedding, int $limit, array $filters)`. PostgreSQL is the sole vector storage/search backend (Solr was removed — `SearchBackendHandler` is database-only); there is no `$backend` parameter or Solr routing.

`VectorSearchHandler::semanticSearch` MUST prefer a SQL K-nearest-neighbour path on PostgreSQL: when the pgvector ANN fast path is populated for the filtered candidate set — implemented as the unprefixed sidecar table `openregister_vec_ann` (`vector_id -> embedding vector(N)`, cascade-deleted with the main row) rather than an in-table `embedding_vector` column, because a `vector`-typed column on a prefix-matched table breaks Doctrine's schema introspection for every subsequent Nextcloud migration (implementation amendment 2026-07-06, see design.md) — the call MUST execute `ORDER BY embedding <=> :queryVector LIMIT :limit` (cosine-distance operator, ascending = most similar first) against the HNSW index on the sidecar, joined to `openregister_vectors` for entity data, honouring `$filters['entity_type']` / `$filters['entity_id']` as WHERE predicates. When the fast path is unavailable (non-Postgres platform, extension not installed, sidecar absent, or the row's stored dimension doesn't match the currently-configured embedding dimension), the call MUST fall back to fetching candidate vectors from `openregister_vectors` (honouring the `$filters` predicate, ordered by `id ASC` — not `created_at DESC` — with a bounded `max_vectors` safety cap), `unserialize()` each stored embedding BLOB, compute `cosineSimilarity()` against the query embedding, sort by similarity descending, and return the top `$limit`. A vector row whose deserialised embedding is not an array MUST be skipped (not fatal) on the fallback path. When neither path finds any candidates the call MUST return `[]`. Any thrown error MUST be re-wrapped as `Semantic search failed: {message}`.

Each result entry MUST carry `vector_id`, `entity_type`, `entity_id`, `similarity`, `chunk_index`, `total_chunks`, `chunk_text`, `metadata` (JSON-decoded from the row, `[]` when absent), `model`, and `dimensions`, regardless of which path (KNN or PHP-fallback) produced it.

`VectorEmbeddings::hybridSearch(string $query, array $keywordFilters = [], int $limit = 20, array $weights = ['keyword' => 0.5, 'vector' => 0.5], ?string $provider = null)` MUST generate the query embedding and delegate to `VectorSearchHandler::hybridSearch(array $queryEmbedding, array $keywordResults, int $limit, array $weights)`, where `$keywordResults` MUST be the caller-supplied, already-executed keyword search results (e.g. `ChunkMapper::searchByKeyword()` output) — the handler itself does not execute keyword search; it only fuses results.

`VectorSearchHandler::hybridSearch` MUST normalise the supplied `keyword` / `vector` weights to sum to 1 (when their sum is `> 0`), run the vector leg via `semanticSearch` with `limit * 2` candidates ONLY when `vectorWeight > 0`, fuse the vector and keyword result sets via Reciprocal Rank Fusion (`reciprocalRankFusion()`), and return the top `$limit` fused results. A failure in the vector leg MUST be logged and tolerated (the keyword leg still contributes) — it MUST NOT abort hybrid search. The response MUST include `results`, `total` (the count of the fused result list, not of the response object's own keys), `search_time_ms`, a `source_breakdown` of `vector_only` / `keyword_only` / `both` counts, and the normalised `weights`.

#### Scenario: PostgreSQL KNN search ranks by pgvector cosine distance and is index-backed
- **GIVEN** the platform is PostgreSQL, the `pgvector` extension is installed, and `openregister_vectors` holds 5,000 rows with `openregister_vec_ann` sidecar rows populated at the currently-configured dimension
- **WHEN** `VectorEmbeddings::semanticSearch('find me', 10)` runs
- **THEN** the query MUST first be embedded via `generateEmbedding('find me')`
- **AND** the search MUST execute `ORDER BY embedding <=> :queryVector LIMIT 10` against the sidecar's HNSW index, not a PHP loop over all 5,000 rows
- **AND** the result MUST be the 10 nearest entries, each carrying `vector_id`, `entity_type`, `entity_id`, `similarity`, `chunk_text`, `model`, and `dimensions`

#### Scenario: Fallback PHP-cosine search ranks by similarity, not recency, and caps at limit
- **GIVEN** the platform is MariaDB (no pgvector fast path exists) and `openregister_vectors` holds 50 candidate rows for the filter
- **WHEN** `VectorEmbeddings::semanticSearch('find me', 10)` runs
- **THEN** candidate rows MUST be fetched ordered by `id ASC` (not `created_at DESC`)
- **AND** cosine similarity MUST be computed against each deserialised stored embedding
- **AND** the result MUST be the 10 highest-similarity entries sorted descending, each carrying `vector_id`, `entity_type`, `entity_id`, `similarity`, `chunk_text`, `model`, and `dimensions`

#### Scenario: Unparseable stored embedding is skipped, not fatal
- **GIVEN** one of the fetched vector rows on the PHP-fallback path deserialises to a non-array value
- **WHEN** the fallback semantic-search loop processes it
- **THEN** that row MUST be skipped with a warning log
- **AND** the remaining rows MUST still be scored and returned

#### Scenario: No candidates returns empty result on either path
- **GIVEN** `openregister_vectors` has no rows matching `$filters` (neither `embedding_vector`-populated nor BLOB-only)
- **WHEN** `VectorSearchHandler::semanticSearch(...)` runs
- **THEN** the call MUST return `[]`
- **AND** no cosine-similarity computation or KNN query MUST be attempted

#### Scenario: Hybrid search fuses real keyword results, not a hard-coded empty array
- **GIVEN** `$keywordResults` is a non-empty, already-ranked array (e.g. from `ChunkMapper::searchByKeyword()`) and `weights = ['keyword' => 0.5, 'vector' => 0.5]`
- **WHEN** `VectorSearchHandler::hybridSearch(queryEmbedding, $keywordResults, ...)` runs
- **THEN** both the vector and keyword result sets MUST be fused via Reciprocal Rank Fusion
- **AND** the response's `source_breakdown` MUST report non-zero `keyword_only` and/or `both` counts when keyword-matched entities exist
- **AND** `total` MUST equal `count($finalResults)` — the fused result list length, not the response object's own key count

#### Scenario: Hybrid search tolerates a failing vector leg
- **GIVEN** `weights = ['keyword' => 0.5, 'vector' => 0.5]` and the vector `semanticSearch` call throws
- **WHEN** `VectorSearchHandler::hybridSearch(...)` runs
- **THEN** the failure MUST be logged and swallowed (no exception propagates)
- **AND** the keyword results MUST still be fused and returned via Reciprocal Rank Fusion
- **AND** the response MUST include `results`, `total`, `search_time_ms`, `source_breakdown`, and the normalised `weights`

#### Scenario: Hybrid weights are normalised to sum to one
- **GIVEN** `weights = ['keyword' => 3, 'vector' => 1]`
- **WHEN** `hybridSearch(...)` normalises them
- **THEN** the effective weights MUST be `['keyword' => 0.75, 'vector' => 0.25]`
- **AND** the returned `weights` field MUST reflect the normalised values

---
retrofit_extensions:
  - REQ-006
---

# Vector Embeddings Specification (delta)

**Status**: implemented (retrofit — code already exists)
**Scope**: openregister

## ADDED Requirements

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

## Non-Functional

- **i18n (ADR-007):** This delta covers backend vector-query execution with no
  user-facing copy of its own. The query string is opaque pass-through content
  embedded by the model and is locale-agnostic; result entries carry only
  machine fields (`vector_id`, `similarity`, `chunk_text`, etc.) and no
  presentation labels. Error strings (`Solr service is not available`,
  `Semantic search failed: {message}`) are operator/log diagnostics and are
  exempt from translation.
- **Graceful degradation:** a failing vector leg in `hybridSearch` MUST be
  logged and tolerated; the Solr leg MUST still fuse and return results.
- **Resilience:** an unparseable stored embedding MUST be skipped (warning),
  never fatal, on the database path.

## Acceptance Criteria

- `semanticSearch` embeds the query, routes by backend, computes cosine
  similarity on the DB path, sorts descending, and caps at `limit`; an empty
  vector table returns `[]`.
- Each result entry carries the full key set (`vector_id`, `entity_type`,
  `entity_id`, `similarity`, `chunk_index`, `total_chunks`, `chunk_text`,
  `metadata`, `model`, `dimensions`).
- `hybridSearch` normalises weights to sum to 1, fuses via Reciprocal Rank
  Fusion, tolerates a failing vector leg, and returns `results`, `total`,
  `search_time_ms`, `source_breakdown`, and the normalised `weights`.

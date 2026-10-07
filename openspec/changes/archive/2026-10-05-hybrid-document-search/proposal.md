---
kind: code
depends_on: []
chain:
  - hybrid-document-search
  - searchable-property-index
---

## Why

File search in OpenRegister is broken in three concrete, file-verified ways
(spectr re-platform FTS deep-dive, 2026-07-05): `VectorSearchHandler::semanticSearch`
fetches at most 500 vectors ordered `created_at DESC` and scores them with a PHP
cosine loop — past 500 chunks, "semantic search" silently degrades into "search the
newest 500 chunks," not the most relevant ones. The hybrid-search keyword arm is
never populated by any caller (`FileSearchController::hybridSearch` hard-codes
`keywordResults: []`), so Reciprocal Rank Fusion always degenerates to pure-vector
ranking regardless of the caller's requested weights, and the endpoint's `total`
field counts the wrong array (the outer response shape, not the results list).
`FileSearchController::semanticSearch` also passes `filters: ['entityType' => 'file']`
into a handler that reads `$filters['entity_type']` — the file-only scope is silently
ignored, so semantic file search actually searches every vectorized entity type.

Chunk extraction (`openregister_chunks`) already runs automatically after file
upload, but vectorization is a manual batch action with no auto-trigger — chunks
accumulate unvectorized until an admin remembers to run it, so search quality
silently decays over time on every install.

Solr was removed from OpenRegister (`SearchBackendHandler` is database-only); the
2026-07-05 scale spike proved the concrete fix for the ranking side is available
today: a `pg_trgm` GIN index cut a rare-term ILIKE seq-scan from 268ms to 1.5ms
(~180x). The same category of fix — an actual index instead of an unindexed
in-application scan — applies to vector similarity (pgvector + HNSW instead of a
PHP loop over up to 500 unserialized BLOBs) and to keyword ranking over chunk text
(`tsvector`/`ts_rank` instead of no ranked keyword path at all).

## What Changes

- Add a `pgvector` column + HNSW ANN index to `openregister_vectors` (PostgreSQL
  only; the existing serialized-BLOB `embedding` column stays as the MariaDB/no-
  extension fallback storage — this is additive, not a replacement).
- Dual-write the new column from `VectorStorageHandler::storeVector()` going
  forward (Postgres + matching configured embedding dimension only), plus a
  one-time backfill pass over existing BLOB rows for the currently-configured
  model/dimension.
- Rewrite `VectorSearchHandler::semanticSearch()` to run a SQL `ORDER BY
  embedding_vector <=> :query LIMIT :n` KNN query on Postgres when the pgvector
  column is populated for the request's filters; keep the existing PHP
  cosine-similarity loop as the fallback for MariaDB/SQLite or rows without a
  populated pgvector value. Remove the recency-biased `created_at DESC` ordering
  from the fallback path's candidate fetch — it silently substituted "newest" for
  "most relevant" and is not needed once the primary path is index-backed.
  **BREAKING**: `VectorSearchHandler::fetchVectors()`'s default `max_vectors` cap
  and ordering change; any caller relying on the old recency bias must switch to
  the new relevance-ranked result set.
- Add a ranked keyword-search method to `ChunkMapper` over `openregister_chunks
  .text_content` using a `tsvector` generated column + GIN index and `ts_rank`
  (PostgreSQL only; returns `[]` with a logged warning on other platforms — no
  ranked keyword arm existed before this change on any platform, so this is
  additive).
- Wire `FileSearchController::hybridSearch()` to call the new keyword-search
  method and pass real results into `VectorizationService::hybridSearch()`'s RRF
  fusion, replacing the hard-coded `keywordResults: []`.
- Fix `FileSearchController::semanticSearch()`'s `entityType` → `entity_type`
  filter-key mismatch so file-scoped search actually scopes to files.
- Fix `FileSearchController::hybridSearch()`'s `total` (it currently counts the
  outer response array's keys, not the result list) and flatten the endpoint's
  JSON shape to `{results, total, ...}` matching what a search-page UI consumes
  directly, instead of nesting the inner service response under `results`.
- Add a recurring background job that vectorizes chunks where `vectorized =
  false` in batches (via the existing `generateBatchEmbeddings()` path), so new
  extracted text is searchable without a manual admin action.

## Capabilities

### New Capabilities
- `file-search`: The `FileSearchController` HTTP contract for semantic and
  hybrid search scoped to file chunks — request/response shape, entity-type
  scoping, and the background auto-vectorization job that keeps the index fresh.

### Modified Capabilities
- `vector-embeddings`: `VectorSearchHandler::semanticSearch()` gains a
  Postgres/pgvector KNN path (PHP-cosine becomes the fallback, not the only
  path); `hybridSearch()`'s keyword arm becomes real (ranked `tsvector`/`ts_rank`
  results) instead of always-empty; the 500-vector recency-biased cap is removed
  from the primary path.

## Impact

- **Affected code**: `lib/Db/ChunkMapper.php`, `lib/Db/Chunk.php` (no new
  columns needed — `vectorized` already exists), `lib/Service/Vectorization
  /Handlers/VectorSearchHandler.php`, `lib/Service/Vectorization/Handlers
  /VectorStorageHandler.php`, `lib/Controller/FileSearchController.php`, a new
  `lib/BackgroundJob/ChunkVectorizationJob.php`, two new migrations
  (`openregister_vectors` pgvector column + index, `openregister_chunks`
  tsvector column + index).
- **Database**: additive schema changes only; MariaDB/SQLite installs are
  unaffected (new columns/indexes are Postgres-only, guarded the same way the
  existing retention-JSON GIN index migration guards its Postgres path).
- **Dependent apps**: none directly consume `FileSearchController` today
  (verified: no cross-app callers found); the `vector-embeddings` capability is
  consumed internally by OpenRegister's own object/file vectorization strategies,
  which are unaffected by the ranking-path change (only the ranking mechanics
  change, not the public method signatures).
- **Chain**: this is spec 1 of 2. Spec 2, `searchable-property-index` (schema
  `searchable` property flag → `pg_trgm` GIN index on OpenRegister object magic
  tables, mirroring the existing `facetable` → btree pattern), is a distinct
  subsystem (object-property search, not file/chunk search) split out to keep
  each spec's reviewer surface and task count tight per ADR-032. No functional
  dependency exists between them; `searchable-property-index` declares
  `depends_on: [hybrid-document-search]` purely for sequencing discipline within
  the same initiative (see that spec's proposal for the rationale, flagged as a
  deferred question for confirmation).

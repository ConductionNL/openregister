## 1. Database migrations

- [x] 1.1 Add a migration for `openregister_vectors`: `CREATE EXTENSION IF NOT EXISTS vector` (try/catch + skip on failure), add nullable `embedding_vector vector({configured_dimension})` column, `CREATE INDEX ... USING hnsw (embedding_vector vector_cosine_ops)`. Guard the whole migration behind a PostgreSQL platform check (mirror `Version1Date20260322120000`'s `str_contains(get_class($platform), 'PostgreSQL')` pattern) and no-op with a log line on other platforms. *(AMENDED 2026-07-06, live-verified: an in-table `vector`-typed column breaks Doctrine introspectSchema() for every subsequent NC migration — shipped as the unprefixed `openregister_vec_ann` sidecar table + HNSW index with cascade FK; identical guard/extension/dimension semantics. See design.md amendment.)*
- [x] 1.2 Add a migration for `openregister_chunks`: add `text_search tsvector GENERATED ALWAYS AS (to_tsvector('simple', text_content)) STORED`, `CREATE INDEX ... USING GIN (text_search)`. Same PostgreSQL-only guard as 1.1. *(AMENDED 2026-07-06: a `tsvector` column has the same Doctrine-introspection problem — shipped as a functional GIN index `USING gin (to_tsvector('simple', text_content))`, zero schema-visible changes, same 'simple' config. See design.md amendment.)*

## 2. Vector storage & backfill

- [x] 2.1 Update `VectorStorageHandler::storeVector()` to also write `embedding_vector` (Postgres + dimension-matched rows only) alongside the existing BLOB write — additive, does not replace or alter the BLOB path. *(AMENDED: dual-write is an idempotent upsert into the `openregister_vec_ann` sidecar instead of an in-table column — same conditions, same tolerance.)*
- [x] 2.2 Backfill via job-only warm-up (DECIDED 2026-07-06): the migration adds column/index only; extend the task-5.2 background job's selection to rows where `embedding_vector IS NULL` (idempotent, dimension-matched) so existing BLOB rows convert over warm-up runs with zero upgrade-time impact. *(AMENDED: "embedding_vector IS NULL" is implemented as "no sidecar row" — `LEFT JOIN openregister_vec_ann ... WHERE a.vector_id IS NULL`; identical idempotence and zero-upgrade-impact semantics.)*

## 3. Semantic & hybrid search rewrite

- [x] 3.1 Rewrite `VectorSearchHandler::semanticSearch()`: on PostgreSQL with `embedding_vector` populated for the filtered candidate set, run `ORDER BY embedding_vector <=> :queryVector LIMIT :limit` (index-backed KNN); otherwise fall back to the existing `fetchVectors()` + PHP `cosineSimilarity()` loop unchanged in shape.
- [x] 3.2 Update `VectorSearchHandler::fetchVectors()`'s fallback path: replace `orderBy('created_at', 'DESC')` with `orderBy('id', 'ASC')`; keep the `max_vectors` safety cap (documented as an approximate-fallback bound, not a relevance mechanism).
- [x] 3.3 Add `ChunkMapper::searchByKeyword(string $query, int $limit, array $filters = []): array` — `ts_rank`-ranked keyword search over the `text_search` column on PostgreSQL (optional `source_type` filter), returning rows shaped for `reciprocalRankFusion()`'s `$keywordResults` input; return `[]` with a logged warning on non-PostgreSQL platforms.
- [x] 3.4 Rename `VectorSearchHandler::hybridSearch()` / `VectorEmbeddings::hybridSearch()` parameters from `solrResults`/`solr` weight key to `keywordResults`/`keyword` (the Solr backend and its routing no longer exist in this code; align naming with actual behaviour) and update the `source_breakdown` keys from `solr_only` to `keyword_only`. *(Ground truth 2026-07-06: the rename had already landed on development — parameters, weight keys and `keyword_only` breakdown verified in place; this change removed the last stale "Solr" docblock mentions in VectorStorageHandler/VectorStatsHandler/FileSearchController.)*

## 4. FileSearchController fixes

- [x] 4.1 Fix `FileSearchController::semanticSearch()`: change the filter key from `entityType` to `entity_type` so file-scoped search actually scopes to `entity_type = 'file'`.
- [x] 4.2 Fix `FileSearchController::hybridSearch()`: call `ChunkMapper::searchByKeyword()` (scoped to `source_type = 'file'`) and pass its output as `keywordResults` into `VectorizationService::hybridSearch()`; destructure the service response into a flat `{success, query, results, total, search_time_ms, source_breakdown, weights, search_type}` body instead of nesting the whole service response under `results` with a wrong `total`.

## 5. Auto-vectorization background job

- [x] 5.1 Add `ChunkMapper::findUnvectorized(?int $limit = null, ?int $offset = null): array` mirroring the existing `findUnindexed()` shape, filtering `vectorized = false`, ordered `created_at ASC` (FIFO work queue).
- [x] 5.2 Add `ChunkVectorizationJob extends TimedJob` (5-minute interval, batch size 50-100, mirroring `BlobMigrationJob`'s shape): read `ChunkMapper::findUnvectorized()`, generate embeddings via the existing `generateBatchEmbeddings()` path, store via `VectorStorageHandler::storeVector()`, mark each successfully-processed chunk `vectorized = true`. A single chunk's embedding failure must not abort the batch (log + continue; retried on the next scheduled run).
- [x] 5.3 Register `ChunkVectorizationJob` in the app's background-job registration (verify and follow the existing registration call site used for `BlobMigrationJob`/`FileTextExtractionJob`).

## 6. Diagnostics

- [x] 6.1 Extend `SettingsController`'s existing database-info diagnostics (the panel that already reports pgvector-extension presence) to also report whether `embedding_vector`/`text_search` columns and their indexes exist, and the current backfill/vectorization progress (`ChunkMapper::countVectorized()` vs `countAll()`).

## 7. Tests

- [x] 7.1 Unit tests for `VectorSearchHandler`: KNN-path query construction and result shape on Postgres with `embedding_vector` populated; PHP-fallback path preserves existing scoring behaviour with `id ASC` ordering (no recency bias); filters (`entity_type`, `entity_id`) applied correctly on both paths; empty-candidate-set returns `[]` without computation.
- [x] 7.2 Unit tests for `ChunkMapper::searchByKeyword()`: ranked-by-`ts_rank` results on Postgres; `[]` + logged warning on a non-Postgres platform; `source_type` filter honoured.
- [x] 7.3 Unit/functional tests for `FileSearchController`: `semanticSearch()` passes `entity_type => 'file'` (regression test for the fixed bug); `hybridSearch()` response `total` matches the actual fused-result count and `results` is flat, not nested; both endpoints return 400 on a missing `query` parameter.
- [x] 7.4 Unit tests for `ChunkVectorizationJob`: batch processing marks `vectorized = true` on success; a single chunk's embedding failure doesn't abort the batch and leaves that chunk `vectorized = false` for retry.
- [x] 7.5 Migration tests: verify the pgvector column/HNSW index and the tsvector column/GIN index are created on a PostgreSQL test database and gracefully skipped (no error, informative log) on a non-PostgreSQL test database.

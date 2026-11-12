## Context

`openregister_vectors` stores one row per (entity, chunk) with a serialized-PHP-
array `embedding` BLOB (verified: `Version002003000Date20251013000000`,
`embedding` column is `blob`, `notnull`). `VectorSearchHandler::semanticSearch()`
fetches candidate rows via `fetchVectors()`, which defaults `max_vectors` to 500
and orders `created_at DESC` (verified: `lib/Service/Vectorization/Handlers
/VectorSearchHandler.php` lines 467-470), then `unserialize()`s each embedding
and computes cosine similarity in a PHP loop. Past 500 stored vectors this
returns the 500 newest, not the 500 most similar — a silent relevance
regression that gets worse the more content an install has vectorized.

`openregister_chunks` (verified: `Version1Date20251116000000`) already carries
`text_content` (TEXT), `indexed` (bool), and `vectorized` (bool, with an index
`chunks_vector_idx`) — `ChunkMapper::countVectorized()` /
`findUnindexed()` already exist, but there is no `findUnvectorized()` and no
background job consumes it: vectorization only happens when
`VectorizationService::vectorizeBatch('file', ...)` is invoked manually.

`FileSearchController` (verified) has two routes already registered in
`appinfo/routes.php`: `POST /api/search/files/semantic` and
`POST /api/search/files/hybrid`. Both exist; neither needs a new route — the
proposal's "one hybrid route returning `{results,total}`" is a response-shape
fix to `hybridSearch()`, not a new endpoint (see Decision 5).

`SettingsController` (verified, lines ~460-530) already diagnoses Postgres +
pgvector-extension presence for an admin-facing "database info" panel — this
change adds the schema/index side that panel currently only *describes*
("Recommended: Migrate to PostgreSQL + pgvector") without OpenRegister itself
using it anywhere.

The 2026-07-05 spike (`SPECTR-NEXTCLOUD-PLAN.md` §3.4) measured a `pg_trgm` GIN
index cutting a rare-term ILIKE seq-scan from 268ms to 1.5ms on a magic table —
direct evidence that "add the missing index" is the fix, not "rewrite the query
logic" (the query logic for keyword search over chunks doesn't exist at all yet;
the vector-search query logic exists but runs unindexed in PHP).

## Goals / Non-Goals

**Goals:**
- Vector similarity search on Postgres becomes index-backed (HNSW ANN), not a
  full-table PHP cosine loop capped at 500 rows.
- Keyword ranking over chunk text becomes a real, ranked (`ts_rank`) SQL path,
  not absent.
- `hybridSearch()`'s RRF fusion combines two real result sets, not one real +
  one hard-coded-empty.
- The three or-#277-class bugs (entity_type key mismatch, hard-coded empty
  keyword arm, wrong `total`) are fixed as part of touching this exact code.
- Newly extracted chunks become searchable automatically (no manual admin
  action required for basic search quality).
- MariaDB/SQLite installs keep working exactly as today (PHP-cosine fallback,
  no ranked keyword arm — same as the pre-change baseline on those platforms).

**Non-Goals:**
- Resurrecting Solr (`SearchBackendHandler` is deliberately database-only per
  `remove-solr-and-publishing` — out of scope, not revisited here).
- A general-purpose search UI or nc-vue `CnSearchPage` data-source contract
  (plan §4.3(b) — a separate nc-vue change, not this repo).
- Object-property (magic-table) search improvements — that's the sibling spec
  `searchable-property-index` (different subsystem: schema properties on
  typed object tables, not file/chunk text).
- Multi-model / mixed-dimension pgvector columns — the column is sized for the
  currently-configured embedding model's dimension only (see Decision 2).
- Re-ranking or LLM-based result re-ordering — RRF fusion only, as today.

## Decisions

> **IMPLEMENTATION AMENDMENT (2026-07-06, live-verified during apply on the
> spectr-spike instance — PostgreSQL 16 + pgvector 0.8.1 + NC 34):** Decisions
> 1 and 7's *storage mechanics* had to change; their intent, guarantees and
> warm-up semantics are implemented unchanged. Adding a `vector`- or
> `tsvector`-typed column to an `oc_`-prefixed table breaks Nextcloud
> permanently: `OC\DB\Migrator::createSchema()` runs Doctrine's
> `introspectSchema()` over ALL prefix-matched tables for EVERY subsequent
> migration of every app (and core upgrades), and Doctrine throws
> `Unknown database type vector requested` on the first unknown column type.
> This was hit live: this change's own second migration failed with exactly
> that error after the first migration added the column. Equivalents shipped:
>
> - **Vectors (Decision 1):** an UNPREFIXED sidecar table
>   `openregister_vec_ann` (`vector_id BIGINT PK REFERENCES
>   {prefix}openregister_vectors(id) ON DELETE CASCADE, embedding vector(N)
>   NOT NULL`) + HNSW cosine index. Unprefixed tables are invisible to the
>   `/^{prefix}/` migration filter, so Doctrine never introspects the vector
>   type. "Sidecar row absent" is the exact equivalent of the designed
>   "`embedding_vector IS NULL`" for the job-only warm-up; dual-write is an
>   idempotent upsert; deletes cascade. Skipped (PHP fallback) when
>   `dbtableprefix` is empty, since then nothing can be hidden from the filter.
> - **Chunks (Decision 7):** a functional (expression) GIN index
>   `USING gin (to_tsvector('simple', text_content))` instead of a STORED
>   generated column — zero schema-visible changes, same index-backed ranked
>   `ts_rank` query (the query uses the same expression so the planner uses
>   the index). Precedent: `Version1Date20260322120000`'s
>   `(retention::jsonb) jsonb_path_ops` expression GIN index already lives on
>   production installs without breaking migrations.

### 1. Additive pgvector column, BLOB stays as the storage-of-record fallback

Add `embedding_vector` (pgvector `vector(N)`, nullable) to `openregister_vectors`
alongside the existing `embedding` BLOB column — do not replace the BLOB. The
BLOB remains the durable, platform-portable storage (works on MariaDB/SQLite);
`embedding_vector` is a Postgres-only accelerated *index path* populated
opportunistically. This mirrors the exact precedent in
`Version1Date20260322120000` (retention-JSON GIN index): the canonical `json`
column stays; a derived Postgres-specific structure is added beside it, guarded
by `str_contains(get_class($platform), 'PostgreSQL')`.

Migration mechanics: `ISchemaWrapper`/Doctrine DBAL has no native pgvector type,
so the column is added via raw SQL in `postSchemaChange()` (same pattern as the
retention migration's `createPostgreSqlIndex()` / `createMariaDbIndexes()`
split): `ALTER TABLE {prefix}openregister_vectors ADD COLUMN IF NOT EXISTS
embedding_vector vector({dimensions})` guarded behind `CREATE EXTENSION IF NOT
EXISTS vector` (idempotent, already proven safe — `SettingsController` already
queries `pg_extension` for `vector` without issue). Skip entirely (log info,
return) on non-Postgres platforms, matching the retention migration's "Skipping
… unsupported database platform" branch.

**Alternative considered**: replace the BLOB column outright. Rejected — it
would make MariaDB/SQLite installs (which `SettingsController` already
explicitly supports as a documented tier: "SQLite not recommended for
production… migrate to PostgreSQL") lose vector search entirely instead of
degrading gracefully to the existing PHP path.

### 2. Fixed embedding dimension per column; mixed-dimension rows stay BLOB-only

`vector(N)` requires a static `N`. OpenRegister supports multiple embedding
providers/models with different dimensions (verified:
`VectorEmbeddings::checkEmbeddingModelMismatch()` already exists specifically
because historical vectors can carry a different model/dimension than the
currently configured one, and the documented remedy today is "Clear All
Embeddings" + re-vectorize). Design choice: size `embedding_vector` at
migration time from the *currently configured* model's dimension
(`SettingsService::getLLMSettingsOnly()`); rows whose `embedding_dimensions`
column doesn't match are left with `embedding_vector IS NULL` and continue to
be served by the PHP-cosine fallback path until re-vectorized. This is
consistent with the existing model-mismatch handling — it doesn't introduce a
new class of inconsistency, it extends the existing one to a second column.

**Alternative considered**: multiple `vector(N)` columns (one per known
dimension family — 1536, 3072, etc.). Rejected as premature — the existing
system already treats "embedding model changed" as an event requiring
re-vectorization (`checkEmbeddingModelMismatch` + "Clear All Embeddings"), so a
single active-dimension column matches the system's existing single-active-model
assumption rather than fighting it.

### 3. SQL KNN as the primary path, PHP-cosine as an explicit fallback (not deleted)

`VectorSearchHandler::semanticSearch()` gains a Postgres branch: when
`embedding_vector` is populated for the candidate set, run `SELECT … FROM
openregister_vectors WHERE embedding_vector IS NOT NULL {filters} ORDER BY
embedding_vector <=> :queryVector LIMIT :limit` (the `<=>` cosine-distance
operator from pgvector, ordered ascending = most similar first) with an HNSW
index on `embedding_vector` (`CREATE INDEX … USING hnsw (embedding_vector
vector_cosine_ops)`) to make that `ORDER BY … LIMIT` index-backed rather than a
sequential scan. The existing `fetchVectors()` + PHP `cosineSimilarity()` loop
remains verbatim as the fallback (MariaDB/SQLite, or Postgres installs where
the pgvector extension isn't installed, or unmatched-dimension rows) — same
method, same signature, just no longer the only path. Only the fallback's
`fetchVectors()` `orderBy('created_at', 'DESC')` bias is removed (see Decision
4); its `max_vectors` cap stays as an explicit, documented "approximate
fallback" bound (renamed/commented, not silently framed as relevance).

**Alternative considered**: require pgvector and drop the PHP path entirely.
Rejected — breaks MariaDB/SQLite installs and installs without the extension,
which `SettingsController`'s own diagnostics already treat as a supported (if
suboptimal) tier.

### 4. Remove the recency bias from the fallback path, keep a bound

The current `orderBy('created_at', 'DESC')` inside `fetchVectors()` silently
substitutes "newest 500" for "most relevant" once an install exceeds 500
vectors — this is the or-#277-adjacent bug the spectr deep-dive called out by
name. Fix: on the fallback path, order by primary key (`id ASC`) instead of
`created_at DESC` — a stable, non-biasing tiebreaker — and keep `max_vectors`
as a documented safety cap (default unchanged at 500, now callable via
`$filters['max_vectors']` same as today) so a MariaDB install with tens of
thousands of chunks doesn't `unserialize()` and cosine-score all of them on
every request. This is a pragmatic bound on an inherently O(n) fallback, not a
silent relevance distortion — the difference from today is that it no longer
*pretends* to rank by relevance while actually ranking by recency.

### 5. Response-shape fix for `hybridSearch()`, no new route

`FileSearchController::hybridSearch()` currently does
`'total' => count($results)` where `$results` is the *entire* nested return of
`VectorizationService::hybridSearch()` (keys: `results`, `total`,
`search_time_ms`, `source_breakdown`, `weights`) — so `total` is always ~5,
and the outer `results` key nests that whole structure instead of the flat
result list. Fix: destructure the service response and return
`{success, query, results: $serviceResponse['results'], total:
$serviceResponse['total'], search_time_ms, source_breakdown, weights,
search_type: 'hybrid'}` — this *is* the "`POST /api/search/files` hybrid route
returning `{results,total}` shaped for a search page" from the plan; the route
already exists at `/api/search/files/hybrid`, only its response body was wrong.
`semanticSearch()`'s response is already flat (`results`, `total` from
`count($results)` over the real result array) and needs no shape change —
only its filter-key bug (Decision 6).

### 6. `entityType` → `entity_type` filter-key fix

`FileSearchController::semanticSearch()` passes `filters: ['entityType' =>
'file']`; `VectorSearchHandler::fetchVectors()` reads `$filters['entity_type']`
— the mismatch means the WHERE clause never applies and file-scoped search
silently searches every entity type. Fix: change the controller to pass
`entity_type` (snake_case, matching the handler's contract and the
`openregister_vectors.entity_type` column name it filters on directly).

### 7. Ranked keyword arm: `tsvector` generated column + GIN + `ts_rank`

Add `text_search` (Postgres `tsvector`, `GENERATED ALWAYS AS
(to_tsvector('simple', text_content)) STORED`) to `openregister_chunks` +
`CREATE INDEX … USING GIN (text_search)`, added the same way as Decision 1
(raw SQL in `postSchemaChange()`, Postgres-only, skip elsewhere). New
`ChunkMapper::searchByKeyword(string $query, int $limit, array $filters =
[]): array` builds `SELECT *, ts_rank(text_search, plainto_tsquery('simple',
:q)) AS score FROM openregister_chunks WHERE text_search @@
plainto_tsquery('simple', :q) ORDER BY score DESC LIMIT :limit` on Postgres;
returns `[]` with a logged warning on other platforms (there is no pre-existing
ranked keyword path on any platform to regress — this is purely additive).
`'simple'` (not `'english'`) is chosen to avoid English-only stemming bias
given OpenRegister's Dutch-government usage context; revisit if per-chunk
`language`/`language_level` (already tracked columns on `Chunk`) should drive a
per-row tsvector-config choice — flagged as an open question, not blocking.

`FileSearchController::hybridSearch()` calls `ChunkMapper::searchByKeyword()`
and maps its rows into the `{entity_type: 'file', entity_id, score, chunk_text,
chunk_index, metadata}` shape `VectorSearchHandler::reciprocalRankFusion()`
already expects from `$keywordResults` (verified: the RRF implementation reads
`entity_type`, `entity_id`, `score`, `chunk_text`, `metadata` per element —
no changes needed to `reciprocalRankFusion()` itself, only to what's passed in).

### 8. Auto-vectorization as a native `TimedJob`, not `ScheduledWorkflow`/n8n

**Declarative-vs-imperative decision (ADR-031)**: this change's background job
is system-maintenance/pipeline infrastructure (embedding-generation batch
processing), not business-domain workflow automation — it has no lifecycle,
aggregation, derived-field, notification, relation, or dashboard-widget shape,
so none of ADR-031's declarative triggers apply. Where the task instructions
ask this to be justified against ADR-031's exception list: this falls under
"scheduled bulk work" — but the exception list's suggested mechanism
(`ScheduledWorkflow` + n8n) is for *business*-process scheduling. The two
directly analogous precedents already in this codebase —
`BlobMigrationJob` (batch-migrates blob-table rows to magic tables) and
`FileTextExtractionJob`/`CronFileTextExtractionJob` (batch-extracts chunk
text) — both use native Nextcloud `OCP\BackgroundJob\TimedJob`/`QueuedJob`,
not n8n, because they are OpenRegister-internal data-pipeline maintenance, the
same category as this new job. `ChunkVectorizationJob` follows the same shape:
`TimedJob`, 5-minute interval (matching `BlobMigrationJob::INTERVAL`), batch
size 50-100, reads `ChunkMapper::findUnvectorized()` (new method, mirrors
`findUnindexed()`), calls `VectorizationService::vectorizeBatch()` /
`generateBatchEmbeddings()` per batch, marks `vectorized = true` on success
per-chunk (partial-batch failures don't block the rest, same tolerance pattern
as `BlobMigrationJob`'s per-group try/catch).

### 9. `findUnvectorized()` on `ChunkMapper`

Mirrors the existing `findUnindexed()` exactly (same shape: `?int $limit,
?int $offset`, `orderBy('created_at', 'ASC')` — FIFO processing order is
correct here, unlike the vector-search recency bug in Decision 4, because this
is a work queue, not a relevance ranking) but filters `vectorized = false`
instead of `indexed = false`.

## Risks / Trade-offs

- **[Risk] HNSW index build time on large existing installs** → Mitigation:
  index creation happens once via `CREATE INDEX` (not `CONCURRENTLY`, to keep
  the migration synchronous and match every other index-creation call site in
  this codebase — `MagicMapper.php`'s facetable/relation indexes are not
  `CONCURRENTLY` either); flagged as an open question below for installs with
  very large vector tables where a blocking migration could be undesirable.
- **[Risk] Backfill script cost** → Mitigation: the one-time backfill only
  processes rows matching the currently-configured dimension (Decision 2),
  batches via the existing `ChunkVectorizationJob`-adjacent batching pattern,
  and is idempotent (skips rows where `embedding_vector IS NOT NULL`).
- **[Risk] `to_tsvector('simple', ...)` loses stemming quality vs a
  language-aware config** → Mitigation: documented as an explicit trade-off in
  Decision 7 and an open question; `'simple'` is the safe default given mixed
  Dutch/English content, not a mistake to silently carry forward.
- **[Risk] Removing the `created_at DESC` fallback ordering is a documented
  BREAKING change** (proposal.md) → Mitigation: no known external caller of
  `VectorSearchHandler::fetchVectors()` outside `VectorSearchHandler` itself
  (verified: it's `private`); the observable behavior change is scoped to
  `semanticSearch()`/`hybridSearch()` result ordering on the fallback path only.
- **[Risk] `CREATE EXTENSION vector` / `CREATE EXTENSION pg_trgm` requires
  appropriate Postgres privileges** → Mitigation: same precondition
  `SettingsController` already assumes when it reports "Install pgvector
  extension: CREATE EXTENSION vector;" as admin guidance; migration wraps the
  statement in a try/catch and logs+skips (matching `MagicMapper`'s existing
  `hasPgTrgmExtension()` tolerant-check pattern) rather than failing the whole
  migration if the extension can't be created.

## Migration Plan

1. Migration A: `openregister_vectors` — `CREATE EXTENSION IF NOT EXISTS
   vector` (Postgres only, try/catch+skip), add `embedding_vector
   vector({configured_dimension})`, `CREATE INDEX … USING hnsw
   (embedding_vector vector_cosine_ops)`.
2. Migration B: `openregister_chunks` — add `text_search tsvector GENERATED
   ALWAYS AS (to_tsvector('simple', text_content)) STORED`, `CREATE INDEX …
   USING GIN (text_search)` (Postgres only).
3. Deploy `VectorStorageHandler::storeVector()` dual-write + rewritten
   `VectorSearchHandler` + `ChunkMapper::searchByKeyword()` +
   `FileSearchController` fixes + `ChunkVectorizationJob` together (they are
   one coherent code surface — splitting them would leave the new column
   unused or the old bugs half-fixed).
4. One-time backfill (either as `postSchemaChange` batch loop bounded by a
   row-count safety cap, or as the first few runs of `ChunkVectorizationJob`
   naturally catching up given `vectorized` chunks with a stale/absent
   `embedding_vector` — implementer's choice, captured as an open question).
5. Rollback: both migrations are additive-only (new nullable column + new
   index); rolling back the code to the pre-change version leaves the new
   column/index unused but harmless — no destructive rollback migration is
   required.

## Open Questions

- ~~Backfill placement~~ **DECIDED 2026-07-06 (Ruben): job-only warm-up.** The
  migration adds the column/index ONLY; ALL BLOB→pgvector conversion happens
  through `ChunkVectorizationJob` warm-up iterations (Decision 8's per-batch
  design, selecting rows where `embedding_vector IS NULL`). Zero upgrade-time
  impact; capability converges over the first job runs. The task-6.1
  diagnostics panel is the operator's convergence view.
- Should `CREATE INDEX … USING hnsw` use `CONCURRENTLY`? Doctrine
  migrations run inside Nextcloud's migration transaction management, and
  `CREATE INDEX CONCURRENTLY` cannot run inside a transaction. Provisional
  decision: non-concurrent, consistent with every other index-creation call
  site in this codebase; document in the migration's admin-facing output that
  very large existing vector tables may see a longer `occ upgrade` on first
  run. Flagged as `DEFERRED_QUESTIONS`.
- Per-chunk `language`/`language_level`-aware `tsvector` config instead of a
  single `'simple'` config for all chunks — deferred as a follow-up, not
  blocking this change (documented in Decision 7).

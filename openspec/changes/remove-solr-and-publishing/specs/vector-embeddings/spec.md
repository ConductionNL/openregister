## MODIFIED Requirements

### Requirement: The system MUST execute vector queries via PostgreSQL semantic KNN/cosine similarity
`VectorEmbeddings::semanticSearch(string $query, int $limit = 10, array $filters = [], ?string $provider = null)` MUST first generate a query embedding for `$query` via `generateEmbedding()` (vector-embeddings REQ-001), and delegate to `VectorSearchHandler::semanticSearch(array $queryEmbedding, int $limit, array $filters)`. There is no Solr/external-backend branch — vector search runs exclusively against the PostgreSQL vector store.

`VectorSearchHandler::semanticSearch` MUST fetch candidate vectors from the PostgreSQL vector store (honouring the `$filters` predicate), `unserialize()` each stored embedding BLOB, compute `cosineSimilarity()` against the query embedding, sort by similarity descending, and return the top `$limit`. A vector row whose deserialised embedding is not an array MUST be skipped (not fatal). When the database path finds no vectors the call MUST return `[]`. Any thrown error MUST be re-wrapped as `Semantic search failed: {message}`.

Each result entry MUST carry `vector_id`, `entity_type`, `entity_id`, `similarity`, `chunk_index`, `total_chunks`, `chunk_text`, `metadata` (JSON-decoded from the row, `[]` when absent), `model`, and `dimensions`.

The `/api/search/semantic` and `/api/search/hybrid` HTTP routes and the Solr-leg hybrid-search method are removed; semantic search is available only through in-process callers, not through the removed public HTTP surface.

#### Scenario: Database semantic search ranks by cosine similarity and caps at limit
- **GIVEN** the PostgreSQL vector store holds 50 candidate rows for the filter
- **WHEN** `VectorEmbeddings::semanticSearch('find me', 10)` runs
- **THEN** the query MUST first be embedded via `generateEmbedding('find me')`
- **AND** cosine similarity MUST be computed against each deserialised stored embedding
- **AND** the result MUST be the 10 highest-similarity entries sorted descending, each carrying `vector_id`, `entity_type`, `entity_id`, `similarity`, `chunk_text`, `model`, and `dimensions`

#### Scenario: Unparseable stored embedding is skipped, not fatal
- **GIVEN** one of the fetched vector rows deserialises to a non-array value
- **WHEN** the database semantic-search loop processes it
- **THEN** that row MUST be skipped with a warning log
- **AND** the remaining rows MUST still be ranked and returned

### Requirement: The system MUST store vectors in the PostgreSQL vector store with UTF-8 sanitisation
`VectorEmbeddings::storeVector(...)` MUST persist to the PostgreSQL vector store via `VectorStorageHandler::storeVector(...)`. There is no Solr storage branch and no backend resolution — the database is the sole store.

The database path MUST insert a row into the vector store with: `entity_type`, `entity_id`, `chunk_index`, `total_chunks`, `chunk_text` (after sanitisation), `embedding` (PHP-`serialize()`'d to a BLOB via `PDO::PARAM_LOB`), `embedding_model`, `embedding_dimensions`, `metadata` (JSON-encoded when non-empty), and `created_at` / `updated_at` set to `date('Y-m-d H:i:s')`. The autoincrement `id` MUST be returned. Sanitisation (`sanitizeText`) MUST: (1) `mb_convert_encoding($text, 'UTF-8', 'UTF-8')`, (2) strip `\x00-\x08\x0B\x0C\x0E-\x1F\x7F` control characters, (3) `iconv('UTF-8', 'UTF-8//IGNORE', $text)`, (4) collapse whitespace via `preg_replace('/\s+/u', ' ', $text)`, (5) `trim()`.

Any failure MUST be re-wrapped as `Vector storage failed: {message}` before being thrown.

#### Scenario: Vectors persist to the PostgreSQL store
- **GIVEN** an embedding `[0.1, 0.2, 0.3]` for object `'abc-uuid'` with model `text-embedding-3-small`
- **WHEN** `VectorEmbeddings::storeVector(...)` runs
- **THEN** `VectorStorageHandler::storeVectorInDatabase(...)` MUST be the path taken
- **AND** the inserted row's `embedding` column MUST contain `serialize([0.1, 0.2, 0.3])` bound as `PDO::PARAM_LOB`
- **AND** the inserted row's `embedding_dimensions` MUST equal 3
- **AND** the function MUST return the autoincrement `id` of the new row

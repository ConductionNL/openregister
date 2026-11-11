## ADDED Requirements

### Requirement: The system MUST store vectors in the PostgreSQL vector store with UTF-8 sanitisation

`VectorEmbeddings::storeVector(...)` MUST persist to the vector store via `VectorStorageHandler::storeVector(...)`, which MUST call `storeVectorInDatabase(...)`. There is no backend resolution, no `$backend` parameter and no Solr storage branch: the database is the sole vector store.

**Database path** (`storeVectorInDatabase`) MUST insert a row into `openregister_vectors` with: `entity_type`, `entity_id`, `chunk_index`, `total_chunks`, `chunk_text` (after sanitisation), `embedding` (PHP-`serialize()`'d to a BLOB via `PDO::PARAM_LOB`), `embedding_model`, `embedding_dimensions`, `metadata` (JSON-encoded when non-empty), and `created_at` / `updated_at` set to `date('Y-m-d H:i:s')`. The autoincrement `id` MUST be returned. When the pgvector fast path is available and the embedding's dimension matches the sidecar column dimension, the same call MUST also write the embedding to the `openregister_vec_ann` sidecar row for that `vector_id`; a dimension mismatch or a missing sidecar MUST skip that write without failing the insert. Sanitisation (`sanitizeText`) MUST: (1) `mb_convert_encoding($text, 'UTF-8', 'UTF-8')`, (2) strip `\x00-\x08\x0B\x0C\x0E-\x1F\x7F` control characters, (3) `iconv('UTF-8', 'UTF-8//IGNORE', $text)`, (4) collapse whitespace via `preg_replace('/\s+/u', ' ', $text)`, (5) `trim()`.

Any failure MUST be re-wrapped as `Vector storage failed: {message}` before being thrown.

#### Scenario: Storage takes the database path without backend resolution
- **GIVEN** any LLM settings, including a legacy `llmSettings.vectorConfig.backend` value of `'solr'`
- **WHEN** `VectorEmbeddings::storeVector(...)` runs
- **THEN** `VectorStorageHandler::storeVectorInDatabase(...)` MUST be the path taken
- **AND** no external search backend MUST be contacted

#### Scenario: Database insert serialises embedding as a PHP BLOB
- **GIVEN** an embedding `[0.1, 0.2, 0.3]` for object `'abc-uuid'` with model `text-embedding-3-small`
- **WHEN** `storeVectorInDatabase(...)` runs
- **THEN** the inserted row's `embedding` column MUST contain `serialize([0.1, 0.2, 0.3])` bound as `PDO::PARAM_LOB`
- **AND** the inserted row's `embedding_dimensions` MUST equal 3
- **AND** the function MUST return the autoincrement `id` of the new row

#### Scenario: Database failure is re-wrapped
- **GIVEN** the insert into `openregister_vectors` throws `connection lost`
- **WHEN** `storeVectorInDatabase(...)` runs
- **THEN** an `Exception` with message `Vector storage failed: connection lost` MUST be thrown

#### Scenario: sanitizeText strips control chars and normalises whitespace
- **GIVEN** input `"hello\x00world\t\t\nfoo"`
- **WHEN** `VectorStorageHandler::sanitizeText(...)` is invoked
- **THEN** the output MUST equal `"helloworld foo"` (NUL byte removed, tab+newline collapsed to single space, trimmed)

### Requirement: The system MUST expose database vector statistics and surface embedding-model rotation needs over the LLM settings routes

`VectorizationService::getVectorStats()` MUST delegate to `VectorStatsHandler::getStats()`, which reads the database vector store only (there is no backend resolution) and MUST run three queryBuilder calls on `openregister_vectors` (total via `COUNT(*)`, breakdown via `GROUP BY entity_type`, breakdown via `GROUP BY embedding_model`). The returned shape MUST be `['total_vectors', 'by_type' => array<string,int>, 'by_model' => array<string,int>, 'object_vectors' => int, 'file_vectors' => int]`. On any exception the handler MUST return `['total_vectors' => 0, 'by_type' => [], 'by_model' => [], 'object_vectors' => 0, 'file_vectors' => 0]` rather than throw.

`VectorEmbeddings::checkEmbeddingModelMismatch()` MUST compare the currently-configured embedding model (from `SettingsService::getLLMSettingsOnly()`, mapped by provider: `openaiConfig.model`, `ollamaConfig.model`, `fireworksConfig.embeddingModel`) against the distinct `embedding_model` values found in `openregister_vectors`. The response MUST be one of:

- `['has_vectors' => false, 'mismatch' => false, 'message' => 'No embedding model configured']` — when no current model is configured.
- `['has_vectors' => false, 'mismatch' => false, 'current_model' => string, 'message' => 'No vectors exist yet']` — when the table is empty.
- `['has_vectors' => true, 'mismatch' => bool, 'current_model', 'existing_models' => array<string>, 'total_vectors' => int, 'null_model_count' => int, 'mismatched_models' => array<string>, 'message' => string]` — when vectors exist. `mismatch` MUST be `true` if any stored model differs from current OR if `null_model_count > 0`.

The user-facing `message` MUST be one of three strings depending on state: `Multiple embedding models detected. Consider re-embedding all vectors with a single model.` (any drift), `{N} vectors have no model information.` (only NULL models), `All vectors use the same embedding model.` (no drift). On exception the response MUST be `['has_vectors' => false, 'mismatch' => false, 'error' => string]`.

`VectorEmbeddings::clearAllEmbeddings()` MUST count the rows in `openregister_vectors`, return `['success' => true, 'deleted' => 0, 'message' => 'No vectors to delete']` when empty, otherwise `DELETE FROM openregister_vectors` and return `['success' => true, 'deleted' => int, 'message' => "Deleted {N} vectors successfully"]`. On exception the response MUST be `['success' => false, 'error' => string, 'message' => 'Failed to clear embeddings: ...']` — the call MUST NOT throw.

The LLM settings page MUST reach these operations over admin-only HTTP routes on `Settings\LlmSettingsController`: `GET /api/vectors/stats` (vector statistics), `GET /api/vectors/check-model-mismatch` (model rotation check) and `DELETE /api/vectors/clear-all` (clear all embeddings), next to `POST /api/vectors/test-embedding` (provider test). These are the only `/api/vectors/*` routes; they are kept when the Solr search surface is removed.

#### Scenario: Database stats return total + by-type + by-model breakdown
- **GIVEN** `openregister_vectors` contains 100 rows: 70 with `entity_type='object'`, 30 with `entity_type='file'`, split across models `text-embedding-3-small` (60) and `nomic-embed-text` (40)
- **WHEN** `VectorizationService::getVectorStats()` is invoked
- **THEN** the response MUST be `['total_vectors' => 100, 'by_type' => ['object' => 70, 'file' => 30], 'by_model' => ['text-embedding-3-small' => 60, 'nomic-embed-text' => 40], 'object_vectors' => 70, 'file_vectors' => 30]`
- **AND** no `'source'` key MUST be present

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

#### Scenario: LLM settings page loads vector statistics over HTTP
- **GIVEN** an admin opens the LLM settings page
- **WHEN** the page requests `GET /api/vectors/stats`
- **THEN** the response MUST be HTTP 200 with the `getVectorStats()` shape (`total_vectors`, `by_type`, `by_model`, `object_vectors`, `file_vectors`)
- **AND** a non-admin caller MUST be refused

## REMOVED Requirements

### Requirement: The system MUST route vector storage to a configured backend (Postgres BLOB or Solr atomic update) with UTF-8 sanitisation
**Reason**: There is no vector backend to route to: the Solr atomic-update path, `storeVectorInSolr` and backend resolution are removed, so the default-backend and Solr scenarios no longer apply.
**Migration**: Replaced by "The system MUST store vectors in the PostgreSQL vector store with UTF-8 sanitisation", which carries the BLOB insert and `sanitizeText` scenarios and adds the pgvector sidecar write.

### Requirement: The system MUST expose vector statistics and surface embedding-model rotation needs
**Reason**: The Solr statistics branch and its `source` discriminator are removed.
**Migration**: Replaced by "The system MUST expose database vector statistics and surface embedding-model rotation needs over the LLM settings routes", which carries the database stats, model mismatch and clear-all scenarios and names the `/api/vectors/*` routes the LLM settings page uses.

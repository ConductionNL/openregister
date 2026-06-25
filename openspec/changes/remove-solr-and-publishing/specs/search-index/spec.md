## REMOVED Requirements

### Requirement: Search backend operations route through SearchBackendInterface
**Reason**: The entire SOLR/Elasticsearch search Index abstraction is removed. No live deployment uses an external search backend; object/full-text search runs exclusively on the built-in Magic-Tables (SQL/PostgreSQL) path, making the `SearchBackendInterface` contract and its implementations dead weight.
**Migration**: Search now goes directly through `MagicSearchHandler` (see `zoeken-filteren`). No consumer-facing migration is required — the DB path is already the default and produces the same `{ results, total, page, pages }` envelope. Callers MUST stop referencing `IndexService`, `SearchBackendInterface`, `SolrBackend`, and `ElasticsearchBackend`.

### Requirement: Schemas are mirrored to backend collections as flat field definitions
**Reason**: External backend collections no longer exist; schema mirroring (`SchemaHandler`, `DocumentBuilder`) is removed with the Index abstraction.
**Migration**: None. Schema definitions are read directly from the database; no collection mirroring step is needed.

### Requirement: Bulk indexing fetches searchable-schema objects from the database in batches
**Reason**: `BulkIndexer` and the whole bulk-indexing driver are removed; there is no external index to populate.
**Migration**: None. Objects are queried directly from their magic tables at search time.

### Requirement: Search-backend configuration is loaded once from SettingsService and exposed via tenant-aware helpers
**Reason**: There is no search-backend selection any more; the only backend is the database.
**Migration**: Remove search-backend configuration from settings. `getSearchBackendConfig()`/`getSolrSettings()` and the `/api/settings/solr*` surface are removed.

### Requirement: SetupHandler::setupSolr orchestrates a five-step tenant-collection bootstrap
**Reason**: Solr setup/bootstrap is removed with the Solr backend.
**Migration**: None — no external collections to bootstrap.

### Requirement: Asynchronous file text extraction MUST run as a queued background job
**Reason**: The Solr-warmup-driven file indexing path is removed. Text extraction itself (`text-extraction` capability) is unaffected and stays; only the indexing-to-backend coupling defined here is removed.
**Migration**: File text extraction continues through the `text-extraction` capability and is persisted in the chunks table; it is no longer pushed to an external index.

### Requirement: DocumentBuilder coerces, validates, and reshapes object data into backend-safe documents
**Reason**: No external backend consumes documents; `DocumentBuilder` is removed.
**Migration**: None.

### Requirement: SchemaHandler resolves cross-schema field-type conflicts and provisions vector fields
**Reason**: Backend collection field provisioning is removed with the Index abstraction. Vector storage is handled by the PostgreSQL path (see `vector-embeddings`).
**Migration**: None.

### Requirement: FileHandler indexes database-resident file chunks into the backend file collection
**Reason**: No external file collection exists; `FileHandler` is removed.
**Migration**: None.

### Requirement: File Text Extraction and Indexing HTTP Surface
**Reason**: The indexing HTTP endpoints (`/api/solr/*`, vectorize routes) are removed. The extraction HTTP surface that belongs to `text-extraction`/`file-actions` is unaffected and stays.
**Migration**: Consumers MUST stop calling the removed indexing endpoints (they return HTTP 404). Text-extraction endpoints are unchanged.

### Requirement: Adaptive Post-Import Search-Index Warmup Scheduling
**Reason**: Index warmup (`SolrWarmupJob`, `SolrNightlyWarmupJob`) is removed; there is no index to warm.
**Migration**: None — searches run live against the database with no warmup step.

### Requirement: DocumentBuilder produces a flat Solr document with metadata, scalar payload, and a `_text` blob fallback
**Reason**: Solr document construction is removed with the Solr backend.
**Migration**: None.

### Requirement: DocumentBuilder converts values by field type and truncates oversize strings
**Reason**: Solr document construction is removed with the Solr backend.
**Migration**: None.

### Requirement: SolrDocumentIndexer routes every CRUD operation through the active collection's `/update` endpoint
**Reason**: Solr CRUD indexing is removed; object CRUD persists only to the database.
**Migration**: None — object CRUD already persists to the magic tables; the parallel Solr write is simply gone.

### Requirement: ObjectHandler::searchObjects builds a Solr query with OpenRegister's start/rows/q shape and converts the response to {results, total, start}
**Reason**: Solr query construction is removed.
**Migration**: Search queries are built by `MagicSearchHandler` (see `zoeken-filteren`).

### Requirement: SolrQueryExecutor::searchPaginated translates OpenRegister pagination into Solr and returns a {results, total, limit, offset, page, pages} envelope
**Reason**: Solr pagination translation is removed.
**Migration**: Pagination is handled by `MagicMapper`/`MagicSearchHandler`, which return the same `{results, total, limit, offset, page, pages}` envelope.

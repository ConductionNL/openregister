## ADDED Requirements

### Requirement: OpenRegister MUST run without an external search index
OpenRegister MUST NOT ship, register or route to an external search index. No `SearchBackendInterface`, `IndexService`, Solr or Elasticsearch class MUST be registered in the DI container, no Solr warmup job MUST be scheduled, and every route of the former search index surface (`/api/solr/*`, `/api/settings/solr*`, `/api/search/semantic`, `/api/search/hybrid`) MUST be absent from `appinfo/routes.php`, so a call to one returns HTTP 404. Object search, faceting and aggregation run on the database path (see `zoeken-filteren`, `faceting-configuration`, `aggregations-backend-native`); vectors live in the PostgreSQL vector store (see `vector-embeddings`).

#### Scenario: Former search index routes are gone
- **GIVEN** an admin client
- **WHEN** it calls `GET /api/solr/collections`, `GET /api/settings/solr` or `GET /api/search/semantic`
- **THEN** each response MUST be HTTP 404
- **AND** no controller method for those paths MUST exist (ADR-029)

#### Scenario: No search index services are registered
- **GIVEN** the app has booted
- **WHEN** the DI container is asked for `SearchBackendInterface` or `IndexService`
- **THEN** neither MUST resolve
- **AND** no `SolrWarmupJob` or `SolrNightlyWarmupJob` MUST be registered in `appinfo/info.xml`

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
**Migration**: `getSolrSettings()` and the `/api/settings/solr*` surface are removed. `getSearchBackendConfig()` stays only as a constant report of the database backend (see "Database-backed search architecture" in `zoeken-filteren`).

### Requirement: SetupHandler::setupSolr orchestrates a five-step tenant-collection bootstrap
**Reason**: Solr setup/bootstrap is removed with the Solr backend.
**Migration**: None — no external collections to bootstrap.

### Requirement: Asynchronous file text extraction MUST run as a queued background job
**Reason**: The `search-index` capability is deleted. The extraction job itself is live and is not Solr-specific, so it moves unchanged to `text-extraction` in this change.
**Migration**: Specified as "Asynchronous file text extraction MUST run as a queued background job" in `text-extraction`.

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
**Reason**: The `search-index` capability is deleted and the indexing half of this surface (chunk indexing into a search backend, file-collection fields, index warmup, file (re)indexing, file-index statistics) is removed with Solr.
**Migration**: The live extraction, file search, Files-sidebar, settings and anonymisation endpoints move to `text-extraction` as "File Text Extraction HTTP Surface".

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

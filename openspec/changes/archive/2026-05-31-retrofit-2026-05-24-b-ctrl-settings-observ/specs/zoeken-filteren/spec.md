## ADDED Requirements

### Requirement: Search-backend selection and reindex administration
The system SHALL expose an admin-gated API for selecting the active search backend and for
reindexing collections. `SettingsController` provides `getSearchBackend` (current backend
config), `updateSearchBackend` (sets the active backend and signals `reload_required:
true`), `testSetupHandler` (runs Solr setup via `SetupHandler` when Solr is enabled), and
`reindexSpecificCollection` (reindexes a named collection with validated `batchSize`
1–5000 and `maxObjects` >= 0). `SolrSettingsController` provides `getSolrSettings`,
`updateSolrSettings`, `getSolrInfo`, and `getSolrDashboardStats` for the Solr settings and
status surface.

#### Scenario: Update search backend signals reload
- **GIVEN** an admin posts `{backend: "solr"}`
- **WHEN** `updateSearchBackend` runs
- **THEN** it MUST persist the backend via `SettingsService::updateSearchBackendConfig()` and return `reload_required: true`
- **AND** an empty backend param MUST produce HTTP 400

#### Scenario: Reindex validates batch size
- **GIVEN** an admin requests a reindex with `batchSize=10000`
- **WHEN** `reindexSpecificCollection` runs
- **THEN** it MUST reject with HTTP 400 because batch size must be between 1 and 5000

#### Scenario: Setup test refuses when Solr disabled
- **GIVEN** Solr is disabled in settings
- **WHEN** `testSetupHandler` runs
- **THEN** it MUST return HTTP 400 with a "SOLR is disabled" message

### Requirement: Solr collection, configset, and field administration
The system SHALL expose an admin-gated API for managing Solr collections, configsets, and
schema fields. `SolrController` provides `listCollections`, `listConfigSets`,
`createCollection`, `createConfigSet`, `deleteConfigSet`, and `copyCollection`.
`SolrManagementController` provides `getSolrFields`, `createMissingSolrFields`,
`fixMismatchedSolrFields`, `deleteSolrField`, `deleteSpecificSolrCollection`, and
`updateSolrCollectionAssignments`. `SolrOperationsController` provides `setupSolr`,
`testSolrConnection`, `warmupSolrIndex`, `inspectSolrIndex`, `getSolrMemoryPrediction`,
and `manageSolr`. `ConfigurationSettingsController` provides `getObjectCollectionFields`
and `createMissingObjectFields` to inspect and mirror the object collection's field set.

#### Scenario: Create a Solr collection
- **WHEN** `SolrController::createCollection` runs with a collection name
- **THEN** it MUST create the collection and report the outcome

#### Scenario: Synchronize missing object-collection fields
- **GIVEN** the object collection is configured
- **WHEN** `ConfigurationSettingsController::createMissingObjectFields` runs
- **THEN** it MUST mirror schemas into the collection via `IndexService::mirrorSchemas(force: true)` and report success
- **AND** an unconfigured object collection MUST produce HTTP 400

#### Scenario: Inspect and warm the Solr index
- **WHEN** `SolrOperationsController::warmupSolrIndex` then `inspectSolrIndex` run
- **THEN** warmup MUST populate the index and inspection MUST report current index state

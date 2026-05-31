# Tasks — reverse-spec controller bundle (settings / observability)

Each task documents the observed behavior of one or more controller endpoints and maps to
exactly one new requirement in the matching capability spec delta. Method `@spec`
annotations reference these task anchors.

## production-observability

- [x] task-1 Aggregate settings administration endpoints
  (`SettingsController::index`, `update`, `load`, `updatePublishingOptions`,
  `getObjectSettings`, `updateObjectSettings`, `patchObjectSettings`).
- [x] task-2 Settings dashboard statistics endpoint
  (`SettingsController::stats`, `getStatistics`).
- [x] task-3 Database capability introspection endpoint
  (`SettingsController::getDatabaseInfo`, `refreshDatabaseInfo`).
- [x] task-4 Application version reporting endpoint
  (`SettingsController::getVersionInfo`).
- [x] task-5 Connection keep-alive heartbeat endpoint
  (`HeartbeatController::heartbeat`).
- [x] task-6 Cache administration endpoints
  (`CacheSettingsController::getCacheStats`, `clearCache`, `warmupNamesCache`,
  `getWarmupInterval`, `setWarmupInterval`, `clearAppStoreCache`).
- [x] task-7 Object validation administration endpoints
  (`ValidationSettingsController::validateAllObjects`, `massValidateObjects`,
  `predictMassValidationMemory`).
- [x] task-8 Rate-limit administration endpoints
  (`SecuritySettingsController::clearIpRateLimits`, `clearUserRateLimits`,
  `clearAllRateLimits`).
- [x] task-9 Workflow-integration administration endpoints
  (`N8nSettingsController::getN8nSettings`, `updateN8nSettings`, `testN8nConnection`,
  `initializeN8n`, `getWorkflows`).
- [x] task-10 Integration API-token administration endpoints
  (`ApiTokenSettingsController::getApiTokens`, `saveApiTokens`, `testGitHubToken`,
  `testGitLabToken`).

## retention-management

- [x] task-11 Retention configuration and rebase recalculation endpoints
  (`ConfigurationSettingsController::getRetentionSettings`, `updateRetentionSettings`;
  `SettingsController::rebase`).

## rbac-scopes

- [x] task-12 RBAC enablement/configuration endpoints
  (`ConfigurationSettingsController::getRbacSettings`, `updateRbacSettings`).

## tenant-lifecycle

- [x] task-13 Organisation and multitenancy configuration endpoints
  (`ConfigurationSettingsController::getOrganisationSettings`, `updateOrganisationSettings`,
  `getMultitenancySettings`, `updateMultitenancySettings`).

## archival-destruction-workflow

- [x] task-14 Archival/destruction-scheduling configuration endpoints
  (`ConfigurationSettingsController::getArchivalSettings`, `updateArchivalSettings`).

## zoeken-filteren

- [x] task-15 Search-backend selection and reindex administration
  (`SettingsController::getSearchBackend`, `updateSearchBackend`, `testSetupHandler`,
  `reindexSpecificCollection`).
- [x] task-16 Solr collection and configset lifecycle administration
  (`SolrController::listCollections`, `listConfigSets`, `createCollection`,
  `createConfigSet`, `deleteConfigSet`, `copyCollection`;
  `SolrManagementController::deleteSpecificSolrCollection`,
  `updateSolrCollectionAssignments`;
  `SolrOperationsController::setupSolr`, `testSolrConnection`, `warmupSolrIndex`,
  `inspectSolrIndex`, `getSolrMemoryPrediction`, `manageSolr`).
- [x] task-17 Solr field synchronization administration
  (`SolrManagementController::getSolrFields`, `createMissingSolrFields`,
  `fixMismatchedSolrFields`, `deleteSolrField`;
  `ConfigurationSettingsController::getObjectCollectionFields`,
  `createMissingObjectFields`).
- [x] task-18 Solr settings, info, and dashboard endpoints
  (`SolrSettingsController::getSolrSettings`, `updateSolrSettings`, `getSolrInfo`,
  `getSolrDashboardStats`).

## faceting-configuration

- [x] task-19 Solr facet configuration discovery and management endpoints
  (`SolrSettingsController::getSolrFacetConfiguration`, `updateSolrFacetConfiguration`,
  `discoverSolrFacets`, `getSolrFacetConfigWithDiscovery`,
  `updateSolrFacetConfigWithDiscovery`).

## chat-ai

- [x] task-20 Semantic and hybrid search endpoints
  (`SolrController::semanticSearch`, `hybridSearch`;
  `SettingsController::semanticSearch`, `hybridSearch`).
- [x] task-21 Vectorization and embedding operations endpoints
  (`SolrController::getVectorStats`, `testVectorEmbedding`, `vectorizeObject`,
  `bulkVectorizeObjects`, `getVectorizationStats`).

## schema-driven-read-coercion

- [x] task-22 Object display-name cache resolution endpoints
  (`NamesController::index`, `create`, `show`, `stats`, `warmup`).

<?php

return [
    'resources' => [
        'Registers' => ['url' => 'api/registers'],
        'Schemas' => ['url' => 'api/schemas'],
        'Sources' => ['url' => 'api/sources'],
        'Configurations' => ['url' => 'api/configurations'],
        'Applications' => ['url' => 'api/applications'],
        'Agents' => ['url' => 'api/agents'],
        'Endpoints' => ['url' => 'api/endpoints'],
        'Mappings' => ['url' => 'api/mappings'],
        'Consumers' => ['url' => 'api/consumers'],
    ],
    'routes' => [
        // PATCH routes for resources (partial updates).
        ['name' => 'registers#patch', 'url' => '/api/registers/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#patch', 'url' => '/api/schemas/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#patch', 'url' => '/api/sources/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurations#patch', 'url' => '/api/configurations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'applications#patch', 'url' => '/api/applications/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'agents#patch', 'url' => '/api/agents/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'endpoints#patch', 'url' => '/api/endpoints/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'mappings#patch', 'url' => '/api/mappings/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'consumers#patch', 'url' => '/api/consumers/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],

        // Mappings - Custom routes.
        ['name' => 'mappings#test', 'url' => '/api/mappings/test', 'verb' => 'POST'],

        // Endpoints - Custom routes.
        ['name' => 'endpoints#test', 'url' => '/api/endpoints/{id}/test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#logs', 'url' => '/api/endpoints/{id}/logs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#logStats', 'url' => '/api/endpoints/{id}/logs/stats', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#allLogs', 'url' => '/api/endpoints/logs', 'verb' => 'GET'],

        // Settings - Legacy endpoints (kept for compatibility).
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#rebase', 'url' => '/api/settings/rebase', 'verb' => 'POST'],
        ['name' => 'settings#stats', 'url' => '/api/settings/stats', 'verb' => 'GET'],

        // Migration - Move objects between blob storage and magic tables.
        ['name' => 'migration#status', 'url' => '/api/migration/status/{register}/{schema}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+']],
        ['name' => 'migration#migrate', 'url' => '/api/migration/migrate', 'verb' => 'POST'],
        
        // Settings - Focused endpoints for better performance.
        ['name' => 'settings#getSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'GET'],
        ['name' => 'settings#updateSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'PUT'],
        ['name' => 'settings#updateSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'PATCH'],
        ['name' => 'Settings\SolrSettings#getSolrSettings', 'url' => '/api/settings/solr', 'verb' => 'GET'],
        ['name' => 'Settings\SolrSettings#updateSolrSettings', 'url' => '/api/settings/solr', 'verb' => 'PATCH'],
        ['name' => 'Settings\SolrSettings#updateSolrSettings', 'url' => '/api/settings/solr', 'verb' => 'PUT'],
        ['name' => 'Settings\SolrOperations#testSolrConnection', 'url' => '/api/settings/solr/test', 'verb' => 'POST'],
        ['name' => 'Settings\SolrOperations#warmupSolrIndex', 'url' => '/api/settings/solr/warmup', 'verb' => 'POST'],
        ['name' => 'Settings\SolrOperations#getSolrMemoryPrediction', 'url' => '/api/settings/solr/memory-prediction', 'verb' => 'POST'],
        ['name' => 'Settings\SolrOperations#testSchemaMapping', 'url' => '/api/settings/solr/test-schema-mapping', 'verb' => 'POST'],
        ['name' => 'Settings\SolrSettings#getSolrFacetConfiguration', 'url' => '/api/settings/solr-facet-config', 'verb' => 'GET'],
        ['name' => 'Settings\SolrSettings#updateSolrFacetConfiguration', 'url' => '/api/settings/solr-facet-config', 'verb' => 'POST'],
        ['name' => 'Settings\SolrSettings#discoverSolrFacets', 'url' => '/api/solr/discover-facets', 'verb' => 'GET'],
        ['name' => 'Settings\SolrSettings#getSolrFacetConfigWithDiscovery', 'url' => '/api/solr/facet-config', 'verb' => 'GET'],
        ['name' => 'Settings\SolrSettings#updateSolrFacetConfigWithDiscovery', 'url' => '/api/solr/facet-config', 'verb' => 'POST'],
		['name' => 'Settings\SolrManagement#getSolrFields', 'url' => '/api/solr/fields', 'verb' => 'GET'],
		['name' => 'Settings\SolrManagement#createMissingSolrFields', 'url' => '/api/solr/fields/create-missing', 'verb' => 'POST'],
		['name' => 'Settings\SolrManagement#fixMismatchedSolrFields', 'url' => '/api/solr/fields/fix-mismatches', 'verb' => 'POST'],
	    ['name' => 'Settings\SolrManagement#deleteSolrField', 'url' => '/api/solr/fields/{fieldName}', 'verb' => 'DELETE', 'requirements' => ['fieldName' => '[^/]+']],
		
		// Collection-specific field management.
		['name' => 'Settings\ConfigurationSettings#getObjectCollectionFields', 'url' => '/api/solr/collections/objects/fields', 'verb' => 'GET'],
		['name' => 'Settings\FileSettings#getFileCollectionFields', 'url' => '/api/solr/collections/files/fields', 'verb' => 'GET'],
		['name' => 'Settings\ConfigurationSettings#createMissingObjectFields', 'url' => '/api/solr/collections/objects/fields/create-missing', 'verb' => 'POST'],
		['name' => 'Settings\FileSettings#createMissingFileFields', 'url' => '/api/solr/collections/files/fields/create-missing', 'verb' => 'POST'],
        
        // SOLR Dashboard Management endpoints.
        ['name' => 'Settings\SolrSettings#getSolrDashboardStats', 'url' => '/api/solr/dashboard/stats', 'verb' => 'GET'],
        ['name' => 'Settings\SolrOperations#inspectSolrIndex', 'url' => '/api/settings/solr/inspect', 'verb' => 'POST'],
        ['name' => 'Settings\SolrOperations#manageSolr', 'url' => '/api/solr/manage/{operation}', 'verb' => 'POST'],
        ['name' => 'Settings\SolrOperations#setupSolr', 'url' => '/api/solr/setup', 'verb' => 'POST'],
        ['name' => 'Settings\SolrOperations#testSetupHandler', 'url' => '/api/solr/test-setup', 'verb' => 'POST'],
    
        // Collection-specific operations (with collection name parameter).
        ['name' => 'Settings\SolrManagement#deleteSpecificSolrCollection', 'url' => '/api/solr/collections/{name}', 'verb' => 'DELETE', 'requirements' => ['name' => '[^/]+']],
        ['name' => 'Settings\SolrManagement#clearSpecificCollection', 'url' => '/api/solr/collections/{name}/clear', 'verb' => 'POST', 'requirements' => ['name' => '[^/]+']],
        ['name' => 'Settings\SolrManagement#reindexSpecificCollection', 'url' => '/api/solr/collections/{name}/reindex', 'verb' => 'POST', 'requirements' => ['name' => '[^/]+']],
        
        // SOLR Collection and ConfigSet Management endpoints (SolrController).
        ['name' => 'solr#listCollections', 'url' => '/api/solr/collections', 'verb' => 'GET'],
        ['name' => 'solr#createCollection', 'url' => '/api/solr/collections', 'verb' => 'POST'],
        ['name' => 'solr#listConfigSets', 'url' => '/api/solr/configsets', 'verb' => 'GET'],
        ['name' => 'solr#createConfigSet', 'url' => '/api/solr/configsets', 'verb' => 'POST'],
        ['name' => 'solr#deleteConfigSet', 'url' => '/api/solr/configsets/{name}', 'verb' => 'DELETE'],
        ['name' => 'solr#copyCollection', 'url' => '/api/solr/collections/copy', 'verb' => 'POST'],
        ['name' => 'Settings\SolrManagement#updateSolrCollectionAssignments', 'url' => '/api/solr/collections/assignments', 'verb' => 'PUT'],
        
        // Vector Search endpoints (Semantic and Hybrid Search) - SolrController.
        ['name' => 'solr#semanticSearch', 'url' => '/api/search/semantic', 'verb' => 'POST'],
        ['name' => 'solr#hybridSearch', 'url' => '/api/search/hybrid', 'verb' => 'POST'],
        ['name' => 'solr#getVectorStats', 'url' => '/api/vectors/stats', 'verb' => 'GET'],
        ['name' => 'solr#testVectorEmbedding', 'url' => '/api/vectors/test', 'verb' => 'POST'],
        
        // Object Vectorization endpoints - SolrController.
        ['name' => 'solr#vectorizeObject', 'url' => '/api/objects/{objectId}/vectorize', 'verb' => 'POST'],
        ['name' => 'solr#bulkVectorizeObjects', 'url' => '/api/objects/vectorize/bulk', 'verb' => 'POST'],
        ['name' => 'solr#getVectorizationStats', 'url' => '/api/solr/vectorize/stats', 'verb' => 'GET'],

        // Magic Table Sync endpoints.
        ['name' => 'tables#sync', 'url' => '/api/tables/sync/{registerId}/{schemaId}', 'verb' => 'POST', 'requirements' => ['registerId' => '[^/]+', 'schemaId' => '[^/]+']],
        ['name' => 'tables#syncAll', 'url' => '/api/tables/sync', 'verb' => 'POST'],

        ['name' => 'Settings\ConfigurationSettings#getRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PUT'],
        
        ['name' => 'Settings\ConfigurationSettings#getMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PUT'],
        
        ['name' => 'Settings\ConfigurationSettings#getOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'PUT'],
        
        ['name' => 'Settings\LlmSettings#getLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'GET'],
        ['name' => 'settings#getDatabaseInfo', 'url' => '/api/settings/database', 'verb' => 'GET'],
        ['name' => 'settings#refreshDatabaseInfo', 'url' => '/api/settings/database/refresh', 'verb' => 'POST'],
        ['name' => 'Settings\SolrSettings#getSolrInfo', 'url' => '/api/settings/solr-info', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#patchLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PATCH'],
        ['name' => 'Settings\LlmSettings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PUT'],
        ['name' => 'Settings\LlmSettings#testEmbedding', 'url' => '/api/vectors/test-embedding', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#testChat', 'url' => '/api/llm/test-chat', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#getOllamaModels', 'url' => '/api/llm/ollama-models', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#checkEmbeddingModelMismatch', 'url' => '/api/vectors/check-model-mismatch', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#clearAllEmbeddings', 'url' => '/api/vectors/clear-all', 'verb' => 'DELETE'],
        ['name' => 'Settings\FileSettings#getFileSettings', 'url' => '/api/settings/files', 'verb' => 'GET'],
        ['name' => 'Settings\FileSettings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PATCH'],
        ['name' => 'Settings\FileSettings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PUT'],
        ['name' => 'Settings\FileSettings#getFileExtractionStats', 'url' => '/api/settings/files/stats', 'verb' => 'GET'],
        ['name' => 'Settings\FileSettings#testDolphinConnection', 'url' => '/api/settings/files/test-dolphin', 'verb' => 'POST'],
        ['name' => 'Settings\FileSettings#testPresidioConnection', 'url' => '/api/settings/files/test-presidio', 'verb' => 'POST'],
        ['name' => 'Settings\FileSettings#testOpenAnonymiserConnection', 'url' => '/api/settings/files/test-openanonymiser', 'verb' => 'POST'],
        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'POST'],
        ['name' => 'Settings\ConfigurationSettings#patchObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PUT'],
        
        // Object vectorization endpoints.
        ['name' => 'objects#vectorizeBatch', 'url' => '/api/objects/vectorize/batch', 'verb' => 'POST'],
        ['name' => 'objects#getObjectVectorizationCount', 'url' => '/api/objects/vectorize/count', 'verb' => 'GET'],
        ['name' => 'objects#getObjectVectorizationStats', 'url' => '/api/objects/vectorize/stats', 'verb' => 'GET'],
        
        // Object validation endpoint.
        ['name' => 'objects#validate', 'url' => '/api/objects/validate', 'verb' => 'POST'],
        
        // Core file extraction endpoints (use fileExtraction controller to avoid conflict with files controller).
        // NOTE: Specific routes MUST come before parameterized routes like {id}
        ['name' => 'fileExtraction#index', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'fileExtraction#stats', 'url' => '/api/files/stats', 'verb' => 'GET'],
        ['name' => 'fileExtraction#fileTypes', 'url' => '/api/files/types', 'verb' => 'GET'],
        ['name' => 'fileExtraction#vectorizeBatch', 'url' => '/api/files/vectorize/batch', 'verb' => 'POST'],
        ['name' => 'fileExtraction#discover', 'url' => '/api/files/discover', 'verb' => 'POST'],
        ['name' => 'fileExtraction#extractAll', 'url' => '/api/files/extract', 'verb' => 'POST'],
        ['name' => 'fileExtraction#retryFailed', 'url' => '/api/files/retry-failed', 'verb' => 'POST'],
        ['name' => 'fileExtraction#cleanup', 'url' => '/api/files/cleanup', 'verb' => 'POST'],
        ['name' => 'fileExtraction#show', 'url' => '/api/files/{id}', 'verb' => 'GET'],
        ['name' => 'fileExtraction#extract', 'url' => '/api/files/{id}/extract', 'verb' => 'POST'],
        
        ['name' => 'Settings\ConfigurationSettings#getRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'GET'],
        
        // Debug endpoints for type filtering issue.
        ['name' => 'settings#debugTypeFiltering', 'url' => '/api/debug/type-filtering', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PUT'],
        
        ['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],
        
        // API Tokens for GitHub and GitLab.
        ['name' => 'Settings\ApiTokenSettings#getApiTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'GET'],
        ['name' => 'Settings\ApiTokenSettings#saveApiTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'POST'],
        ['name' => 'Settings\ApiTokenSettings#testGitHubToken', 'url' => '/api/settings/api-tokens/test/github', 'verb' => 'POST'],
        ['name' => 'Settings\ApiTokenSettings#testGitLabToken', 'url' => '/api/settings/api-tokens/test/gitlab', 'verb' => 'POST'],
        
        // n8n workflow integration.
        ['name' => 'Settings\N8nSettings#getN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'GET'],
        ['name' => 'Settings\N8nSettings#updateN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'POST'],
        ['name' => 'Settings\N8nSettings#updateN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'PATCH'],
        ['name' => 'Settings\N8nSettings#updateN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'PUT'],
        ['name' => 'Settings\N8nSettings#testN8nConnection', 'url' => '/api/settings/n8n/test', 'verb' => 'POST'],
        ['name' => 'Settings\N8nSettings#initializeN8n', 'url' => '/api/settings/n8n/initialize', 'verb' => 'POST'],
        ['name' => 'Settings\N8nSettings#getWorkflows', 'url' => '/api/settings/n8n/workflows', 'verb' => 'GET'],
        
        // Statistics endpoint.  
        ['name' => 'settings#getStatistics', 'url' => '/api/settings/statistics', 'verb' => 'GET'],
        
        // Cache management.
        ['name' => 'Settings\CacheSettings#getCacheStats', 'url' => '/api/settings/cache', 'verb' => 'GET'],
        ['name' => 'Settings\CacheSettings#clearCache', 'url' => '/api/settings/cache', 'verb' => 'DELETE'],
        ['name' => 'Settings\CacheSettings#warmupNamesCache', 'url' => '/api/settings/cache/warmup-names', 'verb' => 'POST'],
        ['name' => 'Settings\CacheSettings#getWarmupInterval', 'url' => '/api/settings/cache/warmup-interval', 'verb' => 'GET'],
        ['name' => 'Settings\CacheSettings#setWarmupInterval', 'url' => '/api/settings/cache/warmup-interval', 'verb' => 'PUT'],
        ['name' => 'Settings\CacheSettings#clearAppStoreCache', 'url' => '/api/settings/cache/appstore', 'verb' => 'DELETE'],

        // Security management - Rate limiting and IP blocking.
        ['name' => 'Settings\SecuritySettings#clearIpRateLimits', 'url' => '/api/settings/security/unblock-ip', 'verb' => 'POST'],
        ['name' => 'Settings\SecuritySettings#clearUserRateLimits', 'url' => '/api/settings/security/unblock-user', 'verb' => 'POST'],
        ['name' => 'Settings\SecuritySettings#clearAllRateLimits', 'url' => '/api/settings/security/unblock', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#validateAllObjects', 'url' => '/api/settings/validate-all-objects', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#massValidateObjects', 'url' => '/api/settings/mass-validate', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#predictMassValidationMemory', 'url' => '/api/settings/mass-validate/memory-prediction', 'verb' => 'POST'],
        // Heartbeat - Keep-alive endpoint for long-running operations.
        ['name' => 'heartbeat#heartbeat', 'url' => '/api/heartbeat', 'verb' => 'GET'],
        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],
        // URN resolution endpoints (RFC 8141 system-independent identifiers).
        ['name' => 'urn#resolve', 'url' => '/api/urn/resolve', 'verb' => 'GET'],
        ['name' => 'urn#lookup',  'url' => '/api/urn/lookup',  'verb' => 'GET'],
        ['name' => 'urn#bulk',    'url' => '/api/urn/bulk',    'verb' => 'POST'],
        // RBAC scope discovery endpoint — clients query effective (register,
        // schema, action) scopes for the authenticated user without probing
        // every endpoint individually.
        ['name' => 'scopes#index', 'url' => '/api/scopes', 'verb' => 'GET'],
        // AVG / GDPR Art 30 verwerkingsregister CRUD + verantwoordingsdocument.
        ['name' => 'verwerkingsactiviteiten#index',          'url' => '/api/avg/verwerkingsactiviteiten',        'verb' => 'GET'],
        ['name' => 'verwerkingsactiviteiten#show',           'url' => '/api/avg/verwerkingsactiviteiten/{id}',   'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#create',         'url' => '/api/avg/verwerkingsactiviteiten',        'verb' => 'POST'],
        ['name' => 'verwerkingsactiviteiten#update',         'url' => '/api/avg/verwerkingsactiviteiten/{id}',   'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#destroy',        'url' => '/api/avg/verwerkingsactiviteiten/{id}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#verantwoording', 'url' => '/api/avg/verantwoording',                 'verb' => 'GET'],
        // AVG / GDPR data-subject rights endpoints (Phase 2b).
        ['name' => 'dsar#inzage',         'url' => '/api/avg/inzage',         'verb' => 'GET'],
        ['name' => 'dsar#portabiliteit',  'url' => '/api/avg/portabiliteit',  'verb' => 'GET'],
        ['name' => 'dsar#vergetelheid',   'url' => '/api/avg/vergetelheid',   'verb' => 'POST'],
        ['name' => 'dsar#rectificatie',   'url' => '/api/avg/rectificatie',   'verb' => 'POST'],
        ['name' => 'dsar#compliance',     'url' => '/api/avg/compliance',     'verb' => 'GET'],
        // Realtime cursor-based polling endpoints.
        ['name' => 'realtime#events', 'url' => '/api/realtime/events', 'verb' => 'GET'],
        ['name' => 'realtime#cursor', 'url' => '/api/realtime/cursor', 'verb' => 'GET'],
        // Translation sidecar — search, per-object slots + completeness, status updates.
        ['name' => 'translation#search',        'url' => '/api/translations/search',                                          'verb' => 'GET'],
        ['name' => 'translation#showByObject',  'url' => '/api/translations/object/{uuid}',                                   'verb' => 'GET'],
        ['name' => 'translation#setStatus',     'url' => '/api/translations/object/{uuid}/{property}/{language}/status',      'verb' => 'POST'],
        ['name' => 'translation#bulkTranslate', 'url' => '/api/translations/object/{uuid}/bulk-translate',                    'verb' => 'POST'],
        // Names - Ultra-fast object name lookup endpoints (specific routes first).
        ['name' => 'names#stats', 'url' => '/api/names/stats', 'verb' => 'GET'],
        ['name' => 'names#warmup', 'url' => '/api/names/warmup', 'verb' => 'POST'],
        ['name' => 'names#index', 'url' => '/api/names', 'verb' => 'GET'],
        ['name' => 'names#create', 'url' => '/api/names', 'verb' => 'POST'],
        ['name' => 'names#show', 'url' => '/api/names/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Dashbaord.
        ['name' => 'dashboard#index', 'url' => '/api/dashboard', 'verb' => 'GET'],
        ['name' => 'dashboard#calculate', 'url' => '/api/dashboard/calculate/{registerId}', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
        // Dashboard Charts.
        ['name' => 'dashboard#getAuditTrailActionChart', 'url' => '/api/dashboard/charts/audit-trail-actions', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsByRegisterChart', 'url' => '/api/dashboard/charts/objects-by-register', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySchemaChart', 'url' => '/api/dashboard/charts/objects-by-schema', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySizeChart', 'url' => '/api/dashboard/charts/objects-by-size', 'verb' => 'GET'],
        // Dashboard Statistics.
        ['name' => 'dashboard#getAuditTrailStatistics', 'url' => '/api/dashboard/statistics/audit-trail', 'verb' => 'GET'],
        ['name' => 'dashboard#getAuditTrailActionDistribution', 'url' => '/api/dashboard/statistics/audit-trail-distribution', 'verb' => 'GET'],
        ['name' => 'dashboard#getMostActiveObjects', 'url' => '/api/dashboard/statistics/most-active-objects', 'verb' => 'GET'],
        // Linked entities (mail sidebar, contacts sidebar, etc.).
        // Must be before objects/{register}/{schema} routes to avoid wildcard matching.
        ['name' => 'linked_entity#addObjectLink', 'url' => '/api/objects/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        ['name' => 'linked_entity#removeObjectLink', 'url' => '/api/objects/{uuid}/_linked/{type}/{entityId}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+', 'entityId' => '[^/]+']],
        ['name' => 'linked_entity#addRegisterLink', 'url' => '/api/registers/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        ['name' => 'linked_entity#addSchemaLink', 'url' => '/api/schemas/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        ['name' => 'linked_entity#reverseLookup', 'url' => '/api/linked/{type}/{entityId}', 'verb' => 'GET', 'requirements' => ['type' => '[^/]+', 'entityId' => '.+']],

        // Objects.
        ['name' => 'objects#objects', 'url' => '/api/objects', 'verb' => 'GET'],
        ['name' => 'objects#clearBlob', 'url' => '/api/objects/clear-blob', 'verb' => 'DELETE'],
        // ['name' => 'objects#import', 'url' => '/api/objects/{register}/import', 'verb' => 'POST'], // DISABLED: Use registers import endpoint instead
        // Lifecycle transitions — MUST precede the wildcard {register}/{schema} routes
        // so /api/objects/{id}/transition isn't grabbed as register=id, schema=transition.
        ['name' => 'transition#transition', 'url' => '/api/objects/{id}/transition', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'transition#availableActions', 'url' => '/api/objects/{id}/available-actions', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

        // Aggregations sugar endpoint.
        ['name' => 'aggregation#aggregate', 'url' => '/api/objects/aggregations/{register}/{schema}/{name}', 'verb' => 'GET'],

        // Contacts matching API — used by ContactsMenuProvider + mail-sidebar.
        ['name' => 'contacts#match', 'url' => '/api/contacts/match', 'verb' => 'GET'],

        // Mail sidebar — reverse lookup of OR objects linked to an email.
        // Search + bySender are app-global (no register/schema in path); the
        // CRUD endpoints are scoped to an object so they take register/schema/id.
        ['name' => 'emails#search',   'url' => '/api/emails/search',                                'verb' => 'GET'],
        ['name' => 'emails#bySender', 'url' => '/api/emails/by-sender',                             'verb' => 'GET'],
        ['name' => 'emails#index',    'url' => '/api/objects/{register}/{schema}/{id}/emails',     'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'emails#create',   'url' => '/api/objects/{register}/{schema}/{id}/emails',     'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'emails#destroy',  'url' => '/api/objects/{register}/{schema}/{id}/emails/{emailId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'emailId' => '[0-9]+']],

        // Contacts — object↔NC contact links + reverse lookup. Match is app-global.
        ['name' => 'contacts#index',   'url' => '/api/objects/{register}/{schema}/{id}/contacts',                 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#create',  'url' => '/api/objects/{register}/{schema}/{id}/contacts',                 'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#update',  'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactUid}',    'verb' => 'PUT',    'requirements' => ['id' => '[^/]+', 'contactUid' => '[^/]+']],
        ['name' => 'contacts#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactUid}',    'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'contactUid' => '[^/]+']],
        ['name' => 'contacts#objects', 'url' => '/api/contacts/{contactUid}/objects',                              'verb' => 'GET',    'requirements' => ['contactUid' => '[^/]+']],

        // Calendar events — object↔CalDAV event links via DAV principal.
        ['name' => 'calendarEvents#index',   'url' => '/api/objects/{register}/{schema}/{id}/events',             'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#create',  'url' => '/api/objects/{register}/{schema}/{id}/events',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#link',    'url' => '/api/objects/{register}/{schema}/{id}/events/link',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/events/{eventId}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'eventId' => '[^/]+']],

        // Deck — object↔Deck card links + reverse lookup.
        ['name' => 'deck#index',   'url' => '/api/objects/{register}/{schema}/{id}/deck',                  'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'deck#create',  'url' => '/api/objects/{register}/{schema}/{id}/deck',                  'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'deck#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/deck/{deckRef}',        'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'deckRef' => '[^/]+']],
        ['name' => 'deck#objects', 'url' => '/api/deck/boards/{boardId}/objects',                          'verb' => 'GET',    'requirements' => ['boardId' => '[^/]+']],

        // Unified relations endpoint — aggregates emails/contacts/calendar/deck for an object.
        ['name' => 'relations#index', 'url' => '/api/objects/{register}/{schema}/{id}/relations',          'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],

        // Linked-entity-types — generic per-{type} link API (mail / event / contact / deck).
        ['name' => 'linkedEntity#addObjectLink',    'url' => '/api/objects/{uuid}/_{type}',           'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#removeObjectLink', 'url' => '/api/objects/{uuid}/_{type}/{entityId}','verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+', 'entityId' => '[^/]+']],
        ['name' => 'linkedEntity#addRegisterLink',  'url' => '/api/registers/{uuid}/_{type}',         'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#addSchemaLink',    'url' => '/api/schemas/{uuid}/_{type}',           'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#reverseLookup',    'url' => '/api/linked/_{type}/{entityId}',        'verb' => 'GET',    'requirements' => ['type' => '[a-z]+', 'entityId' => '[^/]+']],

        // TMLO metadata export endpoints (declarative archival metadata per Dutch TMLO standard).
        ['name' => 'tmlo#summary',      'url' => '/api/tmlo/{register}/{schema}/summary',                'verb' => 'GET'],
        ['name' => 'tmlo#exportSingle', 'url' => '/api/tmlo/{register}/{schema}/{id}/export',            'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'tmlo#exportBatch',  'url' => '/api/tmlo/{register}/{schema}/export',                 'verb' => 'GET'],

        // FileSidebar — list OR objects connected to a Files entry + show extraction state.
        ['name' => 'fileSidebar#getObjectsForFile',    'url' => '/api/files/{fileId}/objects',           'verb' => 'GET',  'requirements' => ['fileId' => '[0-9]+']],
        ['name' => 'fileSidebar#getExtractionStatus',  'url' => '/api/files/{fileId}/extraction-status', 'verb' => 'GET',  'requirements' => ['fileId' => '[0-9]+']],

        // Action registry CRUD + utilities.
        ['name' => 'actions#index',            'url' => '/api/actions',                          'verb' => 'GET'],
        ['name' => 'actions#create',           'url' => '/api/actions',                          'verb' => 'POST'],
        ['name' => 'actions#show',             'url' => '/api/actions/{id}',                     'verb' => 'GET',    'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#update',           'url' => '/api/actions/{id}',                     'verb' => 'PUT',    'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#patch',            'url' => '/api/actions/{id}',                     'verb' => 'PATCH',  'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#destroy',          'url' => '/api/actions/{id}',                     'verb' => 'DELETE', 'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#test',             'url' => '/api/actions/{id}/test',                'verb' => 'POST',   'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#logs',             'url' => '/api/actions/{id}/logs',                'verb' => 'GET',    'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#migrateFromHooks', 'url' => '/api/actions/migrate-hooks/{schemaId}', 'verb' => 'POST',   'requirements' => ['schemaId' => '[0-9]+']],

        ['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET'],

        ['name' => 'objects#geoSearch', 'url' => '/api/objects/{register}/{schema}/geo-search', 'verb' => 'POST'],

        ['name' => 'objects#create', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'POST'],
        ['name' => 'objects#export', 'url' => '/api/objects/{register}/{schema}/export', 'verb' => 'GET'],
        ['name' => 'objects#show', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#update', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#patch', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#postPatch', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#destroy', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#canDelete', 'url' => '/api/objects/{register}/{schema}/{id}/can-delete', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#merge', 'url' => '/api/objects/{register}/{schema}/{id}/merge', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#migrate', 'url' => '/api/migrate', 'verb' => 'POST'],
        // Relations.
        ['name' => 'objects#contracts', 'url' => '/api/objects/{register}/{schema}/{id}/contracts', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#uses', 'url' => '/api/objects/{register}/{schema}/{id}/uses', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#used', 'url' => '/api/objects/{register}/{schema}/{id}/used', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Locks.
        ['name' => 'objects#lock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/unlock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Bulk Operations.
        ['name' => 'bulk#save', 'url' => '/api/bulk/{register}/{schema}/save', 'verb' => 'POST'],
        ['name' => 'bulk#delete', 'url' => '/api/bulk/{register}/{schema}/delete', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchema', 'url' => '/api/bulk/{register}/{schema}/delete-schema', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchemaObjects', 'url' => '/api/bulk/{register}/{schema}/delete-objects', 'verb' => 'POST'],
        ['name' => 'bulk#deleteRegister', 'url' => '/api/bulk/{register}/delete-register', 'verb' => 'POST'],
        ['name' => 'bulk#validateSchema', 'url' => '/api/bulk/schema/{schema}/validate', 'verb' => 'POST'],
        // Audit Trails — specific routes MUST come before parameterized {id} routes.
        ['name' => 'auditTrail#objects', 'url' => '/api/objects/{register}/{schema}/{id}/audit-trails', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#index', 'url' => '/api/audit-trails', 'verb' => 'GET'],
        ['name' => 'auditTrail#export', 'url' => '/api/audit-trails/export', 'verb' => 'GET'],
        ['name' => 'auditTrail#verify', 'url' => '/api/audit-trails/verify', 'verb' => 'GET'],
        ['name' => 'auditTrail#verwerkingsregister', 'url' => '/api/audit-trails/verwerkingsregister', 'verb' => 'GET'],
        ['name' => 'auditTrail#inzageverzoek', 'url' => '/api/audit-trails/inzageverzoek', 'verb' => 'GET'],
        ['name' => 'auditTrail#clearAll', 'url' => '/api/audit-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'auditTrail#show', 'url' => '/api/audit-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#update', 'url' => '/api/audit-trails/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroy', 'url' => '/api/audit-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroyMultiple', 'url' => '/api/audit-trails', 'verb' => 'DELETE'],
        // Notification History — read-only audit trail of every dispatch.
        ['name' => 'notificationHistory#index', 'url' => '/api/notification-history', 'verb' => 'GET'],
        // Notification Subscriptions — per-user (register, schema) opt-in surface.
        ['name' => 'notificationSubscriptions#index',   'url' => '/api/notification-subscriptions', 'verb' => 'GET'],
        ['name' => 'notificationSubscriptions#create',  'url' => '/api/notification-subscriptions', 'verb' => 'POST'],
        ['name' => 'notificationSubscriptions#destroy', 'url' => '/api/notification-subscriptions', 'verb' => 'DELETE'],
        // Search Trails - specific routes first, then general ones.
        ['name' => 'searchTrail#index', 'url' => '/api/search-trails', 'verb' => 'GET'],
        ['name' => 'searchTrail#statistics', 'url' => '/api/search-trails/statistics', 'verb' => 'GET'],
        ['name' => 'searchTrail#popularTerms', 'url' => '/api/search-trails/popular-terms', 'verb' => 'GET'],
        ['name' => 'searchTrail#activity', 'url' => '/api/search-trails/activity', 'verb' => 'GET'],
        ['name' => 'searchTrail#registerSchemaStats', 'url' => '/api/search-trails/register-schema-stats', 'verb' => 'GET'],
        ['name' => 'searchTrail#userAgentStats', 'url' => '/api/search-trails/user-agent-stats', 'verb' => 'GET'],
        ['name' => 'searchTrail#export', 'url' => '/api/search-trails/export', 'verb' => 'GET'],
        ['name' => 'searchTrail#cleanup', 'url' => '/api/search-trails/cleanup', 'verb' => 'POST'],
        ['name' => 'searchTrail#destroyMultiple', 'url' => '/api/search-trails', 'verb' => 'DELETE'],
        ['name' => 'searchTrail#clearAll', 'url' => '/api/search-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'searchTrail#show', 'url' => '/api/search-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'searchTrail#destroy', 'url' => '/api/search-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        // Deleted Objects.
        ['name' => 'deleted#index', 'url' => '/api/deleted', 'verb' => 'GET'],
        ['name' => 'deleted#statistics', 'url' => '/api/deleted/statistics', 'verb' => 'GET'],
        ['name' => 'deleted#topDeleters', 'url' => '/api/deleted/top-deleters', 'verb' => 'GET'],
        ['name' => 'deleted#restore', 'url' => '/api/deleted/{id}/restore', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#restoreMultiple', 'url' => '/api/deleted/restore', 'verb' => 'POST'],
        ['name' => 'deleted#destroy', 'url' => '/api/deleted/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#destroyMultiple', 'url' => '/api/deleted', 'verb' => 'DELETE'],
        // Revert.
        ['name' => 'revert#revert', 'url' => '/api/objects/{register}/{schema}/{id}/revert', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        
        // Files operations under objects.
		['name' => 'files#create', 'url' => '/api/objects/{register}/{schema}/{id}/files', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#save', 'url' => '/api/objects/{register}/{schema}/{id}/files/save', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#index', 'url' => '/api/objects/{register}/{schema}/{id}/files', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'files#show', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
        ['name' => 'objects#downloadFiles', 'url' => '/api/objects/{register}/{schema}/{id}/files/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#createMultipart', 'url' => '/api/objects/{register}/{schema}/{id}/filesMultipart', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#update', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#delete', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#publish', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/publish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#depublish', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/depublish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],

		// File-actions (rename / copy / move / versions / lock / batch / preview / labels).
		['name' => 'files#rename',         'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/rename',                       'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#copy',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/copy',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#move',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/move',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#listVersions',   'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/versions',                     'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#restoreVersion', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/versions/{versionId}/restore', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+', 'versionId' => '[^/]+']],
		['name' => 'files#lock',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/lock',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#unlock',         'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/unlock',                       'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#batch',          'url' => '/api/objects/{register}/{schema}/{id}/files/batch',                                 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#preview',        'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/preview',                      'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#updateLabels',   'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/labels',                       'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],

        // Direct file access by ID (authenticated).
        ['name' => 'files#downloadById', 'url' => '/api/files/{fileId}/download', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],

        // Tasks: user-scoped listing (all CalDAV VTODOs for current user).
        ['name' => 'tasks#allUserTasks', 'url' => '/api/tasks', 'verb' => 'GET'],

        // Tasks operations under objects (CalDAV VTODO wrapper).
        ['name' => 'tasks#index', 'url' => '/api/objects/{register}/{schema}/{id}/tasks', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'tasks#create', 'url' => '/api/objects/{register}/{schema}/{id}/tasks', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'tasks#update', 'url' => '/api/objects/{register}/{schema}/{id}/tasks/{taskId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'taskId' => '[^/]+']],
        ['name' => 'tasks#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/tasks/{taskId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'taskId' => '[^/]+']],

        // Notes operations under objects (Nextcloud Comments wrapper).
        ['name' => 'notes#index', 'url' => '/api/objects/{register}/{schema}/{id}/notes', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'notes#create', 'url' => '/api/objects/{register}/{schema}/{id}/notes', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'notes#update', 'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+']],
        ['name' => 'notes#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+']],
        
        // Schemas.
        ['name' => 'schemas#upload', 'url' => '/api/schemas/upload', 'verb' => 'POST'],
        ['name' => 'schemas#uploadUpdate', 'url' => '/api/schemas/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#download', 'url' => '/api/schemas/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#related', 'url' => '/api/schemas/{id}/related', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#stats', 'url' => '/api/schemas/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#explore', 'url' => '/api/schemas/{id}/explore', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#updateFromExploration', 'url' => '/api/schemas/{id}/update-from-exploration', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#publish', 'url' => '/api/schemas/{id}/publish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#depublish', 'url' => '/api/schemas/{id}/depublish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Registers
        ['name' => 'registers#export', 'url' => '/api/registers/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#import', 'url' => '/api/registers/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#rollbackImport', 'url' => '/api/registers/import/rollback', 'verb' => 'POST'],
        [
            'name'         => 'registers#importTemplate',
            'url'          => '/api/registers/{id}/schemas/{schema}/import-template',
            'verb'         => 'GET',
            'requirements' => ['id' => '[^/]+', 'schema' => '[^/]+'],
        ],
        ['name' => 'registers#publishToGitHub', 'url' => '/api/registers/{id}/publish/github', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#publish', 'url' => '/api/registers/{id}/publish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#depublish', 'url' => '/api/registers/{id}/depublish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#schemas', 'url' => '/api/registers/{id}/schemas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#stats', 'url' => '/api/registers/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generate', 'url' => '/api/registers/{id}/oas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generateAll', 'url' => '/api/registers/oas', 'verb' => 'GET'],
        // Configurations - Management.
        ['name' => 'configuration#checkVersion', 'url' => '/api/configurations/{id}/check-version', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#preview', 'url' => '/api/configurations/{id}/preview', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#import', 'url' => '/api/configurations/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#export', 'url' => '/api/configurations/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        
        // Configuration discovery endpoints.
        ['name' => 'configuration#discover', 'url' => '/api/configurations/discover', 'verb' => 'GET'],
        ['name' => 'configuration#enrichDetails', 'url' => '/api/configurations/enrich', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubBranches', 'url' => '/api/configurations/github/branches', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubRepositories', 'url' => '/api/configurations/github/repositories', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubConfigurations', 'url' => '/api/configurations/github/files', 'verb' => 'GET'],
        ['name' => 'configuration#getGitLabBranches', 'url' => '/api/configurations/gitlab/branches', 'verb' => 'GET'],
        ['name' => 'configuration#getGitLabConfigurations', 'url' => '/api/configurations/gitlab/files', 'verb' => 'GET'],
        
        // Configuration import endpoints.
        ['name' => 'configurations#import', 'url' => '/api/configurations/import', 'verb' => 'POST'],
        ['name' => 'configuration#importFromGitHub', 'url' => '/api/configurations/import/github', 'verb' => 'POST'],
        ['name' => 'configuration#importFromGitLab', 'url' => '/api/configurations/import/gitlab', 'verb' => 'POST'],
        ['name' => 'configuration#importFromUrl', 'url' => '/api/configurations/import/url', 'verb' => 'POST'],
        
        // Configuration publish endpoints.
        ['name' => 'configuration#publishToGitHub', 'url' => '/api/configurations/{id}/publish/github', 'verb' => 'POST'],
        
        // User Settings - GitHub Integration.
        ['name' => 'userSettings#getGitHubTokenStatus', 'url' => '/api/user-settings/github/status', 'verb' => 'GET'],
        ['name' => 'userSettings#setGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'POST'],
        ['name' => 'userSettings#removeGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'DELETE'],
        // Applications.
        ['name' => 'applications#page', 'url' => '/applications', 'verb' => 'GET'],
        // Agents.
        ['name' => 'agents#page', 'url' => '/agents', 'verb' => 'GET'],
        ['name' => 'agents#stats', 'url' => '/api/agents/stats', 'verb' => 'GET'],
        ['name' => 'agents#tools', 'url' => '/api/agents/tools', 'verb' => 'GET'],
        // Search.
        ['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
        // Organisations - Multi-tenancy management.
        ['name' => 'organisation#index', 'url' => '/api/organisations', 'verb' => 'GET'],
        ['name' => 'organisation#create', 'url' => '/api/organisations', 'verb' => 'POST'],
        ['name' => 'organisation#search', 'url' => '/api/organisations/search', 'verb' => 'GET'],
        ['name' => 'organisation#stats', 'url' => '/api/organisations/stats', 'verb' => 'GET'],
        ['name' => 'organisation#stats', 'url' => '/api/organisations/statistics', 'verb' => 'GET'],
        ['name' => 'organisation#clearCache', 'url' => '/api/organisations/clear-cache', 'verb' => 'POST'],
        ['name' => 'organisation#getActive', 'url' => '/api/organisations/active', 'verb' => 'GET'],
        ['name' => 'organisation#show', 'url' => '/api/organisations/{uuid}', 'verb' => 'GET'],
        ['name' => 'organisation#update', 'url' => '/api/organisations/{uuid}', 'verb' => 'PUT'],
        ['name' => 'organisation#patch', 'url' => '/api/organisations/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'organisation#setActive', 'url' => '/api/organisations/{uuid}/set-active', 'verb' => 'POST'],
        ['name' => 'organisation#join', 'url' => '/api/organisations/{uuid}/join', 'verb' => 'POST'],
        ['name' => 'organisation#leave', 'url' => '/api/organisations/{uuid}/leave', 'verb' => 'POST'],

        // Organisations - Tenant lifecycle management.
        ['name' => 'organisation#suspend', 'url' => '/api/organisations/{uuid}/suspend', 'verb' => 'PUT'],
        ['name' => 'organisation#activate', 'url' => '/api/organisations/{uuid}/activate', 'verb' => 'PUT'],
        ['name' => 'organisation#deprovision', 'url' => '/api/organisations/{uuid}/deprovision', 'verb' => 'PUT'],
        ['name' => 'organisation#usage', 'url' => '/api/organisations/{uuid}/usage', 'verb' => 'GET'],

        // Admin - Tenant isolation verification and metrics.
        ['name' => 'organisation#isolationVerify', 'url' => '/api/admin/isolation-verify', 'verb' => 'POST'],
        ['name' => 'organisation#isolationMetrics', 'url' => '/api/admin/isolation-metrics', 'verb' => 'GET'],
		// Tags.
		['name' => 'tags#getAllTags', 'url' => '/api/tags', 'verb' => 'GET'],
		['name' => 'tags#index',  'url' => '/api/objects/{register}/{schema}/{id}/tags',       'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
		['name' => 'tags#add',    'url' => '/api/objects/{register}/{schema}/{id}/tags',       'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
		['name' => 'tags#remove', 'url' => '/api/objects/{register}/{schema}/{id}/tags/{tag}', 'verb' => 'DELETE', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'tag' => '[^/]+']],
		
		// Views - Saved search configurations.
		['name' => 'views#index', 'url' => '/api/views', 'verb' => 'GET'],
		['name' => 'views#show', 'url' => '/api/views/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#create', 'url' => '/api/views', 'verb' => 'POST'],
		['name' => 'views#update', 'url' => '/api/views/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#patch', 'url' => '/api/views/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#destroy', 'url' => '/api/views/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		
		// Chat - AI Assistant endpoints.
		['name' => 'chat#sendMessage', 'url' => '/api/chat/send', 'verb' => 'POST'],
		['name' => 'chat#getHistory', 'url' => '/api/chat/history', 'verb' => 'GET'],
		['name' => 'chat#clearHistory', 'url' => '/api/chat/history', 'verb' => 'DELETE'],
		['name' => 'chat#getChatStats', 'url' => '/api/chat/stats', 'verb' => 'GET'],
		['name' => 'chat#sendFeedback', 'url' => '/api/conversations/{conversationUuid}/messages/{messageId}/feedback', 'verb' => 'POST', 'requirements' => ['conversationUuid' => '[^/]+', 'messageId' => '\\d+']],
		
		// Conversations - AI Conversation management.
		['name' => 'conversation#index', 'url' => '/api/conversations', 'verb' => 'GET'],
		['name' => 'conversation#show', 'url' => '/api/conversations/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#messages', 'url' => '/api/conversations/{uuid}/messages', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#create', 'url' => '/api/conversations', 'verb' => 'POST'],
		['name' => 'conversation#update', 'url' => '/api/conversations/{uuid}', 'verb' => 'PATCH', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroy', 'url' => '/api/conversations/{uuid}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#restore', 'url' => '/api/conversations/{uuid}/restore', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroyPermanent', 'url' => '/api/conversations/{uuid}/permanent', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],
		
		// File Text Management - Extract and manage text from files.
		['name' => 'fileText#getFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'GET', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#extractFileText', 'url' => '/api/files/{fileId}/extract', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#bulkExtract', 'url' => '/api/files/extract/bulk', 'verb' => 'POST'],
		['name' => 'fileText#getStats', 'url' => '/api/files/extraction/stats', 'verb' => 'GET'],
		['name' => 'fileText#deleteFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'DELETE', 'requirements' => ['fileId' => '\\d+']],
		
		// File Chunking & Indexing - Process extracted files and index chunks in SOLR.
		['name' => 'fileText#processAndIndexExtracted', 'url' => '/api/files/chunks/process', 'verb' => 'POST'],
		['name' => 'fileText#processAndIndexFile', 'url' => '/api/files/{fileId}/chunks/process', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#getChunkingStats', 'url' => '/api/files/chunks/stats', 'verb' => 'GET'],

		// File Anonymization - Replace detected entities with placeholders.
		['name' => 'fileText#anonymizeFile', 'url' => '/api/files/{fileId}/anonymize', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],

		// GDPR Entities - Manage detected PII entities.
		['name' => 'gdprEntities#index', 'url' => '/api/entities', 'verb' => 'GET'],
		['name' => 'gdprEntities#show', 'url' => '/api/entities/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'gdprEntities#destroy', 'url' => '/api/entities/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'gdprEntities#getTypes', 'url' => '/api/entities/types', 'verb' => 'GET'],
		['name' => 'gdprEntities#getCategories', 'url' => '/api/entities/categories', 'verb' => 'GET'],
		['name' => 'gdprEntities#getStats', 'url' => '/api/entities/stats', 'verb' => 'GET'],

		// File Warmup & Indexing - Bulk process and index files in SOLR.
		['name' => 'Settings\FileSettings#warmupFiles', 'url' => '/api/solr/warmup/files', 'verb' => 'POST'],
		['name' => 'Settings\FileSettings#indexFile', 'url' => '/api/solr/files/{fileId}/index', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'Settings\FileSettings#reindexFiles', 'url' => '/api/solr/files/reindex', 'verb' => 'POST'],
		['name' => 'Settings\FileSettings#getFileIndexStats', 'url' => '/api/solr/files/stats', 'verb' => 'GET'],
		
		// File Search - Keyword, semantic, and hybrid search over file contents.
		['name' => 'fileSearch#keywordSearch', 'url' => '/api/search/files/keyword', 'verb' => 'POST'],
		['name' => 'fileSearch#semanticSearch', 'url' => '/api/search/files/semantic', 'verb' => 'POST'],
		['name' => 'fileSearch#hybridSearch', 'url' => '/api/search/files/hybrid', 'verb' => 'POST'],

		// Page routes.
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'], // you cannot remove `dashboard#page` as the dashboard expects this.
		['name' => 'ui#registers', 'url' => '/registers', 'verb' => 'GET'],
		['name' => 'ui#registersDetails', 'url' => '/registers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#schemas', 'url' => '/schemas', 'verb' => 'GET'],
		['name' => 'ui#schemasDetails', 'url' => '/schemas/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#sources', 'url' => '/sources', 'verb' => 'GET'],
		['name' => 'ui#organisation', 'url' => '/organisation', 'verb' => 'GET'],
		['name' => 'ui#objects', 'url' => '/objects', 'verb' => 'GET'],
		['name' => 'ui#tables', 'url' => '/tables', 'verb' => 'GET'],
		['name' => 'ui#chat', 'url' => '/chat', 'verb' => 'GET'],
		['name' => 'ui#configurations', 'url' => '/configurations', 'verb' => 'GET'],
		['name' => 'ui#deleted', 'url' => '/deleted', 'verb' => 'GET'],
		['name' => 'ui#auditTrail', 'url' => '/audit-trails', 'verb' => 'GET'],
		['name' => 'ui#searchTrail', 'url' => '/search-trails', 'verb' => 'GET'],
		['name' => 'ui#webhooks', 'url' => '/webhooks', 'verb' => 'GET'],
		['name' => 'ui#webhooksLogs', 'url' => '/webhooks/logs', 'verb' => 'GET'],
		['name' => 'ui#endpoints', 'url' => '/endpoints', 'verb' => 'GET'],
		['name' => 'ui#endpointLogs', 'url' => '/endpoints/logs', 'verb' => 'GET'],
		['name' => 'ui#entities', 'url' => '/entities', 'verb' => 'GET'],
		['name' => 'ui#entitiesDetails', 'url' => '/entities/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'ui#avg', 'url' => '/avg', 'verb' => 'GET'],
		['name' => 'ui#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'ui#reportView', 'url' => '/reports/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		// Rapportage on-demand render endpoints (Phase 2).
		['name' => 'reports#render',  'url' => '/api/reports/{id}/render',  'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'reports#preview', 'url' => '/api/reports/{id}/preview', 'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
		['name' => 'files#page', 'url' => '/files', 'verb' => 'GET'],

		// User - Profile management and authentication.
		['name' => 'user#me', 'url' => '/api/user/me', 'verb' => 'GET'],
		['name' => 'user#updateMe', 'url' => '/api/user/me', 'verb' => 'PUT'],
		['name' => 'user#login', 'url' => '/api/user/login', 'verb' => 'POST'],
		['name' => 'user#logout', 'url' => '/api/user/logout', 'verb' => 'POST'],

		// profile-actions — self-service endpoints for the current user (/api/user/me).
		['name' => 'user#changePassword',                  'url' => '/api/user/me/password',             'verb' => 'PUT'],
		['name' => 'user#uploadAvatar',                    'url' => '/api/user/me/avatar',               'verb' => 'POST'],
		['name' => 'user#deleteAvatar',                    'url' => '/api/user/me/avatar',               'verb' => 'DELETE'],
		['name' => 'user#exportData',                      'url' => '/api/user/me/export',               'verb' => 'GET'],
		['name' => 'user#getNotificationPreferences',      'url' => '/api/user/me/notifications',        'verb' => 'GET'],
		['name' => 'user#updateNotificationPreferences',   'url' => '/api/user/me/notifications',        'verb' => 'PUT'],
		['name' => 'user#getActivity',                     'url' => '/api/user/me/activity',             'verb' => 'GET'],
		['name' => 'user#listTokens',                      'url' => '/api/user/me/tokens',               'verb' => 'GET'],
		['name' => 'user#createToken',                     'url' => '/api/user/me/tokens',               'verb' => 'POST'],
		['name' => 'user#revokeToken',                     'url' => '/api/user/me/tokens/{id}',          'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'user#requestDeactivation',             'url' => '/api/user/me/deactivate',           'verb' => 'POST'],
		['name' => 'user#getDeactivationStatus',           'url' => '/api/user/me/deactivation-status',  'verb' => 'GET'],
		['name' => 'user#cancelDeactivation',              'url' => '/api/user/me/deactivate',           'verb' => 'DELETE'],

		// Webhooks.
		['name' => 'webhooks#index', 'url' => '/api/webhooks', 'verb' => 'GET'],
		['name' => 'webhooks#show', 'url' => '/api/webhooks/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#create', 'url' => '/api/webhooks', 'verb' => 'POST'],
		['name' => 'webhooks#update', 'url' => '/api/webhooks/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#destroy', 'url' => '/api/webhooks/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#test', 'url' => '/api/webhooks/{id}/test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#events', 'url' => '/api/webhooks/events', 'verb' => 'GET'],
		['name' => 'webhooks#logs', 'url' => '/api/webhooks/{id}/logs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#logStats', 'url' => '/api/webhooks/{id}/logs/stats', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#allLogs', 'url' => '/api/webhooks/logs', 'verb' => 'GET'],
		['name' => 'webhooks#retry', 'url' => '/api/webhooks/logs/{logId}/retry', 'verb' => 'POST', 'requirements' => ['logId' => '\d+']],

		// Workflow Engines - CRUD and health check.
		['name' => 'workflowEngine#available', 'url' => '/api/engines/available', 'verb' => 'GET'],
		['name' => 'workflowEngine#index', 'url' => '/api/engines', 'verb' => 'GET'],
		['name' => 'workflowEngine#create', 'url' => '/api/engines', 'verb' => 'POST'],
		['name' => 'workflowEngine#show', 'url' => '/api/engines/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#update', 'url' => '/api/engines/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#destroy', 'url' => '/api/engines/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#health', 'url' => '/api/engines/{id}/health', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#testHook', 'url' => '/api/engines/{id}/test-hook', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// Workflow Execution History - read/admin-delete persisted hook executions.
		['name' => 'workflowExecution#index', 'url' => '/api/workflow-executions', 'verb' => 'GET'],
		['name' => 'workflowExecution#show', 'url' => '/api/workflow-executions/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowExecution#destroy', 'url' => '/api/workflow-executions/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Scheduled Workflows - CRUD for TimedJob-driven workflow triggers.
		['name' => 'scheduledWorkflow#index', 'url' => '/api/scheduled-workflows', 'verb' => 'GET'],
		['name' => 'scheduledWorkflow#show', 'url' => '/api/scheduled-workflows/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledWorkflow#create', 'url' => '/api/scheduled-workflows', 'verb' => 'POST'],
		['name' => 'scheduledWorkflow#update', 'url' => '/api/scheduled-workflows/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledWorkflow#destroy', 'url' => '/api/scheduled-workflows/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Approval Chains - multi-step approval definitions and per-object progress.
		['name' => 'approval#index', 'url' => '/api/approval-chains', 'verb' => 'GET'],
		['name' => 'approval#show', 'url' => '/api/approval-chains/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#create', 'url' => '/api/approval-chains', 'verb' => 'POST'],
		['name' => 'approval#update', 'url' => '/api/approval-chains/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#destroy', 'url' => '/api/approval-chains/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#objects', 'url' => '/api/approval-chains/{id}/objects', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#steps', 'url' => '/api/approval-steps', 'verb' => 'GET'],
		['name' => 'approval#approve', 'url' => '/api/approval-steps/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#reject', 'url' => '/api/approval-steps/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// MCP Discovery - Tiered API discovery for AI agents.
		// CORS preflight (OPTIONS) is handled automatically by the @CORS annotation.
		['name' => 'mcp#discover', 'url' => '/api/mcp/v1/discover', 'verb' => 'GET'],
		['name' => 'mcp#discoverCapability', 'url' => '/api/mcp/v1/discover/{capability}', 'verb' => 'GET', 'requirements' => ['capability' => '[a-z-]+']],

		// MCP Standard Protocol — JSON-RPC 2.0 Streamable HTTP endpoint.
		['name' => 'mcpServer#handle', 'url' => '/api/mcp', 'verb' => 'POST'],

		// GraphQL API.
		['name' => 'graphQL#execute', 'url' => '/api/graphql', 'verb' => 'POST'],
		['name' => 'graphQL#explorer', 'url' => '/api/graphql/explorer', 'verb' => 'GET'],

		// GraphQL Subscriptions (SSE).
		['name' => 'graphQLSubscription#subscribe', 'url' => '/api/graphql/subscribe', 'verb' => 'GET'],

		// Retention management: archival settings.
		['name' => 'Settings\ConfigurationSettings#getArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'GET'],
		['name' => 'Settings\ConfigurationSettings#updateArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'PUT'],
		['name' => 'Settings\ConfigurationSettings#updateArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'PATCH'],

		// Retention management: destruction list approval workflow.
		['name' => 'retention#approveDestructionList', 'url' => '/api/retention/destruction-lists/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'retention#rejectDestructionList', 'url' => '/api/retention/destruction-lists/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

		// Retention management: legal holds.
		['name' => 'retention#placeLegalHold', 'url' => '/api/retention/legal-holds', 'verb' => 'POST'],
		['name' => 'retention#releaseLegalHold', 'url' => '/api/retention/legal-holds/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'retention#placeBulkLegalHold', 'url' => '/api/retention/legal-holds/bulk', 'verb' => 'POST'],

		// Archival destruction workflow endpoints (spec-compliant /api/archival/ prefix).
		['name' => 'archival#listDestructionLists', 'url' => '/api/archival/destruction-lists', 'verb' => 'GET'],
		['name' => 'archival#getDestructionList', 'url' => '/api/archival/destruction-lists/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#approveDestructionList', 'url' => '/api/archival/destruction-lists/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#rejectDestructionList', 'url' => '/api/archival/destruction-lists/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#createLegalHold', 'url' => '/api/archival/legal-holds', 'verb' => 'POST'],
		['name' => 'archival#releaseLegalHold', 'url' => '/api/archival/legal-holds/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#listLegalHolds', 'url' => '/api/archival/legal-holds', 'verb' => 'GET'],
		['name' => 'archival#listCertificates', 'url' => '/api/archival/certificates', 'verb' => 'GET'],

		// e-Depot transfer settings.
		['name' => 'Settings\EdepotSettings#getEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'GET'],
		['name' => 'Settings\EdepotSettings#updateEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'PUT'],
		['name' => 'Settings\EdepotSettings#updateEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'PATCH'],
		['name' => 'Settings\EdepotSettings#testEdepotConnection', 'url' => '/api/settings/edepot/test', 'verb' => 'POST'],

		// e-Depot transfer management.
		['name' => 'transfer#index', 'url' => '/api/transfers', 'verb' => 'GET'],
		['name' => 'transfer#show', 'url' => '/api/transfers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'transfer#create', 'url' => '/api/transfers', 'verb' => 'POST'],
    ],
];

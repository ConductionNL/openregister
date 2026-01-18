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
		['name' => 'Settings\SolrManagement#getObjectCollectionFields', 'url' => '/api/solr/collections/objects/fields', 'verb' => 'GET'],
		['name' => 'Settings\SolrManagement#getFileCollectionFields', 'url' => '/api/solr/collections/files/fields', 'verb' => 'GET'],
		['name' => 'Settings\SolrManagement#createMissingObjectFields', 'url' => '/api/solr/collections/objects/fields/create-missing', 'verb' => 'POST'],
		['name' => 'Settings\SolrManagement#createMissingFileFields', 'url' => '/api/solr/collections/files/fields/create-missing', 'verb' => 'POST'],
        
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
        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'POST'],
        ['name' => 'Settings\ConfigurationSettings#patchObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PUT'],
        
        // Object vectorization endpoints.
        ['name' => 'objects#vectorizeBatch', 'url' => '/api/objects/vectorize/batch', 'verb' => 'POST'],
        ['name' => 'objects#getObjectVectorizationCount', 'url' => '/api/objects/vectorize/count', 'verb' => 'GET'],
        ['name' => 'objects#getObjectVectorizationStats', 'url' => '/api/objects/vectorize/stats', 'verb' => 'GET'],
        
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
        ['name' => 'Settings\ValidationSettings#validateAllObjects', 'url' => '/api/settings/validate-all-objects', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#massValidateObjects', 'url' => '/api/settings/mass-validate', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#predictMassValidationMemory', 'url' => '/api/settings/mass-validate/memory-prediction', 'verb' => 'POST'],
        // Heartbeat - Keep-alive endpoint for long-running operations.
        ['name' => 'heartbeat#heartbeat', 'url' => '/api/heartbeat', 'verb' => 'GET'],
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
        // Objects.
        ['name' => 'objects#objects', 'url' => '/api/objects', 'verb' => 'GET'],
        // ['name' => 'objects#import', 'url' => '/api/objects/{register}/import', 'verb' => 'POST'], // DISABLED: Use registers import endpoint instead
        ['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET'],
        
        ['name' => 'objects#create', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'POST'],
        ['name' => 'objects#export', 'url' => '/api/objects/{register}/{schema}/export', 'verb' => 'GET'],
        ['name' => 'objects#show', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#update', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#patch', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#destroy', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#merge', 'url' => '/api/objects/{register}/{schema}/{id}/merge', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#migrate', 'url' => '/api/migrate', 'verb' => 'POST'],
        // Relations.        
        ['name' => 'objects#contracts', 'url' => '/api/objects/{register}/{schema}/{id}/contracts', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#uses', 'url' => '/api/objects/{register}/{schema}/{id}/uses', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#used', 'url' => '/api/objects/{register}/{schema}/{id}/used', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Locks.
        ['name' => 'objects#lock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/unlock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#publish', 'url' => '/api/objects/{register}/{schema}/{id}/publish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#depublish', 'url' => '/api/objects/{register}/{schema}/{id}/depublish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Bulk Operations.
        ['name' => 'bulk#save', 'url' => '/api/bulk/{register}/{schema}/save', 'verb' => 'POST'],
        ['name' => 'bulk#delete', 'url' => '/api/bulk/{register}/{schema}/delete', 'verb' => 'POST'],
        ['name' => 'bulk#publish', 'url' => '/api/bulk/{register}/{schema}/publish', 'verb' => 'POST'],
        ['name' => 'bulk#depublish', 'url' => '/api/bulk/{register}/{schema}/depublish', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchema', 'url' => '/api/bulk/{register}/{schema}/delete-schema', 'verb' => 'POST'],
        ['name' => 'bulk#publishSchema', 'url' => '/api/bulk/{register}/{schema}/publish-schema', 'verb' => 'POST'],
        ['name' => 'bulk#deleteRegister', 'url' => '/api/bulk/{register}/delete-register', 'verb' => 'POST'],
        ['name' => 'bulk#validateSchema', 'url' => '/api/bulk/schema/{schema}/validate', 'verb' => 'POST'],
        // Audit Trails.
        ['name' => 'auditTrail#objects', 'url' => '/api/objects/{register}/{schema}/{id}/audit-trails', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#index', 'url' => '/api/audit-trails', 'verb' => 'GET'],
        ['name' => 'auditTrail#export', 'url' => '/api/audit-trails/export', 'verb' => 'GET'],
        ['name' => 'auditTrail#clearAll', 'url' => '/api/audit-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'auditTrail#show', 'url' => '/api/audit-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroy', 'url' => '/api/audit-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroyMultiple', 'url' => '/api/audit-trails', 'verb' => 'DELETE'],
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
        
        // Direct file access by ID (authenticated).
        ['name' => 'files#downloadById', 'url' => '/api/files/{fileId}/download', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],
        
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
        ['name' => 'applications#stats', 'url' => '/api/applications/stats', 'verb' => 'GET'],
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
		// Tags.
		['name' => 'tags#getAllTags', 'url' => '/api/tags', 'verb' => 'GET'],
		
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
	['name' => 'files#page', 'url' => '/files', 'verb' => 'GET'],

		// User - Profile management and authentication.
		['name' => 'user#me', 'url' => '/api/user/me', 'verb' => 'GET'],
		['name' => 'user#updateMe', 'url' => '/api/user/me', 'verb' => 'PUT'],
		['name' => 'user#login', 'url' => '/api/user/login', 'verb' => 'POST'],
		['name' => 'user#logout', 'url' => '/api/user/logout', 'verb' => 'POST'],

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
    ],
];

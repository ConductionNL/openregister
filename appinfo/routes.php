<?php

return [
    'resources' => [
        'Registers' => ['url' => 'api/registers'],
        'Schemas' => ['url' => 'api/schemas'],
        'Sources' => ['url' => 'api/sources'],
        'Configurations' => ['url' => 'api/configurations'],
        'Applications' => ['url' => 'api/applications'],
        'Agents' => ['url' => 'api/agents'],
    ],
    'routes' => [
        // PATCH routes for resources (partial updates)
        ['name' => 'registers#patch', 'url' => '/api/registers/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#patch', 'url' => '/api/schemas/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#patch', 'url' => '/api/sources/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurations#patch', 'url' => '/api/configurations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'applications#patch', 'url' => '/api/applications/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'agents#patch', 'url' => '/api/agents/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        

        // Settings - Legacy endpoints (kept for compatibility)
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#rebase', 'url' => '/api/settings/rebase', 'verb' => 'POST'],
        ['name' => 'settings#stats', 'url' => '/api/settings/stats', 'verb' => 'GET'],
        
        // Settings - Focused endpoints for better performance
        ['name' => 'settings#getSolrSettings', 'url' => '/api/settings/solr', 'verb' => 'GET'],
        ['name' => 'settings#updateSolrSettings', 'url' => '/api/settings/solr', 'verb' => 'PATCH'],
        ['name' => 'settings#updateSolrSettings', 'url' => '/api/settings/solr', 'verb' => 'PUT'],
        ['name' => 'settings#testSolrConnection', 'url' => '/api/settings/solr/test', 'verb' => 'POST'],
        ['name' => 'settings#warmupSolrIndex', 'url' => '/api/settings/solr/warmup', 'verb' => 'POST'],
        ['name' => 'settings#getSolrMemoryPrediction', 'url' => '/api/settings/solr/memory-prediction', 'verb' => 'POST'],
        ['name' => 'settings#testSchemaMapping', 'url' => '/api/settings/solr/test-schema-mapping', 'verb' => 'POST'],
        ['name' => 'settings#getSolrFacetConfiguration', 'url' => '/api/settings/solr-facet-config', 'verb' => 'GET'],
        ['name' => 'settings#updateSolrFacetConfiguration', 'url' => '/api/settings/solr-facet-config', 'verb' => 'POST'],
        ['name' => 'settings#discoverSolrFacets', 'url' => '/api/solr/discover-facets', 'verb' => 'GET'],
        ['name' => 'settings#getSolrFacetConfigWithDiscovery', 'url' => '/api/solr/facet-config', 'verb' => 'GET'],
        ['name' => 'settings#updateSolrFacetConfigWithDiscovery', 'url' => '/api/solr/facet-config', 'verb' => 'POST'],
		['name' => 'settings#getSolrFields', 'url' => '/api/solr/fields', 'verb' => 'GET'],
		['name' => 'settings#createMissingSolrFields', 'url' => '/api/solr/fields/create-missing', 'verb' => 'POST'],
		['name' => 'settings#fixMismatchedSolrFields', 'url' => '/api/solr/fields/fix-mismatches', 'verb' => 'POST'],
	    ['name' => 'settings#deleteSolrField', 'url' => '/api/solr/fields/{fieldName}', 'verb' => 'DELETE', 'requirements' => ['fieldName' => '[^/]+']],
		
		// Collection-specific field management
		['name' => 'settings#getObjectCollectionFields', 'url' => '/api/solr/collections/objects/fields', 'verb' => 'GET'],
		['name' => 'settings#getFileCollectionFields', 'url' => '/api/solr/collections/files/fields', 'verb' => 'GET'],
		['name' => 'settings#createMissingObjectFields', 'url' => '/api/solr/collections/objects/fields/create-missing', 'verb' => 'POST'],
		['name' => 'settings#createMissingFileFields', 'url' => '/api/solr/collections/files/fields/create-missing', 'verb' => 'POST'],
        
        // SOLR Dashboard Management endpoints
        ['name' => 'settings#getSolrDashboardStats', 'url' => '/api/solr/dashboard/stats', 'verb' => 'GET'],
        ['name' => 'settings#inspectSolrIndex', 'url' => '/api/settings/solr/inspect', 'verb' => 'POST'],
        ['name' => 'settings#manageSolr', 'url' => '/api/solr/manage/{operation}', 'verb' => 'POST'],
        ['name' => 'settings#setupSolr', 'url' => '/api/solr/setup', 'verb' => 'POST'],
        ['name' => 'settings#testSolrSetup', 'url' => '/api/solr/test-setup', 'verb' => 'POST'],
    
        // Collection-specific operations (with collection name parameter)
        ['name' => 'settings#deleteSpecificSolrCollection', 'url' => '/api/solr/collections/{name}', 'verb' => 'DELETE', 'requirements' => ['name' => '[^/]+']],
        ['name' => 'settings#clearSpecificCollection', 'url' => '/api/solr/collections/{name}/clear', 'verb' => 'POST', 'requirements' => ['name' => '[^/]+']],
        ['name' => 'settings#reindexSpecificCollection', 'url' => '/api/solr/collections/{name}/reindex', 'verb' => 'POST', 'requirements' => ['name' => '[^/]+']],
        
        // SOLR Collection and ConfigSet Management endpoints (SolrController)
        ['name' => 'solr#listCollections', 'url' => '/api/solr/collections', 'verb' => 'GET'],
        ['name' => 'solr#createCollection', 'url' => '/api/solr/collections', 'verb' => 'POST'],
        ['name' => 'solr#listConfigSets', 'url' => '/api/solr/configsets', 'verb' => 'GET'],
        ['name' => 'solr#createConfigSet', 'url' => '/api/solr/configsets', 'verb' => 'POST'],
        ['name' => 'solr#deleteConfigSet', 'url' => '/api/solr/configsets/{name}', 'verb' => 'DELETE'],
        ['name' => 'solr#copyCollection', 'url' => '/api/solr/collections/copy', 'verb' => 'POST'],
        ['name' => 'settings#updateSolrCollectionAssignments', 'url' => '/api/solr/collections/assignments', 'verb' => 'PUT'],
        
        // Vector Search endpoints (Semantic and Hybrid Search) - SolrController
        ['name' => 'solr#semanticSearch', 'url' => '/api/search/semantic', 'verb' => 'POST'],
        ['name' => 'solr#hybridSearch', 'url' => '/api/search/hybrid', 'verb' => 'POST'],
        ['name' => 'solr#getVectorStats', 'url' => '/api/vectors/stats', 'verb' => 'GET'],
        ['name' => 'solr#testVectorEmbedding', 'url' => '/api/vectors/test', 'verb' => 'POST'],
        
        // Object Vectorization endpoints - SolrController
        ['name' => 'solr#vectorizeObject', 'url' => '/api/objects/{objectId}/vectorize', 'verb' => 'POST'],
        ['name' => 'solr#bulkVectorizeObjects', 'url' => '/api/objects/vectorize/bulk', 'verb' => 'POST'],
        ['name' => 'solr#getVectorizationStats', 'url' => '/api/objects/vectorize/stats', 'verb' => 'GET'],
        
        ['name' => 'settings#getRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'GET'],
        ['name' => 'settings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PATCH'],
        ['name' => 'settings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PUT'],
        
        ['name' => 'settings#getMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'GET'],
        ['name' => 'settings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PATCH'],
        ['name' => 'settings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PUT'],
        
        ['name' => 'settings#getLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'GET'],
        ['name' => 'settings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'POST'],
        ['name' => 'settings#patchLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PATCH'],
        ['name' => 'settings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PUT'],
        ['name' => 'settings#testEmbedding', 'url' => '/api/vectors/test-embedding', 'verb' => 'POST'],
        ['name' => 'settings#testChat', 'url' => '/api/llm/test-chat', 'verb' => 'POST'],
        ['name' => 'settings#getFileSettings', 'url' => '/api/settings/files', 'verb' => 'GET'],
        ['name' => 'settings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PATCH'],
        ['name' => 'settings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PUT'],
        ['name' => 'settings#getFileExtractionStats', 'url' => '/api/settings/files/stats', 'verb' => 'GET'],
        ['name' => 'settings#testDolphinConnection', 'url' => '/api/settings/files/test-dolphin', 'verb' => 'POST'],
        ['name' => 'settings#updateObjectSettings', 'url' => '/api/settings/objects', 'verb' => 'POST'],
        ['name' => 'settings#updateObjectSettings', 'url' => '/api/settings/objects', 'verb' => 'PATCH'],
        ['name' => 'settings#updateObjectSettings', 'url' => '/api/settings/objects', 'verb' => 'PUT'],
        
        // Core file extraction endpoints (use fileExtraction controller to avoid conflict with files controller)
        ['name' => 'fileExtraction#index', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'fileExtraction#show', 'url' => '/api/files/{id}', 'verb' => 'GET'],
        ['name' => 'fileExtraction#discover', 'url' => '/api/files/discover', 'verb' => 'POST'],
        ['name' => 'fileExtraction#extract', 'url' => '/api/files/{id}/extract', 'verb' => 'POST'],
        ['name' => 'fileExtraction#extractAll', 'url' => '/api/files/extract', 'verb' => 'POST'],
        ['name' => 'fileExtraction#retryFailed', 'url' => '/api/files/retry-failed', 'verb' => 'POST'],
        ['name' => 'fileExtraction#stats', 'url' => '/api/files/stats', 'verb' => 'GET'],
        
        ['name' => 'settings#getRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'GET'],
        
        // Debug endpoints for type filtering issue
        ['name' => 'settings#debugTypeFiltering', 'url' => '/api/debug/type-filtering', 'verb' => 'GET'],
        ['name' => 'settings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PATCH'],
        ['name' => 'settings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PUT'],
        
        ['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],
        
        // Statistics endpoint  
        ['name' => 'settings#getStatistics', 'url' => '/api/settings/statistics', 'verb' => 'GET'],
        
        // Cache management
        ['name' => 'settings#getCacheStats', 'url' => '/api/settings/cache', 'verb' => 'GET'],
        ['name' => 'settings#clearCache', 'url' => '/api/settings/cache', 'verb' => 'DELETE'],
        ['name' => 'settings#warmupNamesCache', 'url' => '/api/settings/cache/warmup-names', 'verb' => 'POST'],
        ['name' => 'settings#validateAllObjects', 'url' => '/api/settings/validate-all-objects', 'verb' => 'POST'],
        ['name' => 'settings#massValidateObjects', 'url' => '/api/settings/mass-validate', 'verb' => 'POST'],
        ['name' => 'settings#predictMassValidationMemory', 'url' => '/api/settings/mass-validate/memory-prediction', 'verb' => 'POST'],
        // Heartbeat - Keep-alive endpoint for long-running operations
        ['name' => 'heartbeat#heartbeat', 'url' => '/api/heartbeat', 'verb' => 'GET'],
        // Names - Ultra-fast object name lookup endpoints (specific routes first)
        ['name' => 'names#stats', 'url' => '/api/names/stats', 'verb' => 'GET'],
        ['name' => 'names#warmup', 'url' => '/api/names/warmup', 'verb' => 'POST'],
        ['name' => 'names#index', 'url' => '/api/names', 'verb' => 'GET'],
        ['name' => 'names#create', 'url' => '/api/names', 'verb' => 'POST'],
        ['name' => 'names#show', 'url' => '/api/names/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Dashbaord
        ['name' => 'dashboard#index', 'url' => '/api/dashboard', 'verb' => 'GET'],
        ['name' => 'dashboard#calculate', 'url' => '/api/dashboard/calculate/{registerId}', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
        // Dashboard Charts
        ['name' => 'dashboard#getAuditTrailActionChart', 'url' => '/api/dashboard/charts/audit-trail-actions', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsByRegisterChart', 'url' => '/api/dashboard/charts/objects-by-register', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySchemaChart', 'url' => '/api/dashboard/charts/objects-by-schema', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySizeChart', 'url' => '/api/dashboard/charts/objects-by-size', 'verb' => 'GET'],
        // Dashboard Statistics
        ['name' => 'dashboard#getAuditTrailStatistics', 'url' => '/api/dashboard/statistics/audit-trail', 'verb' => 'GET'],
        ['name' => 'dashboard#getAuditTrailActionDistribution', 'url' => '/api/dashboard/statistics/audit-trail-distribution', 'verb' => 'GET'],
        ['name' => 'dashboard#getMostActiveObjects', 'url' => '/api/dashboard/statistics/most-active-objects', 'verb' => 'GET'],
        // Objects
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
        // Relations        
        ['name' => 'objects#contracts', 'url' => '/api/objects/{register}/{schema}/{id}/contracts', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#uses', 'url' => '/api/objects/{register}/{schema}/{id}/uses', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#used', 'url' => '/api/objects/{register}/{schema}/{id}/used', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Locks
        ['name' => 'objects#lock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/unlock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#publish', 'url' => '/api/objects/{register}/{schema}/{id}/publish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#depublish', 'url' => '/api/objects/{register}/{schema}/{id}/depublish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Bulk Operations
        ['name' => 'bulk#save', 'url' => '/api/bulk/{register}/{schema}/save', 'verb' => 'POST'],
        ['name' => 'bulk#delete', 'url' => '/api/bulk/{register}/{schema}/delete', 'verb' => 'POST'],
        ['name' => 'bulk#publish', 'url' => '/api/bulk/{register}/{schema}/publish', 'verb' => 'POST'],
        ['name' => 'bulk#depublish', 'url' => '/api/bulk/{register}/{schema}/depublish', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchema', 'url' => '/api/bulk/{register}/{schema}/delete-schema', 'verb' => 'POST'],
        ['name' => 'bulk#publishSchema', 'url' => '/api/bulk/{register}/{schema}/publish-schema', 'verb' => 'POST'],
        ['name' => 'bulk#deleteRegister', 'url' => '/api/bulk/{register}/delete-register', 'verb' => 'POST'],
        ['name' => 'bulk#validateSchema', 'url' => '/api/bulk/schema/{schema}/validate', 'verb' => 'POST'],
        // Audit Trails
        ['name' => 'auditTrail#objects', 'url' => '/api/objects/{register}/{schema}/{id}/audit-trails', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#index', 'url' => '/api/audit-trails', 'verb' => 'GET'],
        ['name' => 'auditTrail#export', 'url' => '/api/audit-trails/export', 'verb' => 'GET'],
        ['name' => 'auditTrail#clearAll', 'url' => '/api/audit-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'auditTrail#show', 'url' => '/api/audit-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroy', 'url' => '/api/audit-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroyMultiple', 'url' => '/api/audit-trails', 'verb' => 'DELETE'],
        // Search Trails - specific routes first, then general ones
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
        // Deleted Objects
        ['name' => 'deleted#index', 'url' => '/api/deleted', 'verb' => 'GET'],
        ['name' => 'deleted#statistics', 'url' => '/api/deleted/statistics', 'verb' => 'GET'],
        ['name' => 'deleted#topDeleters', 'url' => '/api/deleted/top-deleters', 'verb' => 'GET'],
        ['name' => 'deleted#restore', 'url' => '/api/deleted/{id}/restore', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#restoreMultiple', 'url' => '/api/deleted/restore', 'verb' => 'POST'],
        ['name' => 'deleted#destroy', 'url' => '/api/deleted/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#destroyMultiple', 'url' => '/api/deleted', 'verb' => 'DELETE'],
        // Revert
        ['name' => 'revert#revert', 'url' => '/api/objects/{register}/{schema}/{id}/revert', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        
        // Files operations under objects
		['name' => 'files#create', 'url' => 'api/objects/{register}/{schema}/{id}/files', 'verb' => 'POST'],
		['name' => 'files#save', 'url' => 'api/objects/{register}/{schema}/{id}/files/save', 'verb' => 'POST'],
		['name' => 'files#index', 'url' => 'api/objects/{register}/{schema}/{id}/files', 'verb' => 'GET'],
        ['name' => 'files#show', 'url' => 'api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],
        ['name' => 'objects#downloadFiles', 'url' => '/api/objects/{register}/{schema}/{id}/files/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#createMultipart', 'url' => 'api/objects/{register}/{schema}/{id}/filesMultipart', 'verb' => 'POST'],	
		['name' => 'files#update', 'url' => 'api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'PUT', 'requirements' => ['fileId' => '\d+']],
		['name' => 'files#delete', 'url' => 'api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'DELETE', 'requirements' => ['fileId' => '\d+']],
		['name' => 'files#publish', 'url' => 'api/objects/{register}/{schema}/{id}/files/{fileId}/publish', 'verb' => 'POST', 'requirements' => ['fileId' => '\d+']],
		['name' => 'files#depublish', 'url' => 'api/objects/{register}/{schema}/{id}/files/{fileId}/depublish', 'verb' => 'POST', 'requirements' => ['fileId' => '\d+']],
        
        // Direct file access by ID (authenticated)
        ['name' => 'files#downloadById', 'url' => '/api/files/{fileId}/download', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],
        
        // Schemas
        ['name' => 'schemas#upload', 'url' => '/api/schemas/upload', 'verb' => 'POST'],
        ['name' => 'schemas#uploadUpdate', 'url' => '/api/schemas/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#download', 'url' => '/api/schemas/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#related', 'url' => '/api/schemas/{id}/related', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#stats', 'url' => '/api/schemas/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#explore', 'url' => '/api/schemas/{id}/explore', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#updateFromExploration', 'url' => '/api/schemas/{id}/update-from-exploration', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Registers
        ['name' => 'registers#export', 'url' => '/api/registers/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#import', 'url' => '/api/registers/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#schemas', 'url' => '/api/registers/{id}/schemas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#stats', 'url' => '/api/registers/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generate', 'url' => '/api/registers/{id}/oas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generateAll', 'url' => '/api/registers/oas', 'verb' => 'GET'],
        // Configurations - Management
        ['name' => 'configuration#checkVersion', 'url' => '/api/configurations/{id}/check-version', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#preview', 'url' => '/api/configurations/{id}/preview', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#import', 'url' => '/api/configurations/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#export', 'url' => '/api/configurations/{id}/export', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        
        // User Settings - GitHub Integration
        ['name' => 'userSettings#getGitHubTokenStatus', 'url' => '/api/user-settings/github/status', 'verb' => 'GET'],
        ['name' => 'userSettings#setGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'POST'],
        ['name' => 'userSettings#removeGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'DELETE'],
        // Applications
        ['name' => 'applications#page', 'url' => '/applications', 'verb' => 'GET'],
        ['name' => 'applications#stats', 'url' => '/api/applications/stats', 'verb' => 'GET'],
        // Agents
        ['name' => 'agents#page', 'url' => '/agents', 'verb' => 'GET'],
        ['name' => 'agents#stats', 'url' => '/api/agents/stats', 'verb' => 'GET'],
        // Search
        ['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
        // Organisations - Multi-tenancy management
        ['name' => 'organisation#index', 'url' => '/api/organisations', 'verb' => 'GET'],
        ['name' => 'organisation#create', 'url' => '/api/organisations', 'verb' => 'POST'],
        ['name' => 'organisation#search', 'url' => '/api/organisations/search', 'verb' => 'GET'],
        ['name' => 'organisation#stats', 'url' => '/api/organisations/stats', 'verb' => 'GET'],
        ['name' => 'organisation#clearCache', 'url' => '/api/organisations/clear-cache', 'verb' => 'POST'],
        ['name' => 'organisation#getActive', 'url' => '/api/organisations/active', 'verb' => 'GET'],
        ['name' => 'organisation#show', 'url' => '/api/organisations/{uuid}', 'verb' => 'GET'],
        ['name' => 'organisation#update', 'url' => '/api/organisations/{uuid}', 'verb' => 'PUT'],
        ['name' => 'organisation#patch', 'url' => '/api/organisations/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'organisation#setActive', 'url' => '/api/organisations/{uuid}/set-active', 'verb' => 'POST'],
        ['name' => 'organisation#join', 'url' => '/api/organisations/{uuid}/join', 'verb' => 'POST'],
        ['name' => 'organisation#leave', 'url' => '/api/organisations/{uuid}/leave', 'verb' => 'POST'],
		// Tags
		['name' => 'tags#getAllTags', 'url' => 'api/tags', 'verb' => 'GET'],
		
		// Views - Saved search configurations
		['name' => 'views#index', 'url' => '/api/views', 'verb' => 'GET'],
		['name' => 'views#show', 'url' => '/api/views/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#create', 'url' => '/api/views', 'verb' => 'POST'],
		['name' => 'views#update', 'url' => '/api/views/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#patch', 'url' => '/api/views/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#destroy', 'url' => '/api/views/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		
		// Chat - AI Assistant endpoints
		['name' => 'chat#sendMessage', 'url' => '/api/chat/send', 'verb' => 'POST'],
		['name' => 'chat#getHistory', 'url' => '/api/chat/history', 'verb' => 'GET'],
		['name' => 'chat#clearHistory', 'url' => '/api/chat/history', 'verb' => 'DELETE'],
		['name' => 'chat#sendFeedback', 'url' => '/api/chat/feedback', 'verb' => 'POST'],
		
		// Conversations - AI Conversation management
		['name' => 'conversation#index', 'url' => '/api/conversations', 'verb' => 'GET'],
		['name' => 'conversation#show', 'url' => '/api/conversations/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#create', 'url' => '/api/conversations', 'verb' => 'POST'],
		['name' => 'conversation#update', 'url' => '/api/conversations/{uuid}', 'verb' => 'PATCH', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroy', 'url' => '/api/conversations/{uuid}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#restore', 'url' => '/api/conversations/{uuid}/restore', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroyPermanent', 'url' => '/api/conversations/{uuid}/permanent', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#archive', 'url' => '/api/conversations/archive', 'verb' => 'GET'],
		
		// File Text Management - Extract and manage text from files
		['name' => 'fileText#getFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'GET', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#extractFileText', 'url' => '/api/files/{fileId}/extract', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#bulkExtract', 'url' => '/api/files/extract/bulk', 'verb' => 'POST'],
		['name' => 'fileText#getStats', 'url' => '/api/files/extraction/stats', 'verb' => 'GET'],
		['name' => 'fileText#deleteFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'DELETE', 'requirements' => ['fileId' => '\\d+']],
		
		// File Chunking & Indexing - Process extracted files and index chunks in SOLR
		['name' => 'fileText#processAndIndexExtracted', 'url' => '/api/files/chunks/process', 'verb' => 'POST'],
		['name' => 'fileText#processAndIndexFile', 'url' => '/api/files/{fileId}/chunks/process', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#getChunkingStats', 'url' => '/api/files/chunks/stats', 'verb' => 'GET'],
		
		// File Warmup & Indexing - Bulk process and index files in SOLR
		['name' => 'settings#warmupFiles', 'url' => '/api/solr/warmup/files', 'verb' => 'POST'],
		['name' => 'settings#indexFile', 'url' => '/api/solr/files/{fileId}/index', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'settings#reindexFiles', 'url' => '/api/solr/files/reindex', 'verb' => 'POST'],
		['name' => 'settings#getFileIndexStats', 'url' => '/api/solr/files/stats', 'verb' => 'GET'],
		
		// File Search - Keyword, semantic, and hybrid search over file contents
		['name' => 'fileSearch#keywordSearch', 'url' => '/api/search/files/keyword', 'verb' => 'POST'],
		['name' => 'fileSearch#semanticSearch', 'url' => '/api/search/files/semantic', 'verb' => 'POST'],
		['name' => 'fileSearch#hybridSearch', 'url' => '/api/search/files/hybrid', 'verb' => 'POST'],

		// Page routes
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
	['name' => 'files#page', 'url' => '/files', 'verb' => 'GET'],
    ],
];

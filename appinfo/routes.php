<?php

return [
    'resources' => [
        'Registers' => ['url' => 'api/registers'],
        'Schemas' => ['url' => 'api/schemas'],
        'Sources' => ['url' => 'api/sources'],
        'Configurations' => ['url' => 'api/configurations'],
    ],
    'routes' => [
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'dashboard#index', 'url' => '/api/dashboard', 'verb' => 'GET'],
        ['name' => 'dashboard#calculate', 'url' => '/api/dashboard/calculate/{registerId}', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
        // Dashboard Charts
        ['name' => 'dashboard#getAuditTrailActionChart', 'url' => '/api/dashboard/charts/audit-trail-actions', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsByRegisterChart', 'url' => '/api/dashboard/charts/objects-by-register', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySchemaChart', 'url' => '/api/dashboard/charts/objects-by-schema', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySizeChart', 'url' => '/api/dashboard/charts/objects-by-size', 'verb' => 'GET'],
        // Objects
        ['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET'],
        ['name' => 'objects#create', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'POST'],
        ['name' => 'objects#show', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#update', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PUT'],
        ['name' => 'objects#destroy', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        // Relations        
        ['name' => 'objects#contracts', 'url' => '/api/objects/{register}/{schema}/{id}/contracts', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#uses', 'url' => '/api/objects/{register}/{schema}/{id}/uses', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#used', 'url' => '/api/objects/{register}/{schema}/{id}/used', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Locks
        ['name' => 'objects#lock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/unlock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Logs
        ['name' => 'log#index', 'url' => '/api/objects/{register}/{schema}/{id}/audit-trails', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Revert
        ['name' => 'revert#revert', 'url' => '/api/objects/{register}/{schema}/{id}/revert', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        
        // Files operations under objects
		['name' => 'files#index', 'url' => 'api/objects/{register}/{schema}/{id}/files', 'verb' => 'GET'],
        ['name' => 'files#show', 'url' => 'api/objects/{register}/{schema}/{id}/files/{filePath}', 'verb' => 'GET'],
		['name' => 'files#create', 'url' => 'api/objects/{register}/{schema}/{id}/files', 'verb' => 'POST'],
		['name' => 'files#createMultipart', 'url' => 'api/objects/{register}/{schema}/{id}/filesMultipart', 'verb' => 'POST'],	
		['name' => 'files#update', 'url' => 'api/objects/{register}/{schema}/{id}/files/{filePath}', 'verb' => 'POST'],
		['name' => 'files#delete', 'url' => 'api/objects/{register}/{schema}/{id}/files/{filePath}', 'verb' => 'DELETE'],
		['name' => 'files#publish', 'url' => 'api/objects/{register}/{schema}/{id}/files/{filePath}/publish', 'verb' => 'POST'],
		['name' => 'files#depublish', 'url' => 'api/objects/{register}/{schema}/{id}/files/{filePath}/depublish', 'verb' => 'POST'],	
        // Schemas
        ['name' => 'schemas#upload', 'url' => '/api/schemas/upload', 'verb' => 'POST'],
        ['name' => 'schemas#uploadUpdate', 'url' => '/api/schemas/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#download', 'url' => '/api/schemas/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Registers
        ['name' => 'registers#import', 'url' => '/api/registers/import', 'verb' => 'POST'],
        ['name' => 'registers#export', 'url' => '/api/registers/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generate', 'url' => '/api/registers/{id}/oas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generateAll', 'url' => '/api/registers/oas', 'verb' => 'GET'],
        // Configurations
        ['name' => 'configurations#export', 'url' => '/api/configurations/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurations#import', 'url' => '/api/configurations/import', 'verb' => 'POST'],
        // Search
        ['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
		// Tags
		['name' => 'tags#getAllTags', 'url' => 'api/tags', 'verb' => 'GET'],
    ],
];

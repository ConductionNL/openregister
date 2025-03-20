<?php

return [
	'resources' => [
		'Registers' => ['url' => 'api/registers'],
		'Schemas' => ['url' => 'api/schemas'],
		'Sources' => ['url' => 'api/sources'],
	],
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
		// Objects
		['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET'],
		['name' => 'objects#create', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'POST'],
		['name' => 'objects#show', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#update', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#destroy', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#auditTrails', 'url' => '/api/objects/{register}/{schema}/{id}/audit-trails', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#relations', 'url' => '/api/objects/{register}/{schema}/{id}/relations', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#uses', 'url' => '/api/objects/{register}/{schema}/{id}/uses', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#files', 'url' => '/api/objects/{register}/{schema}/{id}/files', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#lock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/unlock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#revert', 'url' => '/api/objects/{register}/{schema}/{id}/revert', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		// Schemas
		['name' => 'schemas#upload', 'url' => '/api/schemas/upload', 'verb' => 'POST'],
		['name' => 'schemas#uploadUpdate', 'url' => '/api/schemas/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'schemas#download', 'url' => '/api/schemas/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		// Registers
		['name' => 'registers#upload', 'url' => '/api/registers/upload', 'verb' => 'POST'],
		['name' => 'registers#uploadUpdate', 'url' => '/api/registers/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'registers#download', 'url' => '/api/registers/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
	],
];

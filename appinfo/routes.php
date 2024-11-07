<?php

return [
	'resources' => [
		'Registers' => ['url' => 'api/registers'],
		'Schemas' => ['url' => 'api/schemas'],
		'Sources' => ['url' => 'api/sources'],
		'Objects' => ['url' => 'api/objects'],
	],
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
		['name' => 'registers#objects', 'url' => '/api/registers-objects/{register}/{schema}', 'verb' => 'GET'],
		['name' => 'objects#logs', 'url' => '/api/objects-logs/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'objects#mappings', 'url' => '/api/objects/mappings', 'verb' => 'GET'],
		['name' => 'schemas#upload', 'url' => '/api/schemas/upload', 'verb' => 'POST'],
		['name' => 'schemas#uploadUpdate', 'url' => '/api/schemas/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'schemas#download', 'url' => '/api/schemas/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'registers#upload', 'url' => '/api/registers/upload', 'verb' => 'POST'],
		['name' => 'registers#uploadUpdate', 'url' => '/api/registers/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'registers#download', 'url' => '/api/registers/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
	],
];

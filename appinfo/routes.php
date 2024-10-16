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
		['name' => 'registers#viewObjectsFromRegister', 'url' => '/api/registers/{register}/objects', 'verb' => 'GET'],
		['name' => 'registers#viewObjectFromRegister', 'url' => '/api/registers/{register}/objects/{id}', 'verb' => 'GET'],
		['name' => 'registers#deleteObjectFromRegister', 'url' => '/api/registers/{register}/objects/{id}', 'verb' => 'DELETE'],
	],
];

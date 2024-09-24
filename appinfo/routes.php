<?php

return [
	'resources' => [
		'Registers' => ['url' => 'api/registers'],
		'Schemas' => ['url' => 'api/schemas'],
		'Sources' => ['url' => 'api/sources'],
	],
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
	],
];

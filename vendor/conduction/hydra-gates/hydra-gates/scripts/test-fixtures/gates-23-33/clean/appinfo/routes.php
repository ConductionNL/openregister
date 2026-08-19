<?php
return [
	'routes' => [
		['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],
		['name' => 'AppHost\Controller\GenericHealth#index', 'url' => '/api/engine-health', 'verb' => 'GET'],
		['name' => 'healthPing#show', 'url' => '/api/health-ping/{placementId}', 'verb' => 'GET'],
		['name' => 'healthPing#validate', 'url' => '/api/health-ping/validate', 'verb' => 'POST'],
	],
];

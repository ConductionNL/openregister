<?php
/**
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

return [
	'routes' => [
		['name' => 'agent#show',   'url' => '/api/agents/{id}', 'verb' => 'GET'],
		['name' => 'agent#update', 'url' => '/api/agents/{id}', 'verb' => 'PUT'],
		['name' => 'agent#diff',   'url' => '/api/agents/{id}/diff', 'verb' => 'GET'],
	],
];

<?php
// SPDX-License-Identifier: EUPL-1.2
return [
    'routes' => [
        ['name' => 'healthPing#validate', 'url' => '/api/health-ping/validate', 'verb' => 'POST'],
        ['name' => 'healthPing#show', 'url' => '/api/health-ping/{placementId}', 'verb' => 'GET'],
    ],
];

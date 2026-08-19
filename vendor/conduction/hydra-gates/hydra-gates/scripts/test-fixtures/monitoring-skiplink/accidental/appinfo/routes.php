<?php
// SPDX-License-Identifier: EUPL-1.2
return [
    'routes' => [
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        ['name' => 'health#index',  'url' => '/api/health',  'verb' => 'GET'],
    ],
];

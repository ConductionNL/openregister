<?php
// SPDX-License-Identifier: EUPL-1.2
//
// launchpad's shape. `healthPing#show` is a per-placement status badge and
// `healthPing#validate` submits a candidate config; neither is anything a
// Prometheus scraper or a kubelet can call. `health#index` is.
return [
    'routes' => [
        ['name' => 'healthPing#validate', 'url' => '/api/health-ping/validate', 'verb' => 'POST'],
        ['name' => 'healthPing#show', 'url' => '/api/health-ping/{placementId}', 'verb' => 'GET'],
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],
    ],
];

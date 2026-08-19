<?php
// SPDX-License-Identifier: EUPL-1.2
//
// CAPITALISED monitoring words, and one of them NAMESPACED. `grep -E
// '(metrics|health|…)'` matches no capital, so before #213 these names
// selected NOTHING and gate-30 printed PASS over a 0-byte log — the shape
// openregister shipped for months while owning the fleet's health/metrics
// engine. `read` without -r then ate the backslashes out of the third one.
return [
    'routes' => [
        ['name' => 'genericHealth#index', 'url' => '/api/health', 'verb' => 'GET'],
        ['name' => 'genericMetrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        ['name' => 'AppHost\Controller\GenericLiveness#index', 'url' => '/api/liveness', 'verb' => 'GET'],
    ],
];

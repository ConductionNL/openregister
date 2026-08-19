<?php
// SPDX-License-Identifier: EUPL-1.2
// Fixture: every routed method carries an auth attribute. Gate-5 MUST pass.
return [
    'routes' => [
        ['name' => 'widget#show',   'url' => '/api/widgets/{id}', 'verb' => 'GET'],
        ['name' => 'widget#update', 'url' => '/api/widgets/{id}', 'verb' => 'PUT'],
        // camelCase slug, guarded — the negative half of the pair.
        ['name' => 'paymentTransaction#callback', 'url' => '/api/payments/callback', 'verb' => 'POST'],
    ],
];

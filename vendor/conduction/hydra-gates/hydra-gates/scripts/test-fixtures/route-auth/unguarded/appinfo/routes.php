<?php
// SPDX-License-Identifier: EUPL-1.2
// Fixture: a genuinely unguarded route. Gate-5 MUST fail on this.
return [
    'routes' => [
        ['name' => 'widget#show',   'url' => '/api/widgets/{id}', 'verb' => 'GET'],
        ['name' => 'widget#update', 'url' => '/api/widgets/{id}', 'verb' => 'PUT'],

        // camelCase slug. Gate-5 read route names through `'[a-z_]+#…'` until
        // 2026-08-05, so a slug with a capital in it matched NOTHING and the
        // method behind it was never judged — on scholiq that hid 23 of 37
        // routes, `paymentTransaction#callback` among them. This entry is
        // unguarded, so it must be reported.
        ['name' => 'paymentTransaction#callback', 'url' => '/api/payments/callback', 'verb' => 'POST'],
    ],
];

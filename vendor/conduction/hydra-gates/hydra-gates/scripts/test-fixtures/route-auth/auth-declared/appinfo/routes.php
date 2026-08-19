<?php
// SPDX-License-Identifier: EUPL-1.2
// Fixture for ConductionNL/.github#196 — the closing move.
//
// The same two endpoints as ../prose-exempt, now DECLARING their posture:
// one with a real attribute, one with the `@auth admin-only <reason>` marker
// the tightening ships alongside. Gate-5 must PASS here.
//
// Without this arm the fix would be an unclosable gate: absence of
// #[NoAdminRequired] IS the admin gate, so an admin-only method has no
// attribute available to satisfy the check with.
return [
    'routes' => [
        ['name' => 'productSubscriptions#subscribe', 'url' => '/api/products/{id}/subscribe', 'verb' => 'POST'],
        ['name' => 'productSubscriptions#analytics', 'url' => '/api/products/{id}/analytics', 'verb' => 'GET'],
        ['name' => 'productSubscriptions#legacy',    'url' => '/api/products/{id}/legacy',    'verb' => 'GET'],
    ],
];

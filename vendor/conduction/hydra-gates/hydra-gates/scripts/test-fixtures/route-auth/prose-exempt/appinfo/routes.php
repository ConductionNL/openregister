<?php
// SPDX-License-Identifier: EUPL-1.2
// Fixture for ConductionNL/.github#196 — openconnector's
// ProductSubscriptionsController shape. Two routed methods with the SAME auth
// posture (admin-only: no attribute at all). Before the fix, `subscribe` PASSED
// and `analytics` FAILED, decided entirely by one sentence of prose.
//
// Both must now be reported. This fixture is the false-NEGATIVE arm: the
// acceptance test is that the gate now CATCHES what it used to wave through.
return [
    'routes' => [
        ['name' => 'productSubscriptions#subscribe', 'url' => '/api/products/{id}/subscribe', 'verb' => 'POST'],
        ['name' => 'productSubscriptions#analytics', 'url' => '/api/products/{id}/analytics', 'verb' => 'GET'],
    ],
];

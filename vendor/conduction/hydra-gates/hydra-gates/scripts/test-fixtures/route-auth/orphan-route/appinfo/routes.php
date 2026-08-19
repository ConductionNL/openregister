<?php
// SPDX-License-Identifier: EUPL-1.2
//
// Fixture: NOT an AppHost adopter, and `gadget#run` names a controller class
// that this repository does not ship. That is a route-reachability defect
// (ReflectionException 500 at request time), NOT a missing auth attribute.
// Gate-5 must decline to judge it; gate-14 must raise it.
return [
    'routes' => [
        ['name' => 'widget#show', 'url' => '/api/widgets/{id}', 'verb' => 'GET'],
        ['name' => 'gadget#run',  'url' => '/api/gadgets/run',  'verb' => 'POST'],
        ['name' => 'widget#ghost', 'url' => '/api/widgets/ghost', 'verb' => 'GET'],
    ],
];

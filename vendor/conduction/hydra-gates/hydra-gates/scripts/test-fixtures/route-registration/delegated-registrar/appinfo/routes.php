<?php
// SPDX-License-Identifier: EUPL-1.2
//
// The AppHost canonical names written out as LITERALS (procest's shape: it
// keeps a local fallback so the app still routes when openregister is
// absent). None of the four controllers exists here — they are aliased into
// the container by Bootstrap::register(), which this app calls from a
// registrar, not from Application.php.
return [
    'routes' => [
        ['name' => 'widget#show', 'url' => '/api/widget', 'verb' => 'GET'],
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        // NOT an AppHost generic and bound by nothing: a real 500 at request
        // time, and the anti-widening half of this fixture.
        ['name' => 'gadget#run', 'url' => '/api/gadget', 'verb' => 'POST'],
    ],
];

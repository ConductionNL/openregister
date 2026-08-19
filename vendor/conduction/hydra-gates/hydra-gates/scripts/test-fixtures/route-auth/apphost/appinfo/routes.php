<?php
// SPDX-License-Identifier: EUPL-1.2
//
// Fixture mirroring scholiq: AppHost adoption (ADR-040). The settings,
// preferences, health and metrics controllers are the OpenRegister AppHost
// generics, aliased onto this app's conventional controller class names in
// lib/AppInfo/Application.php via \OCA\OpenRegister\AppHost\Bootstrap::register().
// Those files therefore do NOT exist here, by design.
return [
    'routes' => [
        ['name' => 'widget#show', 'url' => '/api/widgets/{id}', 'verb' => 'GET'],

        ['name' => 'health#index',  'url' => '/api/health',  'verb' => 'GET'],
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],

        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],
    ],
];

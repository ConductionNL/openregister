<?php
// SPDX-License-Identifier: EUPL-1.2
//
// Fixture mirroring opencatalogi origin/development (9e63d9f3), 2026-08-08.
//
// The AppHost generic routes are named with a FULLY-QUALIFIED class name in
// the controller slot, under this app's OWN top namespace. NC's
// \OC\AppFramework\App::main() does `$container->get($controllerName)` FIRST,
// with the literal name RouteParser::buildControllerName() produced (that
// builder only appends 'Controller', so backslashes survive), and for a name
// containing `\Controller\` the QueryException fallback does not rewrite the
// name at all — it throws "App … is not enabled". So the ONLY thing that can
// make these routes work is the exact DI binding in lib/AppInfo/Application.php,
// and it is there. Every one of these four routes resolves at runtime.
//
// `Settings\Widget#show` is here for a second reason: until 2026-08-08 both
// route gates read this file through `read` without `-r`, which strips
// backslashes, so this name reached the resolver as `SettingsWidget` and
// _ctrl_path_from_name's documented `Settings\Foo` branch was unreachable.
// The file below is at the path that branch names.
return [
    'routes' => [
        ['name' => 'OCA\Fixture\AppHost\Controller\GenericDashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'OCA\Fixture\AppHost\Controller\GenericDashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
        ['name' => 'OCA\Fixture\AppHost\Controller\GenericHealth#index', 'url' => '/api/health', 'verb' => 'GET'],

        ['name' => 'Settings\Widget#show', 'url' => '/api/widgets/{id}', 'verb' => 'GET'],
    ],
];

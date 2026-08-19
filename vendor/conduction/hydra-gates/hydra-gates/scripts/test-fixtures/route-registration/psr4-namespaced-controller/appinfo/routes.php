<?php
// SPDX-License-Identifier: EUPL-1.2
//
// A NAMESPACED route name. NC's RouteParser::buildControllerName() does NOT
// prefix the app namespace when the name already contains a backslash, so this
// resolves to the bare class `AppHost\Controller\GenericHealthController`,
// which PSR-4 places at lib/AppHost/Controller/ — NOT under lib/Controller/.
return [
    'routes' => [
        ['name' => 'AppHost\Controller\GenericHealth#index', 'url' => '/api/health', 'verb' => 'GET'],
        ['name' => 'ping#index', 'url' => '/api/ping', 'verb' => 'GET'],
    ],
];

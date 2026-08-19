<?php
// SPDX-License-Identifier: EUPL-1.2
//
// .github#265 FIXTURE — the leaf keeps its OWN SettingsController (which
// `Bootstrap::aliasControllerUnlessLeafDefinesIt()` explicitly allows) but does
// NOT define `update()`.
//
// `Routes::standard()` prepends `['name' => 'settings#update', 'url' =>
// '/api/settings', 'verb' => 'PUT']`, and that name appears NOWHERE in this
// app's appinfo/routes.php. Gate-14 invariant 2 used to read route names as
// literals out of that file only, so it never asked the question — and
// `PUT /api/settings` here is not a 404: the router matches,
// ControllerMethodReflector reflects, and the request dies with a
// ReflectionException 500.
//
// This file is byte-identical to routes-standard/lib/Controller/
// SettingsController.php with `update()` removed. That sibling MUST stay clean;
// this one MUST be reported.
declare(strict_types=1);

namespace OCA\Fixture\Controller;

class SettingsController extends Controller
{
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse([]);
    }

    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function create(): JSONResponse
    {
        return new JSONResponse([]);
    }

    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function load(): JSONResponse
    {
        return new JSONResponse([]);
    }
}

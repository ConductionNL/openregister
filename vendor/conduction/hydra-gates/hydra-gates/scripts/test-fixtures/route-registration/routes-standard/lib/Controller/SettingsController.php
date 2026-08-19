<?php
// SPDX-License-Identifier: EUPL-1.2
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

    // `settings#update` (PUT /api/settings) is one of the ten entries
    // Routes::standard() prepends. A leaf that keeps its OWN SettingsController
    // owes every one of those methods — see the sibling fixture
    // routes-standard-missing-update/, which deletes exactly this method and
    // MUST be reported (.github#265).
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function update(): JSONResponse
    {
        return new JSONResponse([]);
    }

    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function load(): JSONResponse
    {
        return new JSONResponse([]);
    }
}

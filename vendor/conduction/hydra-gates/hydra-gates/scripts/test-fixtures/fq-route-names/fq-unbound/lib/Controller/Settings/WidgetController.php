<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

class WidgetController extends Controller
{
    #[NoAdminRequired]
    public function show(int $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }
}

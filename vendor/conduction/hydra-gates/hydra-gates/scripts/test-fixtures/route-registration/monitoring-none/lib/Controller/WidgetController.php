<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

class WidgetController extends Controller
{
    #[NoAdminRequired]
    public function show(): JSONResponse
    {
        return new JSONResponse([]);
    }
}

<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\AppHost\Controller;

class GenericHealthController extends Controller
{
    #[PublicPage]
    #[NoCSRFRequired]
    public function indexRenamed(): JSONResponse
    {
        return new JSONResponse(['status' => 'ok']);
    }
}

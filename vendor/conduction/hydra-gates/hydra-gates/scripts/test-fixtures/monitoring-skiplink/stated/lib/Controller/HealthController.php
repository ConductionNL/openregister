<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;

class HealthController extends Controller
{
    /**
     * GET /api/health — scraped by kubelet and by external uptime monitors,
     * neither of which has a Nextcloud session.
     *
     * @return JSONResponse `{status, app, version, checks}`.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse($this->engine->checks());
    }
}

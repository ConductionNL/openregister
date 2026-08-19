<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;

/**
 * THE CONTROL for the strict half.
 *
 * Health keeps the hard requirement — the engine agrees with the gate there —
 * so a session-gated health endpoint is a defect however it is written up.
 * This one states its restricted posture in the same words the metrics
 * carve-out accepts, and must STILL be reported: that carve-out is for
 * metrics and for nothing else.
 *
 * Restricted to administrators, deliberately.
 */
class HealthController extends Controller
{
    /**
     * GET /api/health.
     *
     * Admin-only, deliberately — see the class docblock.
     *
     * @return JSONResponse `{status, app, version, checks}`.
     */
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse($this->engine->checks());
    }
}

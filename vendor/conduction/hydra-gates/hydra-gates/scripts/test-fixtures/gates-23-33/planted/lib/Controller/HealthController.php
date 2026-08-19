<?php
/**
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;

class HealthController extends Controller
{

    /**
     * GET /api/health — unparameterised, a real scrape target, and it declares
     * NO posture at all. gate-30 must name this.
     *
     * @return JSONResponse
     */
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse(['status' => 'ok']);
    }

}

<?php
/**
 * DI-bound under `AppHost\Controller\GenericHealth`, but living OUTSIDE the
 * lib/Controller/ PSR-4 path the route name maps to. gate-30 used to declare
 * this file "not present in this repository" and never judge it.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\AppHost\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;

class GenericHealthController extends Controller
{

    /**
     * @return JSONResponse
     */
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse(['status' => 'ok']);
    }

}

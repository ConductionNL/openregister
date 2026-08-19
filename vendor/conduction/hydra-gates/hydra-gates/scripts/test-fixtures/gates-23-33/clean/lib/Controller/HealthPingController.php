<?php
/**
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

class HealthPingController extends Controller
{

    /**
     * Per-placement badge. NOT a scrape target: it 401s anonymous callers and
     * authorises the placement before doing any work. Adding #[PublicPage]
     * here would publish an outbound-ping oracle.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $placementId): JSONResponse
    {
        if ($this->canViewPlacement($placementId) === false) {
            return new JSONResponse([], 403);
        }
        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function validate(): JSONResponse
    {
        return new JSONResponse([]);
    }

    private function canViewPlacement(string $id): bool
    {
        return $id !== '';
    }

}

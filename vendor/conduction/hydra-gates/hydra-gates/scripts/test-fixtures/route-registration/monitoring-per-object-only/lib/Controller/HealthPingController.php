<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

/**
 * THE FALSE POSITIVE WHOSE REMEDY WAS A SECURITY REGRESSION (#218).
 *
 * Both methods 401 an anonymous caller and run a per-object permission
 * check before doing any work. gate-30's advice — add #[PublicPage] — would
 * have published an outbound-ping oracle to anonymous callers.
 */
class HealthPingController extends Controller
{
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $placementId): JSONResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->permissionService->canViewPlacement(userId: $userId, placementId: $placementId) === false) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse($this->healthPingService->resolveForPlacement(placementId: $placementId));
    }

    #[NoAdminRequired]
    public function validate(): JSONResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(['valid' => true]);
    }
}

<?php

/**
 * OpenRegister Anonymisation Backend Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only endpoints exposing anonymisation backend state and probes.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class AnonymisationBackendController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                      $appName      The app name.
     * @param IRequest                    $request      The request.
     * @param IUserSession                $userSession  Current user session for admin gating.
     * @param IGroupManager               $groupManager Group manager for admin checks.
     * @param AnonymisationBackendService $service      Backend state service (single source of truth).
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly AnonymisationBackendService $service,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get the resolved anonymisation backend state.
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @return JSONResponse The serialised BackendState, or 403 for non-admins.
     *
     * @psalm-return JSONResponse<200|403, array, array<never, never>>
     */
    public function getBackendState(): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        return new JSONResponse(data: $this->service->getState()->jsonSerialize());
    }//end getBackendState()

    /**
     * Probe a single backend, bypassing the cache.
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @return JSONResponse The serialised ProbeResult, 400 for an invalid method, or 403 for non-admins.
     *
     * @psalm-return JSONResponse<200|400|403, array, array<never, never>>
     */
    public function testConnection(): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        $method = (string) $this->request->getParam('method', '');
        if (in_array($method, BackendState::METHODS, true) === false) {
            return new JSONResponse(
                data: ['error' => 'Invalid method; expected one of: '.implode(', ', BackendState::METHODS)],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $this->service->testConnection(method: $method)->jsonSerialize());
    }//end testConnection()

    /**
     * Return a 403 response when the current user is not an admin, else null.
     *
     * Unauthenticated requests are rejected with HTTP 401 by the framework
     * before this method runs (the route is not public).
     *
     * @return JSONResponse|null A forbidden response, or null when the user is an admin.
     *
     * @psalm-return JSONResponse<403, array{error: string}, array<never, never>>|null
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                data: ['error' => 'Administrator privileges are required to access anonymisation backend settings'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()
}//end class

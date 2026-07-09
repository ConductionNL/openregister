<?php

/**
 * LinkedEntityController
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-44
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-45
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-48
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-49
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Generic controller for managing linked Nextcloud entities on objects and entities.
 *
 * Replaces per-type controllers (EmailsController, etc.) with a unified API.
 *
 * @category Controller
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-6
 */
class LinkedEntityController extends Controller
{
    /**
     * Constructor for LinkedEntityController.
     *
     * @param string              $appName             The app name
     * @param IRequest            $request             The request object
     * @param LinkedEntityService $linkedEntityService The linked entity service
     * @param LoggerInterface     $logger              Logger
     * @param IUserSession        $userSession         Active user session for caller identity
     * @param IGroupManager       $groupManager        Group manager for admin checks
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LinkedEntityService $linkedEntityService,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Check whether the currently authenticated user is a Nextcloud administrator.
     *
     * Register/schema link mutations change register/schema configuration, and
     * reverseLookup scans across all tenants (RBAC/multitenancy intentionally
     * disabled — see LinkedEntityService, TODO #1273); both are admin-only.
     *
     * @return bool True if a user is signed in and belongs to the admin group.
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end isCurrentUserAdmin()

    // SEC-CTRL-8: CSRF protection retained — this is an SPA-called authenticated write
    // (axios sends the CSRF token); #[NoCSRFRequired] removed.

    /**
     * Add a linked entity to an object.
     *
     * POST /api/objects/{uuid}/_linked/{type}
     *
     * @param string $uuid The object UUID
     * @param string $type The linked entity type (mail, contacts, etc.)
     *
     * @return JSONResponse The updated linked IDs
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-48
     */
    #[NoAdminRequired]
    public function addObjectLink(string $uuid, string $type): JSONResponse
    {
        try {
            $body     = $this->request->getParams();
            $entityId = $body['id'] ?? null;

            if ($entityId === null || $entityId === '') {
                return new JSONResponse(['error' => 'Missing required field: id'], 400);
            }

            $result = $this->linkedEntityService->addLink($uuid, $type, (string) $entityId);

            return new JSONResponse(['_'.$type => $result]);
        } catch (NotAuthorizedException $e) {
            // SEC-CTRL-4: write-permission denial maps to 403.
            return new JSONResponse(['error' => $e->getMessage()], 403);
        } catch (Exception $e) {
            $this->logger->error(
                '[LinkedEntityController] addObjectLink failed',
                ['uuid' => $uuid, 'type' => $type, 'error' => $e->getMessage()]
            );

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end addObjectLink()

    // SEC-CTRL-8: CSRF protection retained — SPA-called authenticated write; #[NoCSRFRequired] removed.

    /**
     * Remove a linked entity from an object.
     *
     * DELETE /api/objects/{uuid}/_linked/{type}/{entityId}
     *
     * @param string $uuid     The object UUID
     * @param string $type     The linked entity type
     * @param string $entityId The entity ID to remove
     *
     * @return JSONResponse The updated linked IDs
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-45
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-48
     */
    #[NoAdminRequired]
    public function removeObjectLink(string $uuid, string $type, string $entityId): JSONResponse
    {
        try {
            $result = $this->linkedEntityService->removeLink($uuid, $type, $entityId);

            return new JSONResponse(['_'.$type => $result]);
        } catch (NotAuthorizedException $e) {
            // SEC-CTRL-4: write-permission denial maps to 403.
            return new JSONResponse(['error' => $e->getMessage()], 403);
        } catch (Exception $e) {
            $this->logger->error(
                '[LinkedEntityController] removeObjectLink failed',
                ['uuid' => $uuid, 'type' => $type, 'entityId' => $entityId, 'error' => $e->getMessage()]
            );

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end removeObjectLink()

    // SEC-CTRL-8: CSRF protection retained — SPA-called authenticated write; #[NoCSRFRequired] removed.

    /**
     * Add a linked entity to a register.
     *
     * POST /api/registers/{uuid}/_linked/{type}
     *
     * @param string $uuid The register UUID
     * @param string $type The linked entity type
     *
     * @return JSONResponse The updated linked IDs
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-6
     */
    #[NoAdminRequired]
    public function addRegisterLink(string $uuid, string $type): JSONResponse
    {
        // SEC-CTRL: admin-only — mutates register configuration.
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(['error' => 'Admin privileges required'], 403);
        }

        try {
            $body     = $this->request->getParams();
            $entityId = $body['id'] ?? null;

            if ($entityId === null || $entityId === '') {
                return new JSONResponse(['error' => 'Missing required field: id'], 400);
            }

            $result = $this->linkedEntityService->addLinkToRegister($uuid, $type, (string) $entityId);

            return new JSONResponse(['_'.$type => $result]);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end addRegisterLink()

    // SEC-CTRL-8: CSRF protection retained — SPA-called authenticated write; #[NoCSRFRequired] removed.

    /**
     * Add a linked entity to a schema.
     *
     * POST /api/schemas/{uuid}/_linked/{type}
     *
     * @param string $uuid The schema UUID
     * @param string $type The linked entity type
     *
     * @return JSONResponse The updated linked IDs
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-6
     */
    #[NoAdminRequired]
    public function addSchemaLink(string $uuid, string $type): JSONResponse
    {
        // SEC-CTRL: admin-only — mutates schema configuration.
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(['error' => 'Admin privileges required'], 403);
        }

        try {
            $body     = $this->request->getParams();
            $entityId = $body['id'] ?? null;

            if ($entityId === null || $entityId === '') {
                return new JSONResponse(['error' => 'Missing required field: id'], 400);
            }

            $result = $this->linkedEntityService->addLinkToSchema($uuid, $type, (string) $entityId);

            return new JSONResponse(['_'.$type => $result]);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end addSchemaLink()

    /**
     * Reverse lookup: find all objects and entities linked to a specific entity.
     *
     * GET /api/linked/{type}/{entityId}
     *
     * @param string $type     The linked entity type (mail, contacts, etc.)
     * @param string $entityId The entity ID to search for
     *
     * @return JSONResponse Array of linked objects and entities
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-44
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-49
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reverseLookup(string $type, string $entityId): JSONResponse
    {
        // SEC-CTRL: admin-only — LinkedEntityService::reverseLookup scans magic
        // tables with RBAC + multitenancy intentionally disabled (cross-tenant;
        // TODO #1273). Until per-tenant scoping lands, restrict to admins so an
        // arbitrary user cannot enumerate cross-tenant object links by entity id.
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(['error' => 'Admin privileges required'], 403);
        }

        try {
            $results = $this->linkedEntityService->reverseLookup($type, $entityId);

            return new JSONResponse(
                    [
                        'results' => $results,
                        'total'   => count($results),
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                '[LinkedEntityController] reverseLookup failed',
                ['type' => $type, 'entityId' => $entityId, 'error' => $e->getMessage()]
            );

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end reverseLookup()
}//end class

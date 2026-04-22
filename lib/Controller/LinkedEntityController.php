<?php

/**
 * LinkedEntityController
 *
 * @category Controller
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LinkedEntityService $linkedEntityService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

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
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        } catch (Exception $e) {
            $this->logger->error(
                '[LinkedEntityController] addObjectLink failed',
                ['uuid' => $uuid, 'type' => $type, 'error' => $e->getMessage()]
            );

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end addObjectLink()

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
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function removeObjectLink(string $uuid, string $type, string $entityId): JSONResponse
    {
        try {
            $result = $this->linkedEntityService->removeLink($uuid, $type, $entityId);

            return new JSONResponse(['_'.$type => $result]);
        } catch (Exception $e) {
            $this->logger->error(
                '[LinkedEntityController] removeObjectLink failed',
                ['uuid' => $uuid, 'type' => $type, 'entityId' => $entityId, 'error' => $e->getMessage()]
            );

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end removeObjectLink()

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
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addRegisterLink(string $uuid, string $type): JSONResponse
    {
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
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addSchemaLink(string $uuid, string $type): JSONResponse
    {
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
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reverseLookup(string $type, string $entityId): JSONResponse
    {
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

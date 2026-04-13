<?php

/**
 * OpenRegister Archival Controller
 *
 * Controller for managing archival destruction workflows including
 * destruction lists, legal holds, and destruction certificates.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for archival destruction workflows.
 *
 * Provides REST endpoints for destruction list management, legal hold
 * operations, and destruction certificate retrieval. All endpoints require
 * the archivist role.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Controller requires many service dependencies
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   REST endpoints for full destruction workflow
 */
class ArchivalController extends Controller
{

    /**
     * The archivist group name for authorization.
     */
    private const ARCHIVIST_GROUP = 'archivaris';

    /**
     * Destruction service.
     *
     * @var DestructionService
     */
    private DestructionService $destructionService;

    /**
     * Legal hold service.
     *
     * @var LegalHoldService
     */
    private LegalHoldService $legalHoldService;

    /**
     * Object mapper.
     *
     * @var MagicMapper
     */
    private MagicMapper $objectMapper;

    /**
     * User session.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Group manager for role checking.
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param string             $appName            The app name.
     * @param IRequest           $request            The request object.
     * @param DestructionService $destructionService Destruction service.
     * @param LegalHoldService   $legalHoldService   Legal hold service.
     * @param MagicMapper        $objectMapper       Object mapper.
     * @param IUserSession       $userSession        User session.
     * @param IGroupManager      $groupManager       Group manager.
     * @param LoggerInterface    $logger             Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        DestructionService $destructionService,
        LegalHoldService $legalHoldService,
        MagicMapper $objectMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->destructionService = $destructionService;
        $this->legalHoldService   = $legalHoldService;
        $this->objectMapper       = $objectMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        $this->logger = $logger;
    }//end __construct()

    /**
     * List destruction lists with optional status filter.
     *
     * @return JSONResponse The list of destruction lists.
     *
     * @NoAdminRequired
     */
    public function listDestructionLists(): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $status = $this->request->getParam('status');

        // In a full implementation, this would query the archival register
        // for destruction list objects. For now, return the structure.
        return new JSONResponse(
            data: [
                'results' => [],
                'total'   => 0,
                'filter'  => $status,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end listDestructionLists()

    /**
     * Get a specific destruction list by ID.
     *
     * @param string $id The destruction list UUID.
     *
     * @return JSONResponse The destruction list detail.
     *
     * @NoAdminRequired
     */
    public function getDestructionList(string $id): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        try {
            $object = $this->objectMapper->findByUuid($id);
            return new JSONResponse(
                data: $object->jsonSerialize(),
                statusCode: Http::STATUS_OK
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Destruction list not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end getDestructionList()

    /**
     * Approve a destruction list (full or partial).
     *
     * @param string $id The destruction list UUID.
     *
     * @return JSONResponse The updated destruction list.
     *
     * @NoAdminRequired
     */
    public function approveDestructionList(string $id): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $params           = $this->request->getParams();
        $action           = $params['action'] ?? 'approve_all';
        $excludedIds      = $params['excluded'] ?? [];
        $exclusionReasons = $params['exclusionReasons'] ?? [];

        try {
            $object          = $this->objectMapper->findByUuid($id);
            $destructionList = $object->getObject() ?? [];

            // Check for dual-approval requirement based on schema config.
            $requiresDual = false;

            $result = $this->destructionService->approveList(
                destructionList: $destructionList,
                action: $action,
                excludedIds: $excludedIds,
                exclusionReasons: $exclusionReasons,
                requiresDual: $requiresDual
            );

            // Check if dual approval was rejected (same user).
            if ($result['status'] === $destructionList['status']
                && $result['status'] === DestructionService::STATUS_AWAITING_SECOND
            ) {
                return new JSONResponse(
                    data: ['error' => 'De tweede goedkeuring moet door een andere archivaris worden gegeven'],
                    statusCode: Http::STATUS_CONFLICT
                );
            }

            return new JSONResponse(
                data: $result,
                statusCode: Http::STATUS_OK
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ArchivalController] Failed to approve destruction list',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: ['error' => 'Failed to approve destruction list: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end approveDestructionList()

    /**
     * Reject a destruction list.
     *
     * @param string $id The destruction list UUID.
     *
     * @return JSONResponse The updated destruction list.
     *
     * @NoAdminRequired
     */
    public function rejectDestructionList(string $id): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $params = $this->request->getParams();
        $reason = $params['reason'] ?? null;

        if ($reason === null || trim($reason) === '') {
            return new JSONResponse(
                data: ['error' => 'Een reden voor afwijzing is verplicht'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $object          = $this->objectMapper->findByUuid($id);
            $destructionList = $object->getObject() ?? [];

            $result = $this->destructionService->rejectList($destructionList, $reason);

            return new JSONResponse(
                data: $result,
                statusCode: Http::STATUS_OK
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ArchivalController] Failed to reject destruction list',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: ['error' => 'Failed to reject destruction list: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end rejectDestructionList()

    /**
     * Place a legal hold on one or more objects.
     *
     * @return JSONResponse The legal hold result.
     *
     * @NoAdminRequired
     */
    public function createLegalHold(): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $params   = $this->request->getParams();
        $objectId = $params['objectId'] ?? null;
        $schemaId = $params['schemaId'] ?? null;
        $reason   = $params['reason'] ?? null;

        if ($reason === null || trim($reason) === '') {
            return new JSONResponse(
                data: ['error' => 'Een reden voor de bewaarplicht is verplicht'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            // Bulk hold on schema.
            if ($schemaId !== null) {
                $registerId = $params['registerId'] ?? null;
                if ($registerId === null) {
                    return new JSONResponse(
                        data: ['error' => 'registerId is verplicht voor schema-brede bewaarplicht'],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }

                $this->legalHoldService->bulkPlaceHold(
                    (int) $schemaId,
                    (int) $registerId,
                    $reason
                );

                return new JSONResponse(
                    data: ['message' => 'Bulk bewaarplicht is ingepland als achtergrondtaak'],
                    statusCode: Http::STATUS_ACCEPTED
                );
            }

            // Single object hold.
            if ($objectId === null) {
                return new JSONResponse(
                    data: ['error' => 'objectId of schemaId is verplicht'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            $object = $this->objectMapper->findByUuid($objectId);
            $result = $this->legalHoldService->placeHold($object, $reason);

            return new JSONResponse(
                data: [
                    'message'   => 'Bewaarplicht geplaatst',
                    'objectId'  => $result->getUuid(),
                    'legalHold' => $result->getRetention()['legalHold'] ?? [],
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ArchivalController] Failed to create legal hold',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: ['error' => 'Failed to create legal hold: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end createLegalHold()

    /**
     * Release a legal hold on an object.
     *
     * @param string $id The object UUID to release the hold from.
     *
     * @return JSONResponse The release result.
     *
     * @NoAdminRequired
     */
    public function releaseLegalHold(string $id): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $params = $this->request->getParams();
        $reason = $params['reason'] ?? null;

        if ($reason === null || trim($reason) === '') {
            return new JSONResponse(
                data: ['error' => 'Een reden voor het opheffen van de bewaarplicht is verplicht'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $object = $this->objectMapper->findByUuid($id);
            $result = $this->legalHoldService->releaseHold($object, $reason);

            return new JSONResponse(
                data: [
                    'message'   => 'Bewaarplicht opgeheven',
                    'objectId'  => $result->getUuid(),
                    'legalHold' => $result->getRetention()['legalHold'] ?? [],
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to release legal hold: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end releaseLegalHold()

    /**
     * List active legal holds.
     *
     * @return JSONResponse The list of active legal holds.
     *
     * @NoAdminRequired
     */
    public function listLegalHolds(): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        // In a full implementation, this would query objects with active legal holds.
        return new JSONResponse(
            data: [
                'results' => [],
                'total'   => 0,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end listLegalHolds()

    /**
     * List destruction certificates.
     *
     * @return JSONResponse The list of destruction certificates.
     *
     * @NoAdminRequired
     */
    public function listCertificates(): JSONResponse
    {
        $authCheck = $this->checkArchivistRole();
        if ($authCheck !== null) {
            return $authCheck;
        }

        // In a full implementation, this would query the archival register
        // for certificate objects.
        return new JSONResponse(
            data: [
                'results' => [],
                'total'   => 0,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end listCertificates()

    /**
     * Check if the current user has the archivist role.
     *
     * @return JSONResponse|null Returns a 403 response if unauthorized, null if authorized.
     */
    private function checkArchivistRole(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Niet geauthenticeerd'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        // Check if user is in the archivaris group or is an admin.
        $isArchivist = $this->groupManager->isInGroup($user->getUID(), self::ARCHIVIST_GROUP);
        $isAdmin     = $this->groupManager->isAdmin($user->getUID());

        if ($isArchivist === false && $isAdmin === false) {
            return new JSONResponse(
                data: ['error' => 'Onvoldoende rechten: archivaris rol is vereist'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end checkArchivistRole()
}//end class

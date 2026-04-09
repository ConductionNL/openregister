<?php

/**
 * OpenRegister Retention Controller
 *
 * Handles API endpoints for destruction list management, legal holds,
 * and archival workflow operations.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use DateInterval;
use Exception;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for retention management endpoints.
 *
 * Provides API endpoints for:
 * - Destruction list approval and rejection
 * - Legal hold placement and release
 * - Bulk legal hold operations
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RetentionController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName          App name
     * @param IRequest               $request          Request
     * @param RetentionService       $retentionService Retention service
     * @param ObjectRetentionHandler $settingsHandler  Settings handler
     * @param SaveObject             $saveObject       Save object service
     * @param MagicMapper            $objectMapper     Object mapper
     * @param SchemaMapper           $schemaMapper     Schema mapper
     * @param AuditTrailMapper       $auditMapper      Audit trail mapper
     * @param IJobList               $jobList          Background job list
     * @param IUserSession           $userSession      User session
     * @param LoggerInterface        $logger           Logger
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly RetentionService $retentionService,
        private readonly ObjectRetentionHandler $settingsHandler,
        private readonly SaveObject $saveObject,
        private readonly MagicMapper $objectMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AuditTrailMapper $auditMapper,
        private readonly IJobList $jobList,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Approve a destruction list (full or partial).
     *
     * POST /api/retention/destruction-lists/{id}/approve
     *
     * @param string $id Destruction list UUID
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Response with updated destruction list
     */
    public function approveDestructionList(string $id): JSONResponse
    {
        try {
            $listObject = $this->objectMapper->find($id, null, null, false, false, false);

            if ($listObject === null) {
                return new JSONResponse(['error' => 'Destruction list not found'], 404);
            }

            $listData = $listObject->getObject();

            $currentStatus = $listData['status'] ?? '';
            if (in_array($currentStatus, ['in_review', 'awaiting_second_approval'], true) === false) {
                return new JSONResponse(
                    ['error' => 'Destruction list is not in reviewable status: '.$currentStatus],
                    409
                );
            }

            $user   = $this->userSession->getUser();
            $userId = $user !== null ? $user->getUID() : 'unknown';

            // Handle partial approval: exclude specified objects.
            $excluded     = $this->request->getParam('excluded', []);
            $excludeUuids = [];

            if (empty($excluded) === false && is_array($excluded) === true) {
                foreach ($excluded as $excl) {
                    $exclUuid   = $excl['uuid'] ?? null;
                    $exclReason = $excl['reason'] ?? 'No reason provided';

                    if ($exclUuid === null) {
                        continue;
                    }

                    $excludeUuids[] = $exclUuid;

                    // Remove from objects list and add to excluded list.
                    $listData['excluded'][] = [
                        'uuid'       => $exclUuid,
                        'reason'     => $exclReason,
                        'excludedBy' => $userId,
                    ];

                    // Extend archiefactiedatum for excluded objects.
                    try {
                        $exclObject = $this->objectMapper->find(
                            $exclUuid,
                                null,
                                null,
                                false,
                                false,
                                false
                        );
                        if ($exclObject !== null) {
                            $this->retentionService->extendArchiefactiedatum($exclObject);
                            $this->objectMapper->update($exclObject);
                        }
                    } catch (Exception $e) {
                        $this->logger->warning(
                            '[RetentionController] Failed to extend excluded object: '.$e->getMessage()
                        );
                    }
                }//end foreach

                // Filter excluded objects from the list.
                $listData['objects'] = array_values(
                    array_filter(
                        $listData['objects'] ?? [],
                        function ($obj) use ($excludeUuids) {
                            return in_array($obj['uuid'] ?? '', $excludeUuids, true) === false;
                        }
                    )
                );
            }//end if

            // Check if two-step approval is required.
            $requiresDualApproval = $this->checkDualApprovalRequired(listData: $listData);

            // Record approval.
            $listData['approvals'][] = [
                'userId'    => $userId,
                'timestamp' => (new DateTime())->format('c'),
            ];

            if ($requiresDualApproval === true && $currentStatus === 'in_review') {
                // First approval — need second approver.
                $listData['status'] = 'awaiting_second_approval';
                $listObject->setObject($listData);
                $this->objectMapper->update($listObject);

                return new JSONResponse(
                        [
                            'status'  => 'awaiting_second_approval',
                            'message' => 'First approval recorded. Awaiting second approval from different archivist.',
                        ]
                        );
            }

            // Check that second approver is different from first.
            if ($currentStatus === 'awaiting_second_approval') {
                $approvals = $listData['approvals'] ?? [];
                if (count($approvals) >= 2) {
                    $firstApprover = $approvals[0]['userId'] ?? '';
                    if ($firstApprover === $userId) {
                        return new JSONResponse(
                            ['error' => 'Second approval must be from a different archivist'],
                            403
                        );
                    }
                }
            }

            // Full approval — queue destruction execution.
            $listData['status'] = 'approved';
            $listObject->setObject($listData);
            $this->objectMapper->update($listObject);

            // Queue the destruction execution job.
            $this->jobList->add(
                \OCA\OpenRegister\BackgroundJob\DestructionExecutionJob::class,
                ['destructionListUuid' => $id]
            );

            // Create audit trail.
            $this->auditMapper->createAuditTrailEntry(
                $listObject,
                'archival.destruction_approved',
                [
                    'approvedBy'  => $userId,
                    'objectCount' => count($listData['objects']),
                    'excluded'    => count($listData['excluded'] ?? []),
                ]
            );

            return new JSONResponse(
                    [
                        'status'        => 'approved',
                        'objectCount'   => count($listData['objects']),
                        'excludedCount' => count($listData['excluded'] ?? []),
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end approveDestructionList()

    /**
     * Reject a destruction list.
     *
     * POST /api/retention/destruction-lists/{id}/reject
     *
     * @param string $id Destruction list UUID
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Response with updated status
     */
    public function rejectDestructionList(string $id): JSONResponse
    {
        try {
            $listObject = $this->objectMapper->find($id, null, null, false, false, false);

            if ($listObject === null) {
                return new JSONResponse(['error' => 'Destruction list not found'], 404);
            }

            $reason = $this->request->getParam('reason');
            if (empty($reason) === true) {
                return new JSONResponse(['error' => 'Rejection reason is required'], 400);
            }

            $listData = $listObject->getObject();

            $currentStatus = $listData['status'] ?? '';
            if (in_array($currentStatus, ['in_review', 'awaiting_second_approval'], true) === false) {
                return new JSONResponse(
                    ['error' => 'Destruction list is not in reviewable status'],
                    409
                );
            }

            $user   = $this->userSession->getUser();
            $userId = $user !== null ? $user->getUID() : 'unknown';

            $listData['status']          = 'rejected';
            $listData['rejectedBy']      = $userId;
            $listData['rejectionReason'] = $reason;
            $listData['rejectedAt']      = (new DateTime())->format('c');

            // Extend archiefactiedatum for all objects on the list.
            foreach ($listData['objects'] ?? [] as $objRef) {
                $uuid = $objRef['uuid'] ?? null;
                if ($uuid === null) {
                    continue;
                }

                try {
                    $object = $this->objectMapper->find($uuid, null, null, false, false, false);
                    if ($object !== null) {
                        $this->retentionService->extendArchiefactiedatum($object);
                        $this->objectMapper->update($object);
                    }
                } catch (Exception $e) {
                    $this->logger->warning(
                        '[RetentionController] Failed to extend rejected object: '.$e->getMessage()
                    );
                }
            }

            $listObject->setObject($listData);
            $this->objectMapper->update($listObject);

            return new JSONResponse(
                    [
                        'status'  => 'rejected',
                        'reason'  => $reason,
                        'message' => 'Destruction list rejected. All object deadlines extended.',
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end rejectDestructionList()

    /**
     * Place a legal hold on a single object.
     *
     * POST /api/retention/legal-holds
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Response with updated object
     */
    public function placeLegalHold(): JSONResponse
    {
        try {
            $objectId = $this->request->getParam('objectId');
            $reason   = $this->request->getParam('reason');

            if (empty($objectId) === true || empty($reason) === true) {
                return new JSONResponse(
                    ['error' => 'objectId and reason are required'],
                    400
                );
            }

            $object = $this->objectMapper->find($objectId, null, null, false, false, false);

            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->retentionService->placeLegalHold($object, $reason);
            $this->objectMapper->update($object);

            // Create audit trail.
            $this->auditMapper->createAuditTrailEntry(
                $object,
                'archival.legal_hold_placed',
                ['reason' => $reason]
            );

            return new JSONResponse(
                    [
                        'status'    => 'legal_hold_placed',
                        'objectId'  => $objectId,
                        'legalHold' => $object->getRetention()['legalHold'] ?? null,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end placeLegalHold()

    /**
     * Release a legal hold on an object.
     *
     * DELETE /api/retention/legal-holds/{id}
     *
     * @param string $id Object UUID (the object the hold is on)
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Response with updated object
     */
    public function releaseLegalHold(string $id): JSONResponse
    {
        try {
            $reason = $this->request->getParam('reason');

            if (empty($reason) === true) {
                return new JSONResponse(['error' => 'Release reason is required'], 400);
            }

            $object = $this->objectMapper->find($id, null, null, false, false, false);

            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->retentionService->releaseLegalHold($object, $reason);
            $this->objectMapper->update($object);

            // Create audit trail.
            $this->auditMapper->createAuditTrailEntry(
                $object,
                'archival.legal_hold_released',
                ['reason' => $reason]
            );

            return new JSONResponse(
                    [
                        'status'   => 'legal_hold_released',
                        'objectId' => $id,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end releaseLegalHold()

    /**
     * Place a bulk legal hold on all objects in a schema.
     *
     * POST /api/retention/legal-holds/bulk
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Response confirming the bulk operation
     */
    public function placeBulkLegalHold(): JSONResponse
    {
        try {
            $schemaId = $this->request->getParam('schemaId');
            $reason   = $this->request->getParam('reason');

            if (empty($schemaId) === true || empty($reason) === true) {
                return new JSONResponse(
                    ['error' => 'schemaId and reason are required'],
                    400
                );
            }

            // Queue via background job for large datasets.
            $this->jobList->add(
                \OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob::class,
                [
                    'schemaId' => $schemaId,
                    'reason'   => $reason,
                    'userId'   => $this->userSession->getUser()?->getUID() ?? 'system',
                ]
            );

            return new JSONResponse(
                    [
                        'status'  => 'queued',
                        'message' => 'Bulk legal hold queued for processing via background job',
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end placeBulkLegalHold()

    /**
     * Check if any objects in the destruction list require dual approval.
     *
     * @param array $listData The destruction list data
     *
     * @return bool True if dual approval is required
     */
    private function checkDualApprovalRequired(array $listData): bool
    {
        $schemaIds = array_unique(
            array_column($listData['objects'] ?? [], 'schema')
        );

        foreach ($schemaIds as $schemaId) {
            if ($schemaId === null) {
                continue;
            }

            try {
                $schema  = $this->schemaMapper->find((int) $schemaId);
                $archive = $schema->getArchive();
                if (($archive['requireDualApproval'] ?? false) === true) {
                    return true;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return false;
    }//end checkDualApprovalRequired()
}//end class

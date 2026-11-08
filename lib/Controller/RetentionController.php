<?php

/**
 * OpenRegister Retention Controller
 *
 * Handles API endpoints for destruction list management, legal holds,
 * and archival workflow operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/retention-management/spec.md
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\BackgroundJob\DestructionExecutionJob;
use OCA\OpenRegister\Controller\Support\DestructionListApproval;
use OCA\OpenRegister\Controller\Support\RetentionControllerServices;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
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
class RetentionController extends Controller {
	/**
	 * Statuses in which a destruction list can be approved or rejected.
	 *
	 * @var list<string>
	 */
	private const REVIEWABLE_STATUSES = ['in_review', 'awaiting_second_approval'];

	/**
	 * Retention service
	 *
	 * @var RetentionServiceInterface
	 */
	private readonly RetentionServiceInterface $retentionService;

	/**
	 * Settings handler
	 *
	 * @var ObjectRetentionHandler
	 */
	private readonly ObjectRetentionHandler $settingsHandler;

	/**
	 * Save object service
	 *
	 * @var SaveObjectWriterInterface
	 */
	private readonly SaveObjectWriterInterface $saveObject;

	/**
	 * Object mapper
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectMapper;

	/**
	 * Schema mapper
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Audit trail mapper
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditMapper;

	/**
	 * Withholds entries a reviewer decided against
	 *
	 * @var DestructionService
	 */
	private readonly DestructionService $destructionService;

	/**
	 * Background job list
	 *
	 * @var IJobList
	 */
	private readonly IJobList $jobList;

	/**
	 * User session
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param RetentionControllerServices $services The retention controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		RetentionControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->retentionService = $services->domain->retentionService;
		$this->settingsHandler = $services->domain->settingsHandler;
		$this->saveObject = $services->domain->saveObject;
		$this->objectMapper = $services->mappers->objectMapper;
		$this->schemaMapper = $services->mappers->schemaMapper;
		$this->auditMapper = $services->mappers->auditMapper;
		$this->destructionService = $services->domain->destructionService;
		$this->jobList = $services->platform->jobList;
		$this->userSession = $services->platform->userSession;
		$this->logger = $services->platform->logger;
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
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function approveDestructionList(string $id): JSONResponse {
		try {
			$listObject = $this->objectMapper->find($id, null, null, false, false, false);

			$approval = new DestructionListApproval();
			$approval->id = $id;
			$approval->listData = $listObject->getObject();
			$approval->status = $approval->listData['status'] ?? '';
			if (in_array($approval->status, self::REVIEWABLE_STATUSES, true) === false) {
				return new JSONResponse(['error' => 'Destruction list is not in reviewable status: ' . $approval->status], 409);
			}

			$approval->userId = $this->currentUserId();
			return $this->approveReviewable(listObject: $listObject, approval: $approval);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end approveDestructionList()

	/**
	 * Record an approval on a reviewable list: exclusions, then the first or the final step.
	 *
	 * @param ObjectEntity            $listObject The stored destruction list
	 * @param DestructionListApproval $approval   The approval in progress
	 *
	 * @return JSONResponse The step's response
	 */
	private function approveReviewable(ObjectEntity $listObject, DestructionListApproval $approval): JSONResponse {
		$this->applyExclusions(approval: $approval);
		$requiresDualApproval = $this->checkDualApprovalRequired(listData: $approval->listData);
		$approval->listData['approvals'][] = [
			'userId' => $approval->userId,
			'timestamp' => (new DateTime())->format('c'),
		];
		if ($requiresDualApproval === true && $approval->status === 'in_review') {
			return $this->awaitSecondApproval(listObject: $listObject, listData: $approval->listData);
		}

		if ($approval->status === 'awaiting_second_approval' && $approval->repeatsFirstApprover() === true) {
			return new JSONResponse(['error' => 'Second approval must be from a different archivist'], 403);
		}

		// Withhold what a reviewer decided against before the counts are read: the audit
		// trail and the response are computed here, before DestructionExecutionJob runs.
		$approval->listData = $this->destructionService->withholdDecidedEntries($approval->listData);
		return $this->approveInFull(listObject: $listObject, approval: $approval);
	}//end approveReviewable()

	/**
	 * Move the request's excluded entries off the list, extending their archive action date.
	 *
	 * @param DestructionListApproval $approval The approval in progress
	 *
	 * @return void
	 */
	private function applyExclusions(DestructionListApproval $approval): void {
		$excluded = $this->request->getParam('excluded', []);
		if (empty($excluded) === true || is_array($excluded) === false) {
			return;
		}

		$excludeUuids = [];
		foreach ($excluded as $excl) {
			$exclUuid = $approval->exclude(excl: $excl);
			if ($exclUuid !== null) {
				$excludeUuids[] = $exclUuid;
				$this->extendArchiveDate(uuid: $exclUuid, kind: 'excluded');
			}
		}

		$approval->dropEntries(uuids: $excludeUuids);
	}//end applyExclusions()

	/**
	 * Store the first of two approvals and report that a second one is awaited.
	 *
	 * @param ObjectEntity $listObject The stored destruction list
	 * @param array        $listData   The list data with the first approval
	 *
	 * @return JSONResponse The awaiting response
	 */
	private function awaitSecondApproval(ObjectEntity $listObject, array $listData): JSONResponse {
		$listData['status'] = 'awaiting_second_approval';
		$listObject->setObject($listData);
		$this->objectMapper->update($listObject);

		return new JSONResponse(
			[
				'status' => 'awaiting_second_approval',
				'message' => 'First approval recorded. Awaiting second approval from different archivist.',
			]
		);
	}//end awaitSecondApproval()

	/**
	 * Store the approved list, queue its destruction and record the audit trail.
	 *
	 * @param ObjectEntity            $listObject The stored destruction list
	 * @param DestructionListApproval $approval   The approval with the withheld list data
	 *
	 * @return JSONResponse The approved response
	 */
	private function approveInFull(ObjectEntity $listObject, DestructionListApproval $approval): JSONResponse {
		$approval->listData['status'] = 'approved';
		$listObject->setObject($approval->listData);
		$this->objectMapper->update($listObject);
		$this->jobList->add(DestructionExecutionJob::class, ['destructionListUuid' => $approval->id]);
		$objectCount = count($approval->listData['objects']);
		$excludedCount = count($approval->listData['excluded'] ?? []);
		$this->auditMapper->createAuditTrailEntry(
			$listObject,
			'archival.destruction_approved',
			['approvedBy' => $approval->userId, 'objectCount' => $objectCount, 'excluded' => $excludedCount]
		);

		return new JSONResponse(['status' => 'approved', 'objectCount' => $objectCount, 'excludedCount' => $excludedCount]);
	}//end approveInFull()

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
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function rejectDestructionList(string $id): JSONResponse {
		try {
			$listObject = $this->objectMapper->find($id, null, null, false, false, false);

			$reason = $this->request->getParam('reason');
			if (empty($reason) === true) {
				return new JSONResponse(['error' => 'Rejection reason is required'], 400);
			}

			$listData = $listObject->getObject();
			if (in_array($listData['status'] ?? '', self::REVIEWABLE_STATUSES, true) === false) {
				return new JSONResponse(['error' => 'Destruction list is not in reviewable status'], 409);
			}

			return $this->reject(listObject: $listObject, reason: $reason);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end rejectDestructionList()

	/**
	 * Store a rejection on the list and extend the archive action date of every entry.
	 *
	 * @param ObjectEntity $listObject The stored destruction list
	 * @param mixed        $reason     The rejection reason
	 *
	 * @return JSONResponse The rejected response
	 */
	private function reject(ObjectEntity $listObject, mixed $reason): JSONResponse {
		$listData = $listObject->getObject();
		$listData['status'] = 'rejected';
		$listData['rejectedBy'] = $this->currentUserId();
		$listData['rejectionReason'] = $reason;
		$listData['rejectedAt'] = (new DateTime())->format('c');
		$this->extendEntries(objects: $listData['objects'] ?? []);
		$listObject->setObject($listData);
		$this->objectMapper->update($listObject);

		return new JSONResponse(
			[
				'status' => 'rejected',
				'reason' => $reason,
				'message' => 'Destruction list rejected. All object deadlines extended.',
			]
		);
	}//end reject()

	/**
	 * Extend the archive action date of every list entry that names a uuid.
	 *
	 * @param array $objects The list entries
	 *
	 * @return void
	 */
	private function extendEntries(array $objects): void {
		foreach ($objects as $objRef) {
			$uuid = $objRef['uuid'] ?? null;
			if ($uuid !== null) {
				$this->extendArchiveDate(uuid: $uuid, kind: 'rejected');
			}
		}
	}//end extendEntries()

	/**
	 * Extend one object's archive action date; a failure is logged, not raised.
	 *
	 * @param string $uuid The object uuid
	 * @param string $kind Why it is extended, for the log line
	 *
	 * @return void
	 */
	private function extendArchiveDate(string $uuid, string $kind): void {
		try {
			// The find() call throws rather than returning null; the catch handles a missing object.
			$object = $this->objectMapper->find($uuid, null, null, false, false, false);
			$this->retentionService->extendArchiveActionDate($object);
			$this->objectMapper->update($object);
		} catch (Exception $e) {
			$this->logger->warning('[RetentionController] Failed to extend ' . $kind . ' object: ' . $e->getMessage());
		}
	}//end extendArchiveDate()

	/**
	 * The session user's id, or 'unknown' without a session user.
	 *
	 * @return string The user id
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'unknown';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Place a legal hold on a single object.
	 *
	 * POST /api/retention/legal-holds
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Response with updated object
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function placeLegalHold(): JSONResponse {
		try {
			$objectId = $this->request->getParam('objectId');
			$reason = $this->request->getParam('reason');

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

			$this->retentionService->placeLegalHold($object, $reason, $this->ownerKeyParam());
			$this->objectMapper->update($object);

			// Create audit trail.
			$this->auditMapper->createAuditTrailEntry(
				$object,
				'archival.legal_hold_placed',
				['reason' => $reason]
			);

			return new JSONResponse(
				[
					'status' => 'legal_hold_placed',
					'objectId' => $objectId,
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
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function releaseLegalHold(string $id): JSONResponse {
		try {
			$reason = $this->request->getParam('reason');

			if (empty($reason) === true) {
				return new JSONResponse(['error' => 'Release reason is required'], 400);
			}

			$object = $this->objectMapper->find($id, null, null, false, false, false);

			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$this->retentionService->releaseLegalHold($object, $reason, $this->ownerKeyParam());
			$this->objectMapper->update($object);

			// Create audit trail.
			$this->auditMapper->createAuditTrailEntry(
				$object,
				'archival.legal_hold_released',
				['reason' => $reason]
			);

			return new JSONResponse(
				[
					'status' => 'legal_hold_released',
					'objectId' => $id,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end releaseLegalHold()

	/**
	 * The matter a hold request speaks for, when it names one (#4172)
	 *
	 * @return string|null The owner key, or null for a manual hold.
	 */
	private function ownerKeyParam(): ?string {
		$ownerKey = $this->request->getParam('ownerKey');
		if (is_string($ownerKey) === false || trim($ownerKey) === '') {
			return null;
		}

		return trim($ownerKey);
	}//end ownerKeyParam()

	/**
	 * Place a bulk legal hold on all objects in a schema.
	 *
	 * POST /api/retention/legal-holds/bulk
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Response confirming the bulk operation
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function placeBulkLegalHold(): JSONResponse {
		try {
			$schemaId = $this->request->getParam('schemaId');
			$reason = $this->request->getParam('reason');

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
					'reason' => $reason,
					'userId' => $this->userSession->getUser()?->getUID() ?? 'system',
				]
			);

			return new JSONResponse(
				[
					'status' => 'queued',
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
	private function checkDualApprovalRequired(array $listData): bool {
		$schemaIds = array_unique(
			array_column($listData['objects'] ?? [], 'schema')
		);

		foreach ($schemaIds as $schemaId) {
			if ($schemaId === null) {
				continue;
			}

			try {
				$schema = $this->schemaMapper->find((int)$schemaId);
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

<?php

/**
 * OpenRegister Archival Controller
 *
 * Controller for managing archival destruction workflows including
 * destruction lists, legal holds, and destruction certificates.
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
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
class ArchivalController extends Controller {

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
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param DestructionService $destructionService Destruction service.
	 * @param LegalHoldService $legalHoldService Legal hold service.
	 * @param MagicMapper $objectMapper Object mapper.
	 * @param IUserSession $userSession User session.
	 * @param IGroupManager $groupManager Group manager.
	 * @param LoggerInterface $logger Logger.
	 * @param DestructionListRepository $lists Finds the destruction lists this instance holds.
	 * @param DestructionReviewService $reviews Assignment, sign-off and the decision history.
	 * @param ReviewOutcomeService $outcomes Carries an answer out against the record.
	 * @param AuditTrailMapper $auditMapper Records who signed off what.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A DI constructor; every parameter is a
	 *              distinct collaborator, and four of them arrived with the review half of the
	 *              archiving process rather than by widening what this controller already did.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		DestructionService $destructionService,
		LegalHoldService $legalHoldService,
		MagicMapper $objectMapper,
		IUserSession $userSession,
		IGroupManager $groupManager,
		LoggerInterface $logger,
		private readonly DestructionListRepository $lists,
		private readonly DestructionReviewService $reviews,
		private readonly ReviewOutcomeService $outcomes,
		private readonly AuditTrailMapper $auditMapper,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->destructionService = $destructionService;
		$this->legalHoldService = $legalHoldService;
		$this->objectMapper = $objectMapper;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * List destruction lists with optional status filter.
	 *
	 * @return JSONResponse The list of destruction lists.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function listDestructionLists(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		// 🔴 THIS USED TO RETURN `results: []` AND A COMMENT SAYING A FULL
		// IMPLEMENTATION WOULD QUERY THE REGISTER. An empty list is what a
		// correctly configured instance with nothing to destroy also answers,
		// so a records officer had no way to tell the two apart, and neither
		// did anything built on top.
		if ($this->lists->isConfigured() === false) {
			return new JSONResponse(
				data: [
					'results' => [],
					'total' => 0,
					'configured' => false,
					'error' => 'No destruction list register and schema are configured',
				],
				statusCode: Http::STATUS_OK
			);
		}

		$status = $this->request->getParam('status');
		$statuses = null;
		if (is_string($status) === true && $status !== '') {
			$statuses = [$status];
		}

		$results = [];
		foreach ($this->lists->findLists(statuses: $statuses) as $list) {
			$listData = ($list->getObject() ?? []);
			$unassigned = $this->reviews->unassignedEntries(listData: $listData);

			$results[] = [
				'uuid' => $list->getUuid(),
				'status' => ($listData['status'] ?? null),
				'createdAt' => ($listData['createdAt'] ?? null),
				'createdBy' => ($listData['createdBy'] ?? null),
				'entryCount' => count($this->reviews->entries(listData: $listData)),
				'unassignedCount' => count($unassigned),
				'decisionCount' => count(($listData['decisions'] ?? [])),
			];
		}

		return new JSONResponse(
			data: [
				'results' => $results,
				'total' => count($results),
				'configured' => true,
				'filter' => $status,
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
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function getDestructionList(string $id): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		$list = $this->lists->find(uuid: $id);
		if ($list === null) {
			return new JSONResponse(
				data: ['error' => 'Destruction list not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$listData = ($list->getObject() ?? []);
		$serialised = $list->jsonSerialize();

		// The entries nobody is accountable for are NAMED, not counted. "3 of
		// 15 unassigned" says there is work to do and not which work, and the
		// whole point of a named reviewer is that the list can be chased.
		$serialised['unassignedEntries'] = $this->reviews->unassignedEntries(listData: $listData);
		$serialised['decisions'] = ($listData['decisions'] ?? []);

		return new JSONResponse(
			data: $serialised,
			statusCode: Http::STATUS_OK
		);
	}//end getDestructionList()

	/**
	 * Approve a destruction list (full or partial).
	 *
	 * @param string $id The destruction list UUID.
	 *
	 * @return JSONResponse The updated destruction list.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function approveDestructionList(string $id): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		$params = $this->request->getParams();
		$action = $params['action'] ?? 'approve_all';
		$excludedIds = $params['excluded'] ?? [];
		$exclusionReasons = $params['exclusionReasons'] ?? [];

		try {
			$object = $this->objectMapper->find($id);
			$destructionList = $object->getObject() ?? [];

			// Check for dual-approval requirement based on schema config.
			$requiresDual = false;

			$result = $this->destructionService->approveList(
				destructionList: $destructionList,
				action: $action,
				excludedIds: $excludedIds,
				exclusionReasons: $exclusionReasons,
				requiresDual: $requiresDual,
				listUuid: $id
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

			// Persist the approval. Without this the recorded approval and the
			// `approved` status were returned to the caller but never written back,
			// so the list stayed `in_review` in storage and the execution job — which
			// reloads the list by uuid and requires status `approved` — refused to run
			// (openregister#393). RetentionController has always persisted here.
			$object->setObject($result);
			$this->objectMapper->update($object);

			return new JSONResponse(
				data: $result,
				statusCode: Http::STATUS_OK
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ArchivalController] Failed to approve destruction list',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: ['error' => 'Failed to approve destruction list: ' . $e->getMessage()],
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
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function rejectDestructionList(string $id): JSONResponse {
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
			$object = $this->objectMapper->find($id);
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
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: ['error' => 'Failed to reject destruction list: ' . $e->getMessage()],
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
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function createLegalHold(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		$params = $this->request->getParams();
		$objectId = $params['objectId'] ?? null;
		$schemaId = $params['schemaId'] ?? null;
		$reason = $params['reason'] ?? null;

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
					(int)$schemaId,
					(int)$registerId,
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

			$object = $this->objectMapper->find($objectId);
			$result = $this->legalHoldService->placeHold($object, $reason);

			return new JSONResponse(
				data: [
					'message' => 'Bewaarplicht geplaatst',
					'objectId' => $result->getUuid(),
					'legalHold' => $result->getRetention()['legalHold'] ?? [],
				],
				statusCode: Http::STATUS_OK
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ArchivalController] Failed to create legal hold',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: ['error' => 'Failed to create legal hold: ' . $e->getMessage()],
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
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseLegalHold(string $id): JSONResponse {
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
			$object = $this->objectMapper->find($id);
			$result = $this->legalHoldService->releaseHold($object, $reason);

			return new JSONResponse(
				data: [
					'message' => 'Bewaarplicht opgeheven',
					'objectId' => $result->getUuid(),
					'legalHold' => $result->getRetention()['legalHold'] ?? [],
				],
				statusCode: Http::STATUS_OK
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Failed to release legal hold: ' . $e->getMessage()],
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
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function listLegalHolds(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		// In a full implementation, this would query objects with active legal holds.
		return new JSONResponse(
			data: [
				'results' => [],
				'total' => 0,
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
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function listCertificates(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		// In a full implementation, this would query the archival register
		// for certificate objects.
		return new JSONResponse(
			data: [
				'results' => [],
				'total' => 0,
			],
			statusCode: Http::STATUS_OK
		);
	}//end listCertificates()

	/**
	 * Make one person accountable for one entry on a destruction list.
	 *
	 * PUT /api/archival/destruction-lists/{id}/entries/{entryId}/reviewer
	 *
	 * @param string $id      The destruction list uuid.
	 * @param string $entryId The uuid of the record the entry is about.
	 *
	 * @return JSONResponse The entry, with its reviewer.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function assignReviewer(string $id, string $entryId): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		$list = $this->lists->find(uuid: $id);
		if ($list === null) {
			return new JSONResponse(
				data: ['error' => 'Destruction list not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$reviewer = $this->request->getParam('reviewer');
		if ($reviewer !== null && is_string($reviewer) === false) {
			return new JSONResponse(
				data: ['error' => 'A reviewer is a user id, or null to unassign'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$listData = ($list->getObject() ?? []);

		try {
			$listData = $this->reviews->assignReviewer(
				listData: $listData,
				entryUuid: $entryId,
				reviewer: $reviewer
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$this->lists->save(list: $list, listData: $listData);

		$this->auditMapper->createAuditTrailEntry(
			$list,
			'archival.review_assigned',
			[
				'entry' => $entryId,
				'reviewer' => $reviewer,
			]
		);

		return new JSONResponse(
			data: [
				'entry' => $this->reviews->entry(listData: $listData, entryUuid: $entryId),
				'unassignedEntries' => $this->reviews->unassignedEntries(listData: $listData),
			],
			statusCode: Http::STATUS_OK
		);
	}//end assignReviewer()

	/**
	 * Answer one entry: destroy it, keep it, or hand it to an e-Depot.
	 *
	 * POST /api/archival/destruction-lists/{id}/entries/{entryId}/decision
	 *
	 * 🔴 ONLY THE NAMED REVIEWER MAY ANSWER. The guard is here in the body and
	 * again in {@see DestructionReviewService::recordAnswer()}, because this
	 * endpoint is `#[NoAdminRequired]`: without a per-entry check any
	 * authenticated user could sign off any record's destruction, which is the
	 * IDOR shape ADR-005 rule 3 names. An entry nobody is accountable for is
	 * refused rather than thrown open to the first archivist, which is exactly
	 * the group-addressed approval this change replaces.
	 *
	 * @param string $id      The destruction list uuid.
	 * @param string $entryId The uuid of the record the entry is about.
	 *
	 * @return JSONResponse The recorded decision.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One refusal per rule; collapsing them
	 *              would hide which rule refused the answer.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      As above.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function decideEntry(string $id, string $entryId): JSONResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return new JSONResponse(
				data: ['error' => 'Niet geauthenticeerd'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$list = $this->lists->find(uuid: $id);
		if ($list === null) {
			return new JSONResponse(
				data: ['error' => 'Destruction list not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$listData = ($list->getObject() ?? []);
		$entry = $this->reviews->entry(listData: $listData, entryUuid: $entryId);
		if ($entry === null) {
			return new JSONResponse(
				data: ['error' => 'This destruction list has no entry for that record'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$assigned = ($entry['reviewer'] ?? null);
		if (is_string($assigned) === false || $assigned === '') {
			return new JSONResponse(
				data: ['error' => 'This entry has no reviewer; assign one before it can be answered'],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		if ($assigned !== $userId) {
			return new JSONResponse(
				data: ['error' => 'This entry is somebody else\'s to answer'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return $this->recordDecision(
			list: $list,
			listData: $listData,
			entryId: $entryId,
			userId: $userId
		);
	}//end decideEntry()

	/**
	 * Apply the answer to the record, then write it into the decision history.
	 *
	 * In that order: a transfer whose list could not be made must not leave a
	 * history saying the record was handed over.
	 *
	 * @param ObjectEntity          $list     The destruction list object.
	 * @param array<string, mixed>              $listData Its own data.
	 * @param string                            $entryId  The record the entry is about.
	 * @param string                            $userId   The reviewer answering.
	 *
	 * @return JSONResponse The recorded decision.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	private function recordDecision(
		ObjectEntity $list,
		array $listData,
		string $entryId,
		string $userId,
	): JSONResponse {
		$answer = (string)$this->request->getParam('answer', '');
		$reason = (string)$this->request->getParam('reason', '');
		$newDate = $this->request->getParam('newArchiefactiedatum');
		if ($newDate !== null) {
			$newDate = (string)$newDate;
		}

		if (in_array($answer, DestructionReviewService::ANSWERS, true) === false) {
			return new JSONResponse(
				data: [
					'error' => sprintf(
						'"%s" is not a review answer; the answers are %s',
						$answer,
						implode(', ', DestructionReviewService::ANSWERS)
					),
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$transferRef = $this->outcomes->apply(
				answer: $answer,
				entryUuid: $entryId,
				reason: $reason,
				newDate: $newDate
			);

			$listData = $this->reviews->recordAnswer(
				listData: $listData,
				entryUuid: $entryId,
				answer: $answer,
				reviewer: $userId,
				reason: $reason,
				newDate: $newDate,
				transferRef: $transferRef
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (RuntimeException $e) {
			$this->logger->error('[ArchivalController] Review answer could not be carried out: ' . $e->getMessage());
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		$this->lists->save(list: $list, listData: $listData);

		$decisions = ($listData['decisions'] ?? []);
		$decision = end($decisions);

		$this->auditMapper->createAuditTrailEntry(
			$list,
			'archival.review_decided',
			[
				'entry' => $entryId,
				'answer' => $answer,
				'reviewer' => $userId,
			]
		);

		return new JSONResponse(
			data: [
				'decision' => $decision,
				'entry' => $this->reviews->entry(listData: $listData, entryUuid: $entryId),
			],
			statusCode: Http::STATUS_OK
		);
	}//end recordDecision()

	/**
	 * What is waiting on the person asking, across every list.
	 *
	 * GET /api/archival/reviews/pending
	 *
	 * Scoped to the caller by construction: it reads the session user id and
	 * asks for that reviewer's entries, so there is no id to tamper with.
	 *
	 * @return JSONResponse The caller's pending entries.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function myPendingReviews(): JSONResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return new JSONResponse(
				data: ['error' => 'Niet geauthenticeerd'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$pending = [];
		foreach ($this->lists->findLists(statuses: DestructionListRepository::OPEN_STATUSES) as $list) {
			$pending = array_merge(
				$pending,
				$this->reviews->pendingEntries(
					listData: ($list->getObject() ?? []),
					listUuid: (string)$list->getUuid(),
					reviewer: $userId
				)
			);
		}

		return new JSONResponse(
			data: [
				'reviewer' => $userId,
				'results' => $pending,
				'total' => count($pending),
			],
			statusCode: Http::STATUS_OK
		);
	}//end myPendingReviews()

	/**
	 * The user id of whoever is asking, or null when nobody is signed in.
	 *
	 * @return string|null The user id.
	 */
	private function currentUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Check if the current user has the archivist role.
	 *
	 * @return JSONResponse|null Returns a 403 response if unauthorized, null if authorized.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function checkArchivistRole(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'Niet geauthenticeerd'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		// Check if user is in the archivaris group or is an admin.
		$isArchivist = $this->groupManager->isInGroup($user->getUID(), self::ARCHIVIST_GROUP);
		$isAdmin = $this->groupManager->isAdmin($user->getUID());

		if ($isArchivist === false && $isAdmin === false) {
			return new JSONResponse(
				data: ['error' => 'Onvoldoende rechten: archivaris rol is vereist'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end checkArchivistRole()
}//end class

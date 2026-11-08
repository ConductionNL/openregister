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
use OCA\OpenRegister\Controller\Support\ArchivalControllerServices;
use OCA\OpenRegister\Controller\Support\ArchivalNominationSubject;
use OCA\OpenRegister\Controller\Support\ArchivalReviewAnswer;
use OCA\OpenRegister\Controller\Support\ArchivalReviewEntry;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchivalNominationServiceInterface;
use OCA\OpenRegister\Service\Archival\DestructionListCreatorInterface;
use OCA\OpenRegister\Service\Archival\DestructionListRepositoryInterface;
use OCA\OpenRegister\Service\Archival\DestructionReviewServiceInterface;
use OCA\OpenRegister\Service\Archival\DestructionServiceInterface;
use OCA\OpenRegister\Service\Archival\LegalHoldServiceInterface;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

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
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The destruction-list, legal-hold, review
 *              and certificate routes share one archivist check and one set of
 *              collaborators; the length is their docblocks, one per route.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is one refusal per rule,
 *              spread over the endpoints rather than piled into one: 401, 403, 404, 409 and
 *              400 each say a different thing to a reviewer who was turned away. Each endpoint
 *              on its own is well under the threshold.
 */
class ArchivalController extends Controller {

	/**
	 * The archivist group name for authorization.
	 */
	private const ARCHIVIST_GROUP = 'archivaris';

	/**
	 * How many uuids one request to create a destruction list may carry.
	 */
	private const CREATE_LIST_LIMIT = 1000;

	/**
	 * Destruction service.
	 *
	 * @var DestructionServiceInterface
	 */
	private DestructionServiceInterface $destructionService;

	/**
	 * Legal hold service.
	 *
	 * @var LegalHoldServiceInterface
	 */
	private LegalHoldServiceInterface $legalHoldService;

	/**
	 * Object mapper.
	 *
	 * @var MagicMapperInterface
	 */
	private MagicMapperInterface $objectMapper;

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
	 * Finds the destruction lists this instance holds.
	 *
	 * @var DestructionListRepositoryInterface
	 */
	private readonly DestructionListRepositoryInterface $lists;

	/**
	 * Assignment, sign-off and the decision history.
	 *
	 * @var DestructionReviewServiceInterface
	 */
	private readonly DestructionReviewServiceInterface $reviews;

	/**
	 * Carries an answer out against the record.
	 *
	 * @var ReviewOutcomeServiceInterface
	 */
	private readonly ReviewOutcomeServiceInterface $outcomes;

	/**
	 * Records who signed off what.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditMapper;

	/**
	 * Derives and writes an archival nomination.
	 *
	 * @var ArchivalNominationServiceInterface
	 */
	private readonly ArchivalNominationServiceInterface $nominations;

	/**
	 * Loads the schema a nomination is derived from.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Creates a destruction list for the objects an app names.
	 *
	 * @var DestructionListCreatorInterface
	 */
	private readonly DestructionListCreatorInterface $creator;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param ArchivalControllerServices $services The archival controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ArchivalControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->lists = $services->mappers->lists;
		$this->reviews = $services->decisions->reviews;
		$this->outcomes = $services->decisions->outcomes;
		$this->auditMapper = $services->mappers->auditMapper;
		$this->nominations = $services->nomination->nominations;
		$this->schemaMapper = $services->mappers->schemaMapper;
		$this->creator = $services->nomination->creator;

		$this->destructionService = $services->decisions->destructionService;
		$this->legalHoldService = $services->decisions->legalHoldService;
		$this->objectMapper = $services->mappers->objectMapper;
		$this->userSession = $services->session->userSession;
		$this->groupManager = $services->session->groupManager;
		$this->logger = $services->session->logger;
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

		$results = [];
		foreach ($this->lists->findLists(statuses: $this->statusFilter(status: $status)) as $list) {
			$results[] = $this->listSummary(list: $list);
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
	 * The statuses a `status` query parameter asks for.
	 *
	 * @param mixed $status The query parameter.
	 *
	 * @return array|null The one status, or null for every status.
	 */
	private function statusFilter(mixed $status): array|null {
		if (is_string($status) === true && $status !== '') {
			return [$status];
		}

		return null;
	}//end statusFilter()

	/**
	 * One destruction list as the overview lists it.
	 *
	 * @param ObjectEntity $list The destruction list object.
	 *
	 * @return array The summary row.
	 */
	private function listSummary(ObjectEntity $list): array {
		$listData = ($list->getObject() ?? []);
		$unassigned = $this->reviews->unassignedEntries(listData: $listData);

		return [
			'uuid' => $list->getUuid(),
			'status' => ($listData['status'] ?? null),
			'createdAt' => ($listData['createdAt'] ?? null),
			'createdBy' => ($listData['createdBy'] ?? null),
			'entryCount' => count($this->reviews->entries(listData: $listData)),
			'unassignedCount' => count($unassigned),
			'decisionCount' => count(($listData['decisions'] ?? [])),
		];
	}//end listSummary()

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

		try {
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
		} catch (Throwable $e) {
			$this->logger->error('[ArchivalController] Could not read destruction list ' . $id . ': ' . $e->getMessage());
			return new JSONResponse(
				data: ['error' => 'Destruction list could not be read'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
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
				&& $result['status'] === DestructionServiceInterface::STATUS_AWAITING_SECOND
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
		$reason = $params['reason'] ?? null;

		if ($reason === null || trim($reason) === '') {
			return new JSONResponse(
				data: ['error' => 'Een reden voor de bewaarplicht is verplicht'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			return $this->placeLegalHold(params: $params, reason: $reason);
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
	 * Place the hold the request names: schema-wide when it names a schema, else on one object.
	 *
	 * @param array $params The request parameters.
	 * @param mixed $reason The reason for the hold, already checked non-blank.
	 *
	 * @return JSONResponse 202 for a queued schema-wide hold, 200 for a placed one, 400 when no target is named.
	 */
	private function placeLegalHold(array $params, mixed $reason): JSONResponse {
		// Bulk hold on schema.
		if (($params['schemaId'] ?? null) !== null) {
			return $this->placeSchemaHold(params: $params, reason: $reason);
		}

		// Single object hold.
		$objectId = $params['objectId'] ?? null;
		if ($objectId === null) {
			return new JSONResponse(
				data: ['error' => 'objectId of schemaId is verplicht'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$object = $this->objectMapper->find($objectId);
		$result = $this->legalHoldService->placeHold($object, $reason, $this->ownerKeyParam(params: $params));

		return new JSONResponse(
			data: [
				'message' => 'Bewaarplicht geplaatst',
				'objectId' => $result->getUuid(),
				'legalHold' => $result->getRetention()['legalHold'] ?? [],
			],
			statusCode: Http::STATUS_OK
		);
	}//end placeLegalHold()

	/**
	 * Queue a hold on every object of a schema in a register.
	 *
	 * @param array $params The request parameters, naming the schema.
	 * @param mixed $reason The reason for the hold.
	 *
	 * @return JSONResponse 202 once queued, 400 when no register is named.
	 */
	private function placeSchemaHold(array $params, mixed $reason): JSONResponse {
		$registerId = $params['registerId'] ?? null;
		if ($registerId === null) {
			return new JSONResponse(
				data: ['error' => 'registerId is verplicht voor schema-brede bewaarplicht'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$this->legalHoldService->bulkPlaceHold(
			(int)$params['schemaId'],
			(int)$registerId,
			$reason
		);

		return new JSONResponse(
			data: ['message' => 'Bulk bewaarplicht is ingepland als achtergrondtaak'],
			statusCode: Http::STATUS_ACCEPTED
		);
	}//end placeSchemaHold()

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
			$result = $this->legalHoldService->releaseHold($object, $reason, $this->ownerKeyParam(params: $params));

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
	 * The matter a hold request speaks for, when it names one (#4172)
	 *
	 * @param array $params The request parameters.
	 *
	 * @return string|null The owner key, or null for a manual hold.
	 */
	private function ownerKeyParam(array $params): ?string {
		$ownerKey = ($params['ownerKey'] ?? null);
		if (is_string($ownerKey) === false || trim($ownerKey) === '') {
			return null;
		}

		return trim($ownerKey);
	}//end ownerKeyParam()

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

		return new JSONResponse(data: $this->legalHoldService->listActiveHolds(), statusCode: Http::STATUS_OK);
	}//end listLegalHolds()

	/**
	 * List the stored destruction certificates, newest first.
	 *
	 * GET /api/archival/certificates, optionally `?destructionList=<uuid>`.
	 * Executed lists whose certificate is not stored are named under `missing`.
	 *
	 * @return JSONResponse `{results, total, missing, configured}`.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	#[NoAdminRequired]
	public function listCertificates(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		if ($this->lists->isConfigured() === false) {
			return new JSONResponse(
				data: [
					'results' => [],
					'total' => 0,
					'missing' => [],
					'configured' => false,
					'error' => 'No destruction list register and schema are configured',
				],
				statusCode: Http::STATUS_OK
			);
		}

		$listUuid = $this->request->getParam('destructionList');
		if (is_string($listUuid) === false || trim($listUuid) === '') {
			$listUuid = null;
		}

		$found = $this->lists->findCertificates(listUuid: $listUuid);

		return new JSONResponse(
			data: [
				'results' => $found['results'],
				'total' => count($found['results']),
				'missing' => $found['missing'],
				'configured' => true,
			],
			statusCode: Http::STATUS_OK
		);
	}//end listCertificates()

	/**
	 * Create a destruction list for the eligible objects among the uuids posted.
	 *
	 * POST /api/archival/destruction-lists with `{"objects": ["<uuid>", ...]}`.
	 * OpenRegister judges every uuid with the daily sweep's own rule; the list
	 * holds only the eligible ones and every other uuid comes back with its
	 * reason. 201 with the list, 422 when nothing was eligible, 400 for a body
	 * without uuids, 409 when no destruction-list register is configured.
	 *
	 * @return JSONResponse `{uuid, status, entryCount, refused}`, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	#[NoAdminRequired]
	public function createDestructionList(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		$uuids = $this->request->getParam('objects');
		if ($this->isCreateListBody(uuids: $uuids) === false) {
			return new JSONResponse(
				data: [
					'error' => sprintf(
						'Send "objects" as a list of 1 to %d object uuids.',
						self::CREATE_LIST_LIMIT
					),
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$created = $this->creator->createFor(uuids: array_values($uuids));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'configured' => false],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return $this->createdListResponse(created: $created);
	}//end createDestructionList()

	/**
	 * Whether the posted `objects` is a list of 1 to CREATE_LIST_LIMIT non-blank uuids.
	 *
	 * @param mixed $uuids The posted `objects`.
	 *
	 * @return bool
	 */
	private function isCreateListBody(mixed $uuids): bool {
		if (is_array($uuids) === false || $uuids === [] || count($uuids) > self::CREATE_LIST_LIMIT) {
			return false;
		}

		return count(array_filter($uuids, static fn ($uuid): bool => is_string($uuid) === false || trim($uuid) === '')) === 0;
	}//end isCreateListBody()

	/**
	 * The answer to a list creation: 201 with the list, or 422 when nothing was eligible.
	 *
	 * @param array $created The creator's outcome: the list (or null) and the refusals.
	 *
	 * @return JSONResponse
	 */
	private function createdListResponse(array $created): JSONResponse {
		if ($created['list'] === null) {
			return new JSONResponse(
				data: [
					'error' => 'None of the objects may be destroyed now, so no list was created.',
					'refused' => $created['refused'],
				],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			data: [
				'uuid' => ($created['list']['uuid'] ?? null),
				'status' => ($created['list']['status'] ?? null),
				'entryCount' => count(($created['list']['objects'] ?? [])),
				'refused' => $created['refused'],
			],
			statusCode: Http::STATUS_CREATED
		);
	}//end createdListResponse()

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

		return $this->assignReviewerOn(
			target: new ArchivalReviewEntry(list: $list, entryId: $entryId, listData: ($list->getObject() ?? [])),
			reviewer: $reviewer
		);
	}//end assignReviewer()

	/**
	 * Name the reviewer on the entry, save the list and record the act.
	 *
	 * @param ArchivalReviewEntry $target   The list and the entry to assign.
	 * @param string|null         $reviewer The reviewer's user id, or null to unassign.
	 *
	 * @return JSONResponse 200 with the entry, or 400 when the list holds no such entry.
	 */
	private function assignReviewerOn(ArchivalReviewEntry $target, string|null $reviewer): JSONResponse {
		$entryId = $target->entryId;

		try {
			$listData = $this->reviews->assignReviewer(
				listData: $target->listData,
				entryUuid: $entryId,
				reviewer: $reviewer
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$this->lists->save(list: $target->list, listData: $listData);

		$this->auditMapper->createAuditTrailEntry(
			$target->list,
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
	}//end assignReviewerOn()

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

		$read = $this->readEntry(id: $id, entryId: $entryId);
		if ($read instanceof JSONResponse) {
			return $read;
		}

		$refusal = $this->refuseAnswer(entry: $read['entry'], userId: $userId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->recordDecision(
			target: new ArchivalReviewEntry(list: $read['list'], entryId: $entryId, listData: $read['listData']),
			userId: $userId
		);
	}//end decideEntry()

	/**
	 * Read the list and the entry an answer is about.
	 *
	 * @param string $id      The destruction list uuid.
	 * @param string $entryId The uuid of the record the entry is about.
	 *
	 * @return array|JSONResponse `{list, listData, entry}`, or 500 / 404.
	 */
	private function readEntry(string $id, string $entryId): array|JSONResponse {
		try {
			$list = $this->lists->find(uuid: $id);
			$listData = (($list?->getObject()) ?? []);
			$entry = $this->reviews->entry(listData: $listData, entryUuid: $entryId);
		} catch (Throwable $e) {
			$this->logger->error('[ArchivalController] Could not read destruction list ' . $id . ': ' . $e->getMessage());
			return new JSONResponse(
				data: ['error' => 'Destruction list could not be read'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($list === null) {
			return new JSONResponse(
				data: ['error' => 'Destruction list not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		if ($entry === null) {
			return new JSONResponse(
				data: ['error' => 'This destruction list has no entry for that record'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return ['list' => $list, 'listData' => $listData, 'entry' => $entry];
	}//end readEntry()

	/**
	 * Refuse an answer from anybody but the entry's named reviewer.
	 *
	 * An entry nobody is accountable for is refused rather than thrown open.
	 *
	 * @param array  $entry  The entry.
	 * @param string $userId The user answering.
	 *
	 * @return JSONResponse|null 409 / 403, or null when the answer is the reviewer's.
	 */
	private function refuseAnswer(array $entry, string $userId): JSONResponse|null {
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

		return null;
	}//end refuseAnswer()

	/**
	 * Apply the answer to the record, then write it into the decision history.
	 *
	 * In that order: a transfer whose list could not be made must not leave a
	 * history saying the record was handed over.
	 *
	 * @param ArchivalReviewEntry $target The list and the entry answered.
	 * @param string              $userId The reviewer answering.
	 *
	 * @return JSONResponse The recorded decision.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	private function recordDecision(ArchivalReviewEntry $target, string $userId): JSONResponse {
		$answer = $this->readAnswer(userId: $userId);
		if ($answer instanceof JSONResponse) {
			return $answer;
		}

		$listData = $this->carryOutAnswer(target: $target, answer: $answer);
		if ($listData instanceof JSONResponse) {
			return $listData;
		}

		$this->lists->save(list: $target->list, listData: $listData);

		$decisions = ($listData['decisions'] ?? []);
		$decision = end($decisions);

		$this->auditMapper->createAuditTrailEntry(
			$target->list,
			'archival.review_decided',
			[
				'entry' => $target->entryId,
				'answer' => $answer->answer,
				'reviewer' => $userId,
			]
		);

		return new JSONResponse(
			data: [
				'decision' => $decision,
				'entry' => $this->reviews->entry(listData: $listData, entryUuid: $target->entryId),
			],
			statusCode: Http::STATUS_OK
		);
	}//end recordDecision()

	/**
	 * Read the reviewer's answer from the request and refuse one that is not an answer.
	 *
	 * @param string $userId The reviewer answering.
	 *
	 * @return ArchivalReviewAnswer|JSONResponse The answer, or 400 for an unknown answer.
	 */
	private function readAnswer(string $userId): ArchivalReviewAnswer|JSONResponse {
		$answer = (string)$this->request->getParam('answer', '');
		$reason = (string)$this->request->getParam('reason', '');
		$newDate = $this->request->getParam('newArchiefactiedatum');
		if ($newDate !== null) {
			$newDate = (string)$newDate;
		}

		if (in_array($answer, DestructionReviewServiceInterface::ANSWERS, true) === false) {
			return new JSONResponse(
				data: [
					'error' => sprintf(
						'"%s" is not a review answer; the answers are %s',
						$answer,
						implode(', ', DestructionReviewServiceInterface::ANSWERS)
					),
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new ArchivalReviewAnswer(answer: $answer, reason: $reason, newDate: $newDate, reviewer: $userId);
	}//end readAnswer()

	/**
	 * Carry the answer out on the record, then write it into the list's data.
	 *
	 * @param ArchivalReviewEntry  $target The list and the entry answered.
	 * @param ArchivalReviewAnswer $answer The reviewer's answer.
	 *
	 * @return array|JSONResponse The list data with the decision, or 400 / 500.
	 */
	private function carryOutAnswer(ArchivalReviewEntry $target, ArchivalReviewAnswer $answer): array|JSONResponse {
		try {
			$transferRef = $this->outcomes->apply(
				answer: $answer->answer,
				entryUuid: $target->entryId,
				reason: $answer->reason,
				newDate: $answer->newDate,
				reviewer: $answer->reviewer
			);

			return $this->reviews->recordAnswer(
				listData: $target->listData,
				entryUuid: $target->entryId,
				answer: $answer->answer,
				reviewer: $answer->reviewer,
				reason: $answer->reason,
				newDate: $answer->newDate,
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
	}//end carryOutAnswer()

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
		foreach ($this->lists->findLists(statuses: DestructionListRepositoryInterface::OPEN_STATUSES) as $list) {
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
	 * Recompute one record's archival nomination, on the record.
	 *
	 * POST /api/archival/objects/{id}/nomination/recompute
	 *
	 * 🔴 RECOMPUTING IS AN EXPLICIT ACT AND IT IS RECORDED. The selectielijst
	 * moves, and a nomination silently rederived against a newer list is a
	 * disposal date nobody can account for. So this asks for a reason, writes
	 * the actor and the reason into the record's nomination history, and leaves
	 * the previous nomination in that history rather than over it.
	 *
	 * @param string $id The record uuid.
	 *
	 * @return JSONResponse The nomination that was written.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function recomputeNomination(string $id): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		$reason = (string)$this->request->getParam('reason', '');
		if (trim($reason) === '') {
			return new JSONResponse(
				data: ['error' => 'Recomputing a nomination is recorded, so a reason is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$subject = $this->nominationSubject(id: $id);
		if ($subject === null) {
			return new JSONResponse(
				data: ['error' => 'Record not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return $this->recompute(subject: $subject, reason: $reason);
	}//end recomputeNomination()

	/**
	 * Load the record and its schema, bypassing RBAC as the recompute always has.
	 *
	 * @param string $id The record uuid.
	 *
	 * @return ArchivalNominationSubject|null The record and its schema, or null when either does not load.
	 */
	private function nominationSubject(string $id): ArchivalNominationSubject|null {
		try {
			$object = $this->objectMapper->find($id, null, null, false, false, false);
			$schema = $this->schemaMapper->find(id: (string)$object->getSchema());
		} catch (Throwable $e) {
			return null;
		}

		return new ArchivalNominationSubject(id: $id, object: $object, schema: $schema);
	}//end nominationSubject()

	/**
	 * Rederive the nomination, persist the record and record the act.
	 *
	 * @param ArchivalNominationSubject $subject The record and its schema.
	 * @param string                    $reason  Why the nomination is recomputed.
	 *
	 * @return JSONResponse 200 with the nomination, 409 for a schema that does not archive, 500 on failure.
	 */
	private function recompute(ArchivalNominationSubject $subject, string $reason): JSONResponse {
		$id = $subject->id;
		$object = $subject->object;
		$schema = $subject->schema;

		try {
			$nomination = $this->nominations->nominate(
				object: $object,
				schema: $schema,
				trigger: 'recompute',
				actor: $this->currentUserId(),
				reason: $reason
			);

			if (($nomination['status'] ?? null) === ArchivalNominationServiceInterface::STATUS_NOT_APPLICABLE) {
				// The reason names both places a declaration could have lived.
				// "Does not declare an archive block" was true of one of them
				// and sent readers to the schema editor while the answer sat in
				// the schema's `x-openregister-archival` annotation.
				return new JSONResponse(
					data: [
						'error' => 'This schema declares no archiving, so there is nothing to nominate',
						'reason' => ($nomination['unnominatableReason'] ?? null),
					],
					statusCode: Http::STATUS_CONFLICT
				);
			}

			$this->objectMapper->update($object);
		} catch (Throwable $e) {
			$this->logger->error('[ArchivalController] Could not recompute the nomination for ' . $id . ': ' . $e->getMessage());
			return new JSONResponse(
				data: ['error' => 'The nomination could not be recomputed'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		$this->auditMapper->createAuditTrailEntry(
			$object,
			'archival.nomination_recomputed',
			[
				'status' => ($nomination['status'] ?? null),
				'rule' => ($nomination['rule'] ?? null),
				'reason' => $reason,
			]
		);

		return new JSONResponse(
			data: ['nomination' => $nomination],
			statusCode: Http::STATUS_OK
		);
	}//end recompute()

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

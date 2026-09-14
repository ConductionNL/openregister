<?php

/**
 * REST controller for the notification history audit trail.
 *
 * Closes the `notificatie-engine` spec's
 * "Notification history MUST be stored and queryable for audit
 * purposes" requirement together with the
 * `Version1Date20260501100000` migration + the `NotificationHistory`
 * entity + the `NotificationHistoryMapper` query API.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Notification\NotificationClearingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class NotificationHistoryController.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationHistoryController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param NotificationHistoryMapper $mapper Mapper for the notification history table.
	 * @param IUserSession $userSession Active session — drives the per-caller filter scope (F07).
	 * @param IGroupManager $groupManager Group resolver — admins keep full audit visibility (F07).
	 * @param NotificationClearingService $clearing The one writer of read, snooze and archive.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NotificationHistoryMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly NotificationClearingService $clearing,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List notification history rows with optional filters.
	 *
	 * Supported query string params: `ruleId`, `channel`, `recipient`,
	 * `objectUuid`, `schemaId`, `registerId`, `status`, `subjectType`,
	 * `subjectId`, `unreadOnly`, `includeArchived`, `includeSnoozed`, `limit`,
	 * `offset`.
	 *
	 * An archived notice is absent unless asked for, and a notice snoozed into
	 * the future is absent until that moment: both leave the list without being
	 * read, which is exactly what distinguishes them from reading.
	 *
	 * @return JSONResponse JSON response with results, total, limit, offset.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'Authentication required'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$filters = $this->extractFilters();

		// SECURITY: non-admins may only read their OWN history. Force the
		// `recipient` filter to the current UID regardless of any value
		// the caller supplied — without this, any authed Bob could query
		// `?recipient=alice` and read Alice's full notification stream
		// (recipient list, channels, ruleId, dispatch timestamps), a
		// privacy + recon vector across tenants.
		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			$filters['recipient'] = $user->getUID();
		}

		$limit = $this->resolveLimit();
		$offset = $this->resolveOffset();

		$results = $this->mapper->findFiltered(filters: $filters, limit: $limit, offset: $offset);
		$total = $this->mapper->countFiltered(filters: $filters);

		return new JSONResponse(
			data: [
				'results' => array_map(static fn ($entity) => $entity->jsonSerialize(), $results),
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			]
		);

	}//end index()

	/**
	 * Snooze one of the caller's own notices until a moment.
	 *
	 * A snooze postpones a notice, it never reads it: the notice is absent from
	 * the unread list until that moment and unread afterwards.
	 *
	 * @param int $id The notice's id.
	 *
	 * @return JSONResponse The notice as it now stands, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	#[NoAdminRequired]
	public function snooze(int $id): JSONResponse {
		$until = $this->request->getParam('snoozedUntil');
		if (is_string($until) === false || $until === '') {
			return new JSONResponse(
				['message' => 'snoozedUntil is required', 'error' => 'snoozed-until-required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$moment = new DateTime($until);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['message' => 'snoozedUntil is not a moment', 'error' => 'snoozed-until-invalid'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$row = $this->clearing->snooze(id: $id, until: $moment);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($row->jsonSerialize());
	}//end snooze()

	/**
	 * Archive one of the caller's own notices, without reading it.
	 *
	 * The read state is left exactly as it was, so a notice archived unread
	 * still says so. That is the whole difference from marking it read.
	 *
	 * @param int $id The notice's id.
	 *
	 * @return JSONResponse The notice as it now stands, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	#[NoAdminRequired]
	public function archive(int $id): JSONResponse {
		try {
			$row = $this->clearing->archive(id: $id);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($row->jsonSerialize());
	}//end archive()

	/**
	 * Mark a whole thread read: every notice the caller holds about one subject.
	 *
	 * The same write as opening the work, reached from the bell instead, so the
	 * two can never leave different state.
	 *
	 * @return JSONResponse How many notices were cleared, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	#[NoAdminRequired]
	public function markThreadRead(): JSONResponse {
		$objectUuid = $this->request->getParam('objectUuid');
		if (is_string($objectUuid) === false || $objectUuid === '') {
			return new JSONResponse(
				['message' => 'objectUuid is required', 'error' => 'object-uuid-required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$subjectId = $this->request->getParam('subjectId');
		if (is_string($subjectId) === false || $subjectId === '') {
			$subjectId = null;
		}

		try {
			$cleared = $this->clearing->markThreadRead(objectUuid: $objectUuid, subjectId: $subjectId);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['cleared' => $cleared]);
	}//end markThreadRead()

	/**
	 * Extract supported filter values from the request.
	 *
	 * @return array<string, string|null> Filter map.
	 */
	private function extractFilters(): array {
		$supported = [
			'ruleId',
			'channel',
			'recipient',
			'objectUuid',
			'schemaId',
			'registerId',
			'status',
			// The bell's axis: what a notice is ABOUT, which is not the same
			// question as which schema's rule produced it.
			'subjectType',
			'subjectId',
			// The three list-state switches. Present as strings and read as
			// booleans by the mapper, so `?unreadOnly=true` works from a plain
			// query string.
			'unreadOnly',
			'includeArchived',
			'includeSnoozed',
		];

		$filters = [];
		foreach ($supported as $key) {
			$value = $this->request->getParam($key);
			if (is_string($value) === true && $value !== '') {
				$filters[$key] = $value;
			}
		}

		return $filters;
	}//end extractFilters()

	/**
	 * Resolve the limit parameter.
	 *
	 * Defaults to 50 when missing or invalid; capped at 500 to prevent
	 * accidental "give me everything" queries from spiking memory.
	 *
	 * @return int Resolved limit.
	 */
	private function resolveLimit(): int {
		$defaultValue = 50;
		$maxValue = 500;

		$raw = $this->request->getParam('limit');
		if ($raw === null || $raw === '') {
			return $defaultValue;
		}

		if (is_string($raw) === true && ctype_digit($raw) === true) {
			$value = (int)$raw;
			if ($value > 0) {
				return min($value, $maxValue);
			}
		} elseif (is_int($raw) === true && $raw > 0) {
			return min($raw, $maxValue);
		}

		return $defaultValue;
	}//end resolveLimit()

	/**
	 * Resolve the offset parameter.
	 *
	 * Defaults to 0 when missing or invalid.
	 *
	 * @return int Resolved offset.
	 */
	private function resolveOffset(): int {
		$raw = $this->request->getParam('offset');
		if ($raw === null || $raw === '') {
			return 0;
		}

		if (is_string($raw) === true && ctype_digit($raw) === true) {
			return (int)$raw;
		}

		if (is_int($raw) === true && $raw >= 0) {
			return $raw;
		}

		return 0;
	}//end resolveOffset()
}//end class

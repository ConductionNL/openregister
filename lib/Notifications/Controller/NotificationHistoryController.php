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

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use DateTime;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationHistoryControllerServices;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Db\ListQuery;

/**
 * Class NotificationHistoryController.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationHistoryController extends Controller {

	/**
	 * Query parameters the list passes on to the mapper as filters.
	 *
	 * `subjectType`/`subjectId` are the bell's axis: what a notice is ABOUT,
	 * which is not the same question as which schema's rule produced it.
	 * `eventId` reads one firing's transports together: the in-app notice and
	 * the outbound call that came from the same event share it, which is the
	 * only way to ask "what else happened when this fired". The last three are
	 * the list-state switches, present as strings and read as booleans by the
	 * mapper, so `?unreadOnly=true` works from a plain query string.
	 */
	private const FILTER_KEYS = [
		'ruleId',
		'channel',
		'recipient',
		'objectUuid',
		'schemaId',
		'registerId',
		'status',
		'subjectType',
		'subjectId',
		'eventId',
		'unreadOnly',
		'includeArchived',
		'includeSnoozed',
	];

	/**
	 * Page size when none or an invalid one is asked for.
	 */
	private const DEFAULT_LIMIT = 50;

	/**
	 * Largest page size, so "give me everything" cannot spike memory.
	 */
	private const MAX_LIMIT = 500;
	/**
	 * Mapper for the notification history table.
	 *
	 * @var NotificationHistoryMapper
	 */
	private readonly NotificationHistoryMapper $mapper;

	/**
	 * Active session, drives the per-caller filter scope (F07).
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group resolver, admins keep full audit visibility (F07).
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The one writer of read, snooze and archive.
	 *
	 * @var NotificationClearingService
	 */
	private readonly NotificationClearingService $clearing;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param NotificationHistoryControllerServices $services The controller's services.
	 */
	public function __construct(
		IRequest $request,
		NotificationHistoryControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->mapper = $services->mapper;
		$this->userSession = $services->userSession;
		$this->groupManager = $services->groupManager;
		$this->clearing = $services->clearing;
	}//end __construct()

	/**
	 * List notification history rows with optional filters.
	 *
	 * Supported query string params: `ruleId`, `channel`, `recipient`,
	 * `objectUuid`, `schemaId`, `registerId`, `status`, `subjectType`,
	 * `subjectId`, `eventId`, `unreadOnly`, `includeArchived`,
	 * `includeSnoozed`, `limit`, `offset`.
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

		return $this->historyPage(filters: $this->visibleFilters(uid: $user->getUID()));
	}//end index()

	/**
	 * The query's filters, with the recipient forced to the caller unless they are an admin.
	 *
	 * @param string $uid The signed-in caller's uid.
	 *
	 * @return array<string, mixed>
	 */
	private function visibleFilters(string $uid): array {
		$filters = $this->extractFilters();

		// SECURITY: non-admins may only read their OWN history. Force the
		// `recipient` filter to the current UID regardless of any value
		// the caller supplied — without this, any authed Bob could query
		// `?recipient=alice` and read Alice's full notification stream
		// (recipient list, channels, ruleId, dispatch timestamps), a
		// privacy + recon vector across tenants.
		if ($this->groupManager->isAdmin($uid) === false) {
			$filters['recipient'] = $uid;
		}

		return $filters;
	}//end visibleFilters()

	/**
	 * One page of the history the filters select, with the total.
	 *
	 * @param array<string, mixed> $filters The filters, recipient already scoped.
	 *
	 * @return JSONResponse JSON response with results, total, limit, offset.
	 */
	private function historyPage(array $filters): JSONResponse {
		$limit = $this->resolveLimit();
		$offset = $this->resolveOffset();

		$results = $this->mapper->findFiltered(query: new ListQuery(limit: $limit, offset: $offset, filters: $filters));
		$total = $this->mapper->countFiltered(filters: $filters);

		return new JSONResponse(
			data: [
				'results' => array_map(static fn ($entity) => $entity->jsonSerialize(), $results),
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			]
		);
	}//end historyPage()

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	#[NoAdminRequired]
	public function snooze(int $id): JSONResponse {
		$moment = $this->snoozeMoment();
		if ($moment instanceof JSONResponse) {
			return $moment;
		}

		try {
			$row = $this->clearing->snooze(id: $id, until: $moment);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($row->jsonSerialize());
	}//end snooze()

	/**
	 * The `snoozedUntil` moment, or the 400 response refusing it.
	 *
	 * @return DateTime|JSONResponse
	 */
	private function snoozeMoment(): DateTime|JSONResponse {
		$until = $this->stringParam(name: 'snoozedUntil');
		if ($until === null) {
			return $this->badRequest(message: 'snoozedUntil is required', error: 'snoozed-until-required');
		}

		try {
			return new DateTime($until);
		} catch (\Throwable $e) {
			return $this->badRequest(message: 'snoozedUntil is not a moment', error: 'snoozed-until-invalid');
		}
	}//end snoozeMoment()

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	#[NoAdminRequired]
	public function markThreadRead(): JSONResponse {
		$objectUuid = $this->stringParam(name: 'objectUuid');
		if ($objectUuid === null) {
			return $this->badRequest(message: 'objectUuid is required', error: 'object-uuid-required');
		}

		try {
			$cleared = $this->clearing->markThreadRead(objectUuid: $objectUuid, subjectId: $this->stringParam(name: 'subjectId'));
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['cleared' => $cleared]);
	}//end markThreadRead()

	/**
	 * A 400 response with a message and an error code.
	 *
	 * @param string $message The message.
	 * @param string $error   The error code.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $message, string $error): JSONResponse {
		return new JSONResponse(['message' => $message, 'error' => $error], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * A request parameter that is a non-empty string, null otherwise.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null
	 */
	private function stringParam(string $name): ?string {
		return ($this->stringParams(names: [$name])[$name] ?? null);
	}//end stringParam()

	/**
	 * The request parameters among `$names` that are non-empty strings, keyed by name.
	 *
	 * @param array<int, string> $names The parameter names, in the order they are read.
	 *
	 * @return array<string, string>
	 */
	private function stringParams(array $names): array {
		$values = [];
		foreach ($names as $name) {
			$value = $this->request->getParam($name);
			if (is_string($value) === true && $value !== '') {
				$values[$name] = $value;
			}
		}

		return $values;
	}//end stringParams()

	/**
	 * A request parameter that is a non-negative integer, given as an int or a
	 * string of digits; null otherwise.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return int|null
	 */
	private function naturalParam(string $name): ?int {
		$raw = $this->request->getParam($name);
		if (is_string($raw) === true && ctype_digit($raw) === true) {
			return (int)$raw;
		}

		if (is_int($raw) === true && $raw >= 0) {
			return $raw;
		}

		return null;
	}//end naturalParam()

	/**
	 * Extract supported filter values from the request.
	 *
	 * @return array<string, string|null> Filter map.
	 */
	private function extractFilters(): array {
		return $this->stringParams(names: self::FILTER_KEYS);
	}//end extractFilters()

	/**
	 * Resolve the limit parameter.
	 *
	 * Defaults to 50 when missing, zero or invalid; capped at 500 to prevent
	 * accidental "give me everything" queries from spiking memory.
	 *
	 * @return int Resolved limit.
	 */
	private function resolveLimit(): int {
		$value = $this->naturalParam(name: 'limit');
		if ($value === null || $value === 0) {
			return self::DEFAULT_LIMIT;
		}

		return min($value, self::MAX_LIMIT);
	}//end resolveLimit()

	/**
	 * Resolve the offset parameter.
	 *
	 * Defaults to 0 when missing or invalid.
	 *
	 * @return int Resolved offset.
	 */
	private function resolveOffset(): int {
		return ($this->naturalParam(name: 'offset') ?? 0);
	}//end resolveOffset()
}//end class

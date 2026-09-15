<?php

/**
 * NotificationBroadcastController.
 *
 * The administered message to every user, and the read each person's page makes
 * to find out whether one is showing for them.
 *
 *   GET    /api/notification-broadcasts            → every broadcast, with how
 *                                                    many people have seen it.
 *                                                    Administrators only.
 *   POST   /api/notification-broadcasts            → send one. Administrators
 *                                                    only, recorded with the
 *                                                    sender.
 *   DELETE /api/notification-broadcasts/{uuid}     → withdraw one.
 *   GET    /api/notification-broadcasts/active     → what is showing for the
 *                                                    caller and not yet seen.
 *   POST   /api/notification-broadcasts/{uuid}/acknowledge
 *                                                  → the caller has seen it;
 *                                                    it does not come back.
 *
 * Reading the active list and acknowledging are open to any signed-in user,
 * because both act only on the caller's own receipt. Everything that writes or
 * reads the whole record requires an administrator: sending one message to
 * every account is the loudest act the system has.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Service\Notification\NotificationBroadcastService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class NotificationBroadcastController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param NotificationBroadcastService $broadcastService The broadcast store and its receipts.
	 * @param IGroupManager $groupManager The authority on who is an administrator.
	 * @param IUserSession $userSession Current-user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NotificationBroadcastService $broadcastService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every broadcast, newest first, with how far each got.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function index(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$limit = $this->intParam(name: 'limit');
		$offset = $this->intParam(name: 'offset');
		$rows = $this->broadcastService->listAll(limit: $limit, offset: $offset);

		return new JSONResponse(data: ['results' => $rows, 'total' => count($rows)]);
	}//end index()

	/**
	 * Send one broadcast.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function create(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$params = $this->request->getParams();
		$subject = $this->nonEmptyString(value: ($params['subject'] ?? null));
		if ($subject === null) {
			return new JSONResponse(data: ['error' => 'A "subject" is required'], statusCode: 422);
		}

		$body = null;
		if (is_string(($params['body'] ?? null)) === true && $params['body'] !== '') {
			$body = (string)$params['body'];
		}

		$startsAt = $this->parseMoment(value: ($params['startsAt'] ?? null), fallback: new DateTime());
		$endsAt = $this->parseMoment(value: ($params['endsAt'] ?? null), fallback: null);
		if ($startsAt === false || $endsAt === false) {
			return new JSONResponse(
				data: ['error' => 'Both "startsAt" and "endsAt" must be readable moments'],
				statusCode: 422
			);
		}

		if ($endsAt === null) {
			return new JSONResponse(data: ['error' => 'An "endsAt" is required'], statusCode: 422);
		}

		try {
			$broadcast = $this->broadcastService->send(
				subject: $subject,
				body: $body,
				sender: (string)$this->resolveUserId(),
				startsAt: $startsAt,
				endsAt: $endsAt
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		return new JSONResponse(data: $broadcast->jsonSerialize(), statusCode: 201);
	}//end create()

	/**
	 * Withdraw one broadcast.
	 *
	 * @param string $uuid The broadcast's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function destroy(string $uuid): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		if ($this->broadcastService->withdraw(broadcastUuid: $uuid) === false) {
			return new JSONResponse(data: ['error' => 'Unknown broadcast'], statusCode: 404);
		}

		return new JSONResponse(data: ['uuid' => $uuid, 'withdrawn' => true]);
	}//end destroy()

	/**
	 * What is showing for the caller, and not yet seen by them.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	#[NoAdminRequired]
	public function active(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$rows = [];
		foreach ($this->broadcastService->activeFor(userId: $userId) as $broadcast) {
			$rows[] = $broadcast->jsonSerialize();
		}

		return new JSONResponse(data: ['results' => $rows, 'total' => count($rows)]);
	}//end active()

	/**
	 * The caller has seen this broadcast; it does not come back to them.
	 *
	 * @param string $uuid The broadcast's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	#[NoAdminRequired]
	public function acknowledge(string $uuid): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		// Scoped to the caller's own uid, never a uid from the request: a
		// receipt is a statement about the person making it.
		if ($this->broadcastService->acknowledge(broadcastUuid: $uuid, userId: $userId) === false) {
			return new JSONResponse(data: ['error' => 'Unknown broadcast'], statusCode: 404);
		}

		return new JSONResponse(data: ['uuid' => $uuid, 'acknowledged' => true]);
	}//end acknowledge()

	/**
	 * Refuse a caller who is not signed in, or not an administrator.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function requireAdmin(): ?JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groupManager->isAdmin($userId) === false) {
			return new JSONResponse(data: ['error' => 'Administrator required'], statusCode: 403);
		}

		return null;
	}//end requireAdmin()

	/**
	 * Read a moment from the request.
	 *
	 * @param mixed $value The raw value.
	 * @param DateTime|null $fallback What an absent value means.
	 *
	 * @return DateTime|false|null The moment, the fallback, or false when unreadable.
	 */
	private function parseMoment(mixed $value, ?DateTime $fallback): DateTime|false|null {
		if (is_string($value) === false || $value === '') {
			return $fallback;
		}

		try {
			return new DateTime($value);
		} catch (\Throwable $e) {
			return false;
		}
	}//end parseMoment()

	/**
	 * Read a positive integer query parameter, or null.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return int|null The value.
	 */
	private function intParam(string $name): ?int {
		$raw = $this->request->getParam($name);
		if (is_string($raw) === false || ctype_digit($raw) === false) {
			return null;
		}

		return (int)$raw;
	}//end intParam()

	/**
	 * Resolve the current user's UID, or null when anonymous.
	 *
	 * @return string|null
	 */
	private function resolveUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end resolveUserId()

	/**
	 * Coerce a request value to a non-empty string, or null.
	 *
	 * @param mixed $value Input.
	 *
	 * @return string|null
	 */
	private function nonEmptyString(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyString()
}//end class

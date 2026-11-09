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

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use DateTime;
use OCA\OpenRegister\Notifications\Db\BroadcastMessage;
use OCA\OpenRegister\Notifications\Db\BroadcastWindow;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationBroadcastService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait;

class NotificationBroadcastController extends Controller {
	use NotificationRequestTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param NotificationBroadcastService $broadcastService The broadcast store and its receipts.
	 * @param IGroupManager $groupManager The authority on who is an administrator.
	 * @param IUserSession $userSession Current-user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly NotificationBroadcastService $broadcastService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every broadcast, newest first, with how far each got.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 *
	 * @NoCSRFRequired
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 *
	 * @NoCSRFRequired
	 */
	public function create(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->createFrom(params: $this->request->getParams());
	}//end create()

	/**
	 * Read a broadcast from the payload and send it, or refuse the payload.
	 *
	 * @param array<string, mixed> $params The request payload.
	 *
	 * @return JSONResponse The created broadcast, or a 422.
	 */
	private function createFrom(array $params): JSONResponse {
		$subject = $this->stringParam(params: $params, key: 'subject');
		if ($subject === null) {
			return new JSONResponse(data: ['error' => 'A "subject" is required'], statusCode: 422);
		}

		$window = $this->requestedWindow(startsAt: ($params['startsAt'] ?? null), endsAt: ($params['endsAt'] ?? null));
		if ($window instanceof JSONResponse) {
			return $window;
		}

		return $this->sendBroadcast(subject: $subject, body: $this->stringParam(params: $params, key: 'body'), window: $window);
	}//end createFrom()

	/**
	 * The moments a broadcast runs between, or the 422 that refuses them.
	 *
	 * `startsAt` defaults to now; `endsAt` is required. Both must be readable.
	 *
	 * @param mixed $startsAt The raw `startsAt`.
	 * @param mixed $endsAt   The raw `endsAt`.
	 *
	 * @return array{0: DateTime, 1: DateTime}|JSONResponse The start and end, or the refusal.
	 */
	private function requestedWindow(mixed $startsAt, mixed $endsAt): array|JSONResponse {
		$startsAt = $this->parseMoment(value: $startsAt, fallback: new DateTime());
		$endsAt = $this->parseMoment(value: $endsAt, fallback: null);
		if ($startsAt === false || $endsAt === false) {
			return new JSONResponse(data: ['error' => 'Both "startsAt" and "endsAt" must be readable moments'], statusCode: 422);
		}

		if ($endsAt === null) {
			return new JSONResponse(data: ['error' => 'An "endsAt" is required'], statusCode: 422);
		}

		return [$startsAt, $endsAt];
	}//end requestedWindow()

	/**
	 * Send the broadcast as the current administrator.
	 *
	 * @param string                           $subject The subject.
	 * @param string|null                      $body    The body, or null.
	 * @param array{0: DateTime, 1: DateTime} $window  The start and end.
	 *
	 * @return JSONResponse The created broadcast, or a 422 when the service refuses it.
	 */
	private function sendBroadcast(string $subject, ?string $body, array $window): JSONResponse {
		try {
			$broadcast = $this->broadcastService->send(
				message: new BroadcastMessage(subject: $subject, body: $body),
				sender: (string)$this->resolveUserId(),
				window: new BroadcastWindow(startsAt: $window[0], endsAt: $window[1])
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		return new JSONResponse(data: $broadcast->jsonSerialize(), statusCode: 201);
	}//end sendBroadcast()

	/**
	 * Withdraw one broadcast.
	 *
	 * @param string $uuid The broadcast's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 *
	 * @NoCSRFRequired
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function active(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function acknowledge(string $uuid): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
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
			return $this->authenticationRequired();
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
}//end class

<?php

/**
 * NotificationDeliveryWindowController.
 *
 * REST surface for the per-user, override-only delivery-window (quiet
 * hours) preference — distinct from the per-(schema, notification) on/off
 * override served by `NotificationPreferencesController`. Follows the same
 * auth pattern: `#[NoAdminRequired]`, resolves the current user from
 * `IUserSession`, 401 when unauthenticated, strictly scoped to the
 * authenticated user (no uid parameter is ever accepted from the request).
 *
 *   GET /api/notification-delivery-window
 *       → the current user's stored window, or `{enabled: false}` when
 *         none is configured (never a 404/500 — the "no configured
 *         window" backward-compat case).
 *
 *   PUT /api/notification-delivery-window
 *       body: { enabled, start?, end?, timezone?, days? }
 *       → stores (or, with `enabled: false`, clears) the window for the
 *         current user only. Malformed `start`/`end`/`timezone`/`days`
 *         values are rejected with HTTP 422.
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
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait;

class NotificationDeliveryWindowController extends Controller {
	use NotificationRequestTrait;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param NotificationDeliveryWindowService $windowService Override-only window store + evaluator.
	 * @param IUserSession $userSession Current-user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NotificationDeliveryWindowService $windowService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the current user's stored delivery-window preference, or
	 * `{enabled: false}` when none is configured.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$window = $this->windowService->getForUser(userId: $userId);
		if ($window === null) {
			return new JSONResponse(data: ['enabled' => false]);
		}

		return new JSONResponse(data: $window);
	}//end index()

	/**
	 * Store (or, with `enabled: false`, clear) the current user's
	 * delivery-window preference.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function update(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$params = $this->request->getParams();
		if (($params['enabled'] ?? true) === false) {
			$this->windowService->setForUser(userId: $userId, window: null);
			return new JSONResponse(data: ['enabled' => false]);
		}

		return $this->storeWindow(userId: $userId, window: $params);
	}//end update()

	/**
	 * Store the caller's window, then answer with it as stored.
	 *
	 * @param string               $userId The caller.
	 * @param array<string, mixed> $window The window as posted.
	 *
	 * @return JSONResponse The stored window, or a 422 when it is invalid.
	 */
	private function storeWindow(string $userId, array $window): JSONResponse {
		try {
			$this->windowService->setForUser(userId: $userId, window: $window);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'code' => 'notification-delivery-window-invalid'],
				statusCode: 422
			);
		}

		return new JSONResponse(data: ($this->windowService->getForUser(userId: $userId) ?? ['enabled' => false]));
	}//end storeWindow()
}//end class

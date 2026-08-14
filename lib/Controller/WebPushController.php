<?php

/**
 * WebPushController — REST surface for the Web Push channel.
 *
 * Endpoints:
 *   - GET    /webpush/vapid-public-key       — the VAPID public key (browser subscribe key)
 *   - POST   /webpush/subscription           — store the CURRENT user's push subscription
 *   - DELETE /webpush/subscription           — delete the current user's subscription (by endpoint)
 *   - GET    /webpush/icon/{app}             — cached cobalt-hex notification icon PNG
 *   - GET    /webpush/badge/{app}            — cached monochrome notification badge PNG
 *
 * IDOR-safe: the subscription endpoints bind every read/write/delete to the
 * current session uid (`$this->userSession->getUser()->getUID()`) and never
 * accept a target uid from the request — a user can only manage their own
 * subscriptions.
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
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\PushSubscriptionMapper;
use OCA\OpenRegister\Service\WebPush\HexIconService;
use OCA\OpenRegister\Service\WebPush\WebPushService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Web Push subscription + VAPID key + hex-icon REST controller.
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class WebPushController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Active HTTP request.
	 * @param WebPushService $webPushService VAPID key + delivery service.
	 * @param PushSubscriptionMapper $subscriptionMapper Subscription store.
	 * @param HexIconService $hexIconService Hex-icon raster service.
	 * @param IUserSession $userSession Current session (owner binding).
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WebPushService $webPushService,
		private readonly PushSubscriptionMapper $subscriptionMapper,
		private readonly HexIconService $hexIconService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the configured VAPID public key (never the private key).
	 *
	 * @return JSONResponse `{ publicKey: <string>, configured: <bool> }`.
	 *
	 * @no-admin-idor-exempt No per-object resource: returns the public VAPID key and configured flag only; the private key is never exposed.
	 *
	 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function vapidPublicKey(): JSONResponse {
		return new JSONResponse(
			[
				'publicKey' => $this->webPushService->getPublicKey(),
				'configured' => $this->webPushService->isConfigured(),
			]
		);
	}//end vapidPublicKey()

	/**
	 * Store a push subscription for the CURRENT user.
	 *
	 * Body: `{ endpoint, keys: { p256dh, auth } }` (the browser PushSubscription
	 * JSON). The owner is ALWAYS the session user — no uid is read from the body.
	 *
	 * @param string $endpoint The push endpoint URL.
	 * @param array<string,mixed> $keys The client keys (p256dh + auth).
	 *
	 * @return JSONResponse The stored subscription summary, or an error.
	 *
	 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
	 */
	#[NoAdminRequired]
	public function subscribe(string $endpoint = '', array $keys = []): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'No active user session'], Http::STATUS_UNAUTHORIZED);
		}

		$p256dh = (string)($keys['p256dh'] ?? '');
		$auth = (string)($keys['auth'] ?? '');
		if ($endpoint === '' || $p256dh === '' || $auth === '') {
			return new JSONResponse(['error' => 'endpoint, keys.p256dh and keys.auth are required'], Http::STATUS_BAD_REQUEST);
		}

		$subscription = $this->subscriptionMapper->store(
			userId: $user->getUID(),
			endpoint: $endpoint,
			p256dh: $p256dh,
			auth: $auth,
			userAgent: $this->request->getHeader('User-Agent')
		);

		return new JSONResponse($subscription->jsonSerialize(), Http::STATUS_CREATED);
	}//end subscribe()

	/**
	 * Delete the CURRENT user's subscription for the given endpoint.
	 *
	 * @param string $endpoint The push endpoint URL to remove.
	 *
	 * @return JSONResponse `{ deleted: <int> }`, or an error.
	 *
	 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
	 */
	#[NoAdminRequired]
	public function unsubscribe(string $endpoint = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'No active user session'], Http::STATUS_UNAUTHORIZED);
		}

		if ($endpoint === '') {
			return new JSONResponse(['error' => 'endpoint is required'], Http::STATUS_BAD_REQUEST);
		}

		// Owner-scoped delete: keyed by the session uid, never a wire uid.
		$deleted = $this->subscriptionMapper->deleteByUserAndEndpoint(
			userId: $user->getUID(),
			endpoint: $endpoint
		);

		return new JSONResponse(['deleted' => $deleted]);
	}//end unsubscribe()

	/**
	 * Serve the cached cobalt-hex notification icon PNG for an app.
	 *
	 * Public: the icon is referenced by the Service Worker / OS notification
	 * surface which has no Nextcloud session; it leaks no user data (just an
	 * app glyph on a hexagon).
	 *
	 * @param string $app The originApp id.
	 *
	 * @return DataDisplayResponse The image bytes.
	 *
	 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function hexIcon(string $app): DataDisplayResponse {
		$icon = $this->hexIconService->getIcon($app);
		$response = new DataDisplayResponse($icon['body'], Http::STATUS_OK, ['Content-Type' => $icon['mime']]);
		$response->cacheFor(86400);
		return $response;
	}//end hexIcon()

	/**
	 * Serve the cached monochrome notification badge PNG for an app.
	 *
	 * @param string $app The originApp id.
	 *
	 * @return DataDisplayResponse The image bytes.
	 *
	 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function hexBadge(string $app): DataDisplayResponse {
		$badge = $this->hexIconService->getBadge($app);
		$response = new DataDisplayResponse($badge['body'], Http::STATUS_OK, ['Content-Type' => $badge['mime']]);
		$response->cacheFor(86400);
		return $response;
	}//end hexBadge()
}//end class

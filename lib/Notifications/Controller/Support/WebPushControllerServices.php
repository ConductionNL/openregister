<?php

/**
 * The services of the web-push API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Notifications\Db\PushSubscriptionMapper;
use OCA\OpenRegister\Notifications\Service\WebPush\HexIconService;
use OCA\OpenRegister\Notifications\Service\WebPush\WebPushService;
use OCP\IUserSession;

/**
 * The services of the web-push API.
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */
final readonly class WebPushControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param WebPushService         $webPushService     The web-push service.
	 * @param PushSubscriptionMapper $subscriptionMapper Stores the browser push subscriptions.
	 * @param HexIconService         $hexIconService     Renders the notification icons.
	 * @param IUserSession           $userSession        The active session, owner of a subscription.
	 */
	public function __construct(
		public WebPushService $webPushService,
		public PushSubscriptionMapper $subscriptionMapper,
		public HexIconService $hexIconService,
		public IUserSession $userSession,
	) {
	}//end __construct()
}//end class

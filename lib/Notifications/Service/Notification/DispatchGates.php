<?php

/**
 * The gates a recipient delivery passes before it is sent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper;

/**
 * The per-recipient gates of the declarative dispatcher: throttling, the
 * recipient's own preference, the legacy subscription filter and the
 * quiet-hours / digest hold. Each gate is independently sufficient to stop
 * or hold a delivery; a null gate lets everything through, as before.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class DispatchGates {
	/**
	 * Constructor.
	 *
	 * @param DispatchThrottle                $throttle Per-rule, per-recipient rate limit and burst coalescing.
	 * @param DeliveryHold                    $deliveryHold The quiet-hours and digest-schedule hold.
	 * @param ?NotificationPreferenceService  $preferenceService Override-only preference resolver.
	 * @param ?NotificationSubscriptionMapper $subscriptionMapper Legacy subscription filter (DEPRECATED).
	 */
	public function __construct(
		public DispatchThrottle $throttle,
		public DeliveryHold $deliveryHold,
		public ?NotificationPreferenceService $preferenceService = null,
		public ?NotificationSubscriptionMapper $subscriptionMapper = null,
	) {
	}//end __construct()
}//end class

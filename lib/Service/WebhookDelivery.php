<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Webhook;

/**
 * One delivery of an event to one webhook: which attempt it is, and the optional
 * hard cap on its timeout that request-blocking interception deliveries set.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhookDelivery {
	/**
	 * Build the delivery.
	 *
	 * @param Webhook      $webhook           Webhook configuration.
	 * @param WebhookEvent $event             The event delivered.
	 * @param int          $attempt           Current attempt number (for retries).
	 * @param int|null     $timeoutCapSeconds Hard cap on connect + total timeout; null keeps the webhook's own.
	 */
	public function __construct(
		public readonly Webhook $webhook,
		public readonly WebhookEvent $event,
		public readonly int $attempt = 1,
		public readonly ?int $timeoutCapSeconds = null,
	) {
	}//end __construct()
}//end class

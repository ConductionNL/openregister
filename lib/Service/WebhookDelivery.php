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
 * One delivery of an event to one webhook: which attempt it is, the optional
 * hard cap on its timeout that request-blocking interception deliveries set, and,
 * for a webhook that delivers per target, the URL this delivery goes to.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhookDelivery {
	/**
	 * The webhook configuration key that makes a webhook deliver per target: every
	 * delivery names its own URL, and the webhook's own `url` is never posted to.
	 */
	public const PER_TARGET_KEY = 'deliverPerTarget';

	/**
	 * Build the delivery.
	 *
	 * @param Webhook      $webhook           Webhook configuration.
	 * @param WebhookEvent $event             The event delivered.
	 * @param int          $attempt           Current attempt number (for retries).
	 * @param int|null     $timeoutCapSeconds Hard cap on connect + total timeout; null keeps the webhook's own.
	 * @param string|null  $targetUrl         The URL of a per-target webhook's delivery; null for every other webhook.
	 */
	public function __construct(
		public readonly Webhook $webhook,
		public readonly WebhookEvent $event,
		public readonly int $attempt = 1,
		public readonly ?int $timeoutCapSeconds = null,
		public readonly ?string $targetUrl = null,
	) {
	}//end __construct()

	/**
	 * Whether a webhook delivers per target. An owned webhook never does: its owner
	 * chose its URL, and a caller must not send its events elsewhere.
	 *
	 * @param Webhook $webhook The webhook.
	 *
	 * @return bool True when the configuration opts in and no user owns the webhook.
	 *
	 * @spec openspec/changes/webhook-per-delivery-target/specs/webhook-payload-mapping/spec.md#requirement-a-per-target-webhook-delivers-to-the-url-each-delivery-names
	 */
	public static function deliversPerTarget(Webhook $webhook): bool {
		return ($webhook->getConfigurationArray()[self::PER_TARGET_KEY] ?? false) === true
			&& trim((string)$webhook->getOwner()) === '';
	}//end deliversPerTarget()

	/**
	 * The target URL a redelivery of a logged delivery goes to: the logged URL for a
	 * per-target webhook, null (the webhook's own URL) for every other webhook.
	 *
	 * @param Webhook $webhook   The webhook.
	 * @param string  $loggedUrl The URL on the failed delivery's log entry.
	 *
	 * @return string|null The target URL, or null.
	 *
	 * @spec openspec/changes/webhook-per-delivery-target/specs/webhook-payload-mapping/spec.md#requirement-a-retry-goes-to-the-same-target
	 */
	public static function retryTarget(Webhook $webhook, string $loggedUrl): ?string {
		if (self::deliversPerTarget(webhook: $webhook) === false || trim($loggedUrl) === '') {
			return null;
		}

		return $loggedUrl;
	}//end retryTarget()

	/**
	 * The URL this delivery is sent to.
	 *
	 * A target URL on a webhook that does not deliver per target is ignored, so no
	 * caller can send an ordinary webhook's events to another address.
	 *
	 * @return string The target URL of a per-target delivery, else the webhook's own URL.
	 *
	 * @spec openspec/changes/webhook-per-delivery-target/specs/webhook-payload-mapping/spec.md#requirement-a-per-target-webhook-delivers-to-the-url-each-delivery-names
	 */
	public function url(): string {
		if ($this->targetUrl !== null && self::deliversPerTarget(webhook: $this->webhook) === true) {
			return $this->targetUrl;
		}

		return $this->webhook->getUrl();
	}//end url()
}//end class

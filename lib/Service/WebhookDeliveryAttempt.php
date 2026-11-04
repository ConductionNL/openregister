<?php

/**
 * OpenRegister Webhook Delivery Attempt
 *
 * One delivery of a webhook: the webhook, the log entry it writes and the event payload.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;

/**
 * The state one webhook delivery shares between its steps.
 */
final class WebhookDeliveryAttempt {
	/**
	 * @param Webhook	$webhook The webhook being delivered
	 * @param WebhookLog $log	 The log entry of this attempt (event, attempt number, payload sent)
	 * @param array	  $payload The event payload as dispatched, before the webhook payload is built
	 */
	public function __construct(
		public readonly Webhook $webhook,
		public readonly WebhookLog $log,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class

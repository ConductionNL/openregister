<?php

/**
 * The notification subsystem's per-channel kill switches.
 *
 * One switch per channel, read at send time by the shared channel senders —
 * which is what makes the switch reach BOTH callers by construction: the
 * declarative dispatcher and the flow messaging service invoke the same units,
 * so a silenced channel is silent for a schema annotation and for a flow node
 * alike. There is deliberately NO flow-specific switch here: stopping a
 * sending flow is the per-flow `enabled` flag or the instance flow kill
 * switch, both of which halt the run before any sender is reached.
 *
 * Defaults to enabled and fails open, matching the RateLimiter's posture: a
 * broken config read must never silently silence an instance's notifications.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads the per-channel notification kill switches.
 */
class NotificationChannelPolicy {

	public const APP_ID = 'openregister';

	/**
	 * App-config key template for a channel's kill switch, e.g.
	 * `notification_channel_email_enabled`. Dashes in the channel name are
	 * folded to underscores (`nc-notification` -> `nc_notification`).
	 */
	public const CONFIG_KEY_TEMPLATE = 'notification_channel_%s_enabled';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App-config reader for the switches.
	 * @param LoggerInterface $logger Logger for silenced-send info events.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the given channel may send. Defaults to ON.
	 *
	 * @param string $channel The channel name (`nc-notification`, `email`, `talk`).
	 *
	 * @return bool True when sends on this channel may proceed.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function isChannelEnabled(string $channel): bool {
		$key = sprintf(self::CONFIG_KEY_TEMPLATE, str_replace('-', '_', $channel));

		try {
			$value = $this->appConfig->getValueString(self::APP_ID, $key, 'true');
		} catch (\Throwable $e) {
			// Fail open: a broken config read must never become a silent
			// instance-wide notification outage.
			return true;
		}

		$enabled = ($value !== 'false' && $value !== '0');
		if ($enabled === false) {
			$this->logger->info(
				sprintf('[NotificationChannelPolicy] channel "%s" silenced by kill switch (%s)', $channel, $key)
			);
		}

		return $enabled;
	}//end isChannelEnabled()
}//end class

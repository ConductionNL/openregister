<?php

/**
 * A broadcast as an administrator sends it: the message, who sends it and when it shows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Notifications\Db\BroadcastMessage;
use OCA\OpenRegister\Notifications\Db\BroadcastWindow;

/**
 * One broadcast to send.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */
final class OutgoingBroadcast {
	/**
	 * Hold the values.
	 *
	 * @param BroadcastMessage $message The one-line subject and the longer text, when there is one.
	 * @param string           $sender  The uid of whoever is sending it.
	 * @param BroadcastWindow  $window  When it starts and stops showing.
	 */
	public function __construct(
		public readonly BroadcastMessage $message,
		public readonly string $sender,
		public readonly BroadcastWindow $window,
	) {
	}//end __construct()
}//end class

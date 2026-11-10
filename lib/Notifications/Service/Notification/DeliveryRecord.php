<?php

/**
 * DeliveryRecord: one delivery over one channel to one recipient, and how it went.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * One delivery over one channel to one recipient, and how it went.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DeliveryRecord {

	/**
	 * Constructor.
	 *
	 * @param string         $channel   'nc-notification' | 'email' | 'activity' | 'webhook' | 'talk' | 'outbound',
	 *                                  or the kind of an unresolved recipient.
	 * @param string         $recipient Recipient identifier.
	 * @param string         $status    'dispatched' | 'rate-limited' | 'coalesced' | 'failed' | ...
	 * @param DeliveryDetail $detail    The text sent and why it failed, when it did.
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $recipient,
		public readonly string $status,
		public readonly DeliveryDetail $detail,
	) {
	}//end __construct()
}//end class

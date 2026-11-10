<?php

/**
 * OpenRegister QueueWait
 *
 * How long a queued notification row has waited: from its enqueue instant
 * to the instant the flush evaluates it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-delivery-windows/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;

/**
 * The wait of one queued row, checked against a digest schedule.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class QueueWait {
	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $enqueuedAt When the row was queued.
	 * @param DateTimeImmutable $now        The instant to evaluate from.
	 */
	public function __construct(
		public readonly DateTimeImmutable $enqueuedAt,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class

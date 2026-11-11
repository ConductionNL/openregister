<?php

/**
 * Why and when a recipient delivery is held.
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

use DateTimeImmutable;

/**
 * A held delivery: the QueuedNotification reason and the moment it was decided.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HoldDecision {
	/**
	 * Constructor.
	 *
	 * @param string            $reason A QueuedNotification::REASON_* constant.
	 * @param DateTimeImmutable $at     The moment the hold was decided.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly DateTimeImmutable $at,
	) {
	}//end __construct()
}//end class

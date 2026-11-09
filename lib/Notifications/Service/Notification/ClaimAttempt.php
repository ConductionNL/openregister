<?php

/**
 * One worker's attempt to claim a scheduled message: who claims it, and when.
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
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;

/**
 * A claim attempt on a scheduled message row.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
 */
final class ClaimAttempt {
	/**
	 * Hold the values.
	 *
	 * @param string            $worker Who is claiming.
	 * @param DateTimeImmutable $at     The moment of the claim.
	 */
	public function __construct(
		public readonly string $worker,
		public readonly DateTimeImmutable $at,
	) {
	}//end __construct()
}//end class

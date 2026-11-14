<?php

/**
 * How far a reminder got from the anchor it was armed from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * The anchor fingerprint, the number of reminders sent from it, and the stop reason.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
final class ReminderProgress {

	/**
	 * Constructor.
	 *
	 * @param string      $fingerprint The fingerprint of the anchor value the reminder is armed from.
	 * @param integer     $sent        Reminders sent from that anchor.
	 * @param string|null $stopped     The condition that stopped the reminder, or null while it runs.
	 */
	public function __construct(
		public readonly string $fingerprint,
		public readonly int $sent = 0,
		public readonly ?string $stopped = null,
	) {
	}//end __construct()

	/**
	 * The progress a stored dedupe row holds, or null for none.
	 *
	 * @param NotificationDedupeState|null $state The row.
	 *
	 * @return self|null The progress.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public static function fromState(?NotificationDedupeState $state): ?self {
		if ($state === null) {
			return null;
		}

		return new self(
			fingerprint: (string)$state->getFingerprint(),
			sent: (int)($state->getReminderSent() ?? 0),
			stopped: $state->getReminderStopped()
		);
	}//end fromState()
}//end class

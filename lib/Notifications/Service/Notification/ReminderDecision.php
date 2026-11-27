<?php

/**
 * What a reminder does for one object on one sweep.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Db\ReminderProgress;

/**
 * The verdict of {@see ReminderPlan::decide()}.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
final class ReminderDecision {

	/**
	 * The object holds no date in the anchor property: nothing is armed.
	 */
	public const NO_ANCHOR = 'noAnchor';

	/**
	 * The next reminder is not due yet.
	 */
	public const WAIT = 'wait';

	/**
	 * The next reminder is due: evaluate the stop condition, then send.
	 */
	public const DUE = 'due';

	/**
	 * Every reminder the repeat allows was sent.
	 */
	public const DONE = 'done';

	/**
	 * The reminder stopped on its condition and does not re-arm.
	 */
	public const STOPPED = 'stopped';

	/**
	 * Constructor.
	 *
	 * @param string                 $kind     One of the constants above.
	 * @param ReminderProgress|null  $progress The progress to keep; null with no anchor.
	 * @param boolean                $rearmed  True when the anchor moved and the count started over.
	 * @param DateTimeImmutable|null $dueAt    When the next reminder is due, if one is.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly ?ReminderProgress $progress = null,
		public readonly bool $rearmed = false,
		public readonly ?DateTimeImmutable $dueAt = null,
	) {
	}//end __construct()

	/**
	 * The 1-based sequence number of the reminder this decision sends.
	 *
	 * @return integer The sequence number.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function sequence(): int {
		return (($this->progress->sent ?? 0) + 1);
	}//end sequence()
}//end class

<?php

/**
 * A timer's escalation rungs placed against its fire moment, minus those already fired.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeInterface;

/**
 * The rungs, the fire moment they are offset from, the rung keys already in the
 * ledger and the calendar the offsets are measured in: what the ladder reads to
 * answer which rung is next and which rungs are due.
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */
final class RungTimeline {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $rungs     The normalised rungs.
	 * @param DateTimeInterface                $fireAt    The fire moment.
	 * @param array<int, string>               $firedKeys Rung keys already in the ledger.
	 * @param WorkingCalendar                  $calendar  The resolved calendar.
	 */
	public function __construct(
		public readonly array $rungs,
		public readonly DateTimeInterface $fireAt,
		public readonly array $firedKeys,
		public readonly WorkingCalendar $calendar,
	) {
	}//end __construct()
}//end class

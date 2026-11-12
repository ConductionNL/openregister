<?php

/**
 * The roll a term declares, on the calendar it counts against.
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
 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * Which way a moment that lands on a non-working day moves, and the calendar
 * that decides which days are non-working.
 *
 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
 */
final class RollRule {
	/**
	 * Describe the roll.
	 *
	 * @param string               $roll     One of SlaCalculatorInterface::ROLLS.
	 * @param WorkingCalendar|null $calendar The resolved calendar.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function __construct(
		public readonly string $roll,
		public readonly ?WorkingCalendar $calendar,
	) {
	}//end __construct()
}//end class

<?php

/**
 * One direction to roll a term in, on one working calendar.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The calendar a term rolls on and the day step it rolls by ({@see WorkingDayRoll}).
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */
final class CalendarRoll {
	/**
	 * Constructor.
	 *
	 * @param WorkingCalendar $calendar The calendar deciding what a working day is.
	 * @param string          $modifier The day step: `+1 day` or `-1 day`.
	 */
	public function __construct(
		public readonly WorkingCalendar $calendar,
		public readonly string $modifier,
	) {
	}//end __construct()
}//end class

<?php

/**
 * A timer together with the calendar its deadline is measured on.
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
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\FlowTimer;

/**
 * A timer and its resolved calendar.
 */
final class FlowTimerScope {

	/**
	 * Constructor.
	 *
	 * @param FlowTimer       $timer    The timer.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 */
	public function __construct(
		public readonly FlowTimer $timer,
		public readonly WorkingCalendar $calendar,
	) {
	}//end __construct()
}//end class

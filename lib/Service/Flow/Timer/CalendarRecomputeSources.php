<?php

/**
 * What a calendar recompute asks and computes with: the dependency question,
 * the calendar resolver and the SLA engine.
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
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The calendar collaborators CalendarRecompute recomputes a timer with.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
final class CalendarRecomputeSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param CalendarDependency     $dependency The one dependency question.
	 * @param WorkingCalendarService $calendars  The resolver.
	 * @param SlaCalculatorInterface $calculator The engine.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly CalendarDependency $dependency,
		public readonly WorkingCalendarService $calendars,
		public readonly SlaCalculatorInterface $calculator,
	) {
	}//end __construct()
}//end class

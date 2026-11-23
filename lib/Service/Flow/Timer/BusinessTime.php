<?php
/**
 * BusinessTime: the flow timers' calculator and working calendars behind the Kernel business-time port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\BusinessCalendarInterface;
use OCA\OpenRegister\Kernel\Contracts\BusinessTimeInterface;

/**
 * Serves {@see BusinessTimeInterface} with the timers' own calculator and calendar resolution,
 * so areas outside the flow timers count business time exactly as the timers do.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
final class BusinessTime implements BusinessTimeInterface {

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendarService $calendars  The working calendar resolution.
	 * @param SlaCalculatorInterface $calculator The business-time calculator.
	 */
	public function __construct(
		private readonly WorkingCalendarService $calendars,
		private readonly SlaCalculatorInterface $calculator,
	) {
	}//end __construct()

	/**
	 * The organisation's working calendar, or the default one.
	 *
	 * @param string|null $organisation The organisation.
	 *
	 * @return BusinessCalendarInterface The calendar.
	 */
	public function calendarFor(?string $organisation): BusinessCalendarInterface {
		return $this->calendars->resolve(calendarSlug: null, organisation: $organisation);
	}//end calendarFor()

	/**
	 * Add an amount through the timers' calculator.
	 *
	 * A calendar that is not a timers' working calendar counts as no calendar,
	 * so a business unit then fails as it does without one.
	 *
	 * @param DateTimeImmutable              $start    The start.
	 * @param float                          $value    The amount.
	 * @param string                         $unit     The unit.
	 * @param BusinessCalendarInterface|null $calendar The calendar.
	 *
	 * @return DateTimeImmutable The result.
	 */
	public function add(DateTimeImmutable $start, float $value, string $unit, ?BusinessCalendarInterface $calendar): DateTimeImmutable {
		$working = null;
		if ($calendar instanceof WorkingCalendarInterface) {
			$working = $calendar;
		}

		return $this->calculator->add(addition: new SlaAddition(start: $start, value: $value, unit: $unit, calendar: $working));
	}//end add()
}//end class

<?php

/**
 * Unit tests for a fixed holiday that moves when it falls on a given weekday.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Koningsdag (27 April) moves to the 26th when it falls on a Sunday, and stays put otherwise.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 */
class WorkingCalendarObservedShiftTest extends TestCase {

	/**
	 * A calendar with only Koningsdag and its Sunday shift.
	 *
	 * @return WorkingCalendar
	 */
	private function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(
			[
				'slug'               => 'nl-koningsdag',
				'hoursPerWorkingDay' => 8,
				'workingWeekdays'    => [1, 2, 3, 4, 5],
				'rules'              => [
					['kind' => 'fixed', 'name' => 'Koningsdag', 'month' => 4, 'day' => 27, 'observedShift' => ['whenWeekday' => 7, 'days' => -1]],
				],
			]
		);

	}//end calendar()

	/**
	 * In 2025 the 27th is a Sunday, so the holiday is kept on Saturday the 26th.
	 *
	 * @return void
	 */
	public function testASundayHolidayMovesToSaturday(): void {
		$dates = $this->calendar()->nonWorkingDates(2025);

		$this->assertArrayHasKey('2025-04-26', $dates);
		$this->assertArrayNotHasKey('2025-04-27', $dates);

	}//end testASundayHolidayMovesToSaturday()

	/**
	 * In 2026 the 27th is a Monday and the holiday stays on it.
	 *
	 * @return void
	 */
	public function testAWeekdayHolidayStays(): void {
		$dates = $this->calendar()->nonWorkingDates(2026);

		$this->assertSame('Koningsdag', $dates['2026-04-27'] ?? null);
		$this->assertArrayNotHasKey('2026-04-26', $dates);

	}//end testAWeekdayHolidayStays()
}//end class

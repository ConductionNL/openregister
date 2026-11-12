<?php

/**
 * Unit tests for the ten-year horizon of the service-hours walk.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHoursWalk;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * A term that does not fit in the horizon, or a span longer than it, has no answer (null) rather than a guess.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursWalk
 */
class ServiceHoursWalkHorizonTest extends TestCase {

	/**
	 * A walk over a calendar open one hour a week, on Monday 09:00-10:00 UTC.
	 *
	 * @return ServiceHoursWalk
	 */
	private function walk(): ServiceHoursWalk {
		$calendar = WorkingCalendar::fromArray(
			[
				'slug'               => 'one-hour',
				'hoursPerWorkingDay' => 1,
				'workingWeekdays'    => [1],
				'serviceHours'       => ['monday' => [['start' => '09:00', 'end' => '10:00']]],
			]
		);

		return new ServiceHoursWalk($calendar, $calendar->getServiceHours());

	}//end walk()

	/**
	 * A term inside the horizon is due; one larger than every open minute in it is not.
	 *
	 * @return void
	 */
	public function testATermBeyondTheHorizonHasNoDueMoment(): void {
		$monday = new DateTimeImmutable('2026-09-07 08:00:00', new DateTimeZone('UTC'));

		$this->assertSame('2026-09-07 09:30', $this->walk()->dueAfter($monday, 30)?->format('Y-m-d H:i'));
		$this->assertNull($this->walk()->dueAfter($monday, 60 * 24 * 365));

	}//end testATermBeyondTheHorizonHasNoDueMoment()

	/**
	 * A span inside the horizon is measured; one longer than the horizon is not.
	 *
	 * @return void
	 */
	public function testASpanLongerThanTheHorizonIsNotMeasured(): void {
		$start = new DateTimeImmutable('2026-09-07 00:00:00', new DateTimeZone('UTC'));

		$this->assertSame(120, $this->walk()->minutesBetween($start, $start->modify('+8 days')));
		$this->assertNull($this->walk()->minutesBetween($start, $start->modify('+3700 days')));

	}//end testASpanLongerThanTheHorizonIsNotMeasured()
}//end class

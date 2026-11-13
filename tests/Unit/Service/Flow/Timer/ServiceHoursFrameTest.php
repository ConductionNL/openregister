<?php

/**
 * ServiceHoursFrame: a term in service hours measured in the calendar's own zone, and a term or span the walk cannot answer refused rather than guessed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHoursFrame;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursFrame
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursClock
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursWalk
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 */
class ServiceHoursFrameTest extends TestCase {

	/**
	 * A frame over a calendar open Monday 09:00-10:00 in the given zone.
	 *
	 * @param string $zone The calendar's timezone.
	 *
	 * @return ServiceHoursFrame
	 */
	private function frame(string $zone = 'UTC'): ServiceHoursFrame {
		$calendar = WorkingCalendar::fromArray(
			[
				'slug'               => 'one-hour',
				'timezone'           => $zone,
				'hoursPerWorkingDay' => 1,
				'workingWeekdays'    => [1],
				'serviceHours'       => ['monday' => [['start' => '09:00', 'end' => '10:00']]],
			]
		);

		return new ServiceHoursFrame(calendar: $calendar, windows: $calendar->getServiceHours());

	}//end frame()

	/**
	 * A term is due once that many open minutes have passed, in the calendar's zone.
	 *
	 * @return void
	 */
	public function testATermIsDueAfterItsOpenMinutes(): void {
		$monday = new DateTimeImmutable('2026-09-07 08:00:00', new DateTimeZone('UTC'));

		$due = $this->frame()->due(from: $monday, hours: 0.5);

		$this->assertSame('2026-09-07 09:30 UTC', $due->format('Y-m-d H:i T'));

	}//end testATermIsDueAfterItsOpenMinutes()

	/**
	 * The windows are read in the calendar's zone, whatever zone the start is given in.
	 *
	 * @return void
	 */
	public function testTheWindowsAreReadInTheCalendarsZone(): void {
		$monday = new DateTimeImmutable('2026-09-07 06:00:00', new DateTimeZone('UTC'));

		$due = $this->frame(zone: 'Europe/Amsterdam')->due(from: $monday, hours: 1);

		$this->assertSame('Europe/Amsterdam', $due->getTimezone()->getName());
		$this->assertSame('2026-09-07 10:00', $due->format('Y-m-d H:i'));

	}//end testTheWindowsAreReadInTheCalendarsZone()

	/**
	 * A term of no time (or less than half a minute) is due at once.
	 *
	 * @return void
	 */
	public function testAnEmptyTermIsDueImmediately(): void {
		$sunday = new DateTimeImmutable('2026-09-06 20:00:00', new DateTimeZone('UTC'));

		$this->assertSame('2026-09-06 20:00', $this->frame()->due(from: $sunday, hours: 0)->format('Y-m-d H:i'));
		$this->assertSame('2026-09-06 20:00', $this->frame()->due(from: $sunday, hours: 0.005)->format('Y-m-d H:i'));

	}//end testAnEmptyTermIsDueImmediately()

	/**
	 * A term the walk cannot place within its horizon is refused, naming the calendar.
	 *
	 * @return void
	 */
	public function testATermBeyondTheHorizonIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("Working calendar 'one-hour' has no open service hours in the next");

		$this->frame()->due(from: new DateTimeImmutable('2026-09-07 08:00:00'), hours: 24 * 365);

	}//end testATermBeyondTheHorizonIsRefused()

	/**
	 * Elapsed time counts only open minutes, in hours.
	 *
	 * @return void
	 */
	public function testElapsedCountsOnlyOpenTime(): void {
		$start = new DateTimeImmutable('2026-09-07 00:00:00', new DateTimeZone('UTC'));

		$this->assertSame(2.0, $this->frame()->elapsed(from: $start, to: $start->modify('+8 days')));
		$this->assertSame(0.25, $this->frame()->elapsed(from: $start->modify('+9 hours'), to: $start->modify('+9 hours 15 minutes')));

	}//end testElapsedCountsOnlyOpenTime()

	/**
	 * An end at or before the start has no elapsed time.
	 *
	 * @return void
	 */
	public function testABackwardsSpanHasNoElapsedTime(): void {
		$start = new DateTimeImmutable('2026-09-07 09:30:00', new DateTimeZone('UTC'));

		$this->assertSame(0.0, $this->frame()->elapsed(from: $start, to: $start));
		$this->assertSame(0.0, $this->frame()->elapsed(from: $start, to: $start->modify('-1 hour')));

	}//end testABackwardsSpanHasNoElapsedTime()

	/**
	 * A span longer than the walk's horizon is refused, naming both ends.
	 *
	 * @return void
	 */
	public function testASpanBeyondTheHorizonIsRefused(): void {
		$start = new DateTimeImmutable('2026-09-07 00:00:00', new DateTimeZone('UTC'));

		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('Measuring service hours between 2026-09-07T00:00:00+00:00 and');

		$this->frame()->elapsed(from: $start, to: $start->modify('+3700 days'));

	}//end testASpanBeyondTheHorizonIsRefused()
}//end class

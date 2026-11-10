<?php

declare(strict_types=1);

/**
 * Service hours as administered configuration, and the arithmetic that reads
 * them.
 *
 * WHY THIS FILE EXISTS BESIDE ServiceHoursTest. That one proves the windows
 * parse and the walk is right. It proves nothing about whether anything asks.
 * When it was written, `ServiceHours` and `ServiceHoursClock` were reachable
 * only from it: no calendar read a `serviceHours` key, no schema declared one,
 * so an administrator who typed opening hours had them dropped by the object
 * store without a word, and every hours term went on counting through the
 * night. A capability whose only caller is its own test looks exactly like a
 * working one.
 *
 * So every assertion here starts at the definition an administrator saves and
 * ends at a moment a handler is told, through `WorkingCalendar::fromArray()`
 * and `SlaCalculator`, which is the path production takes.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
 */

namespace Unit\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHoursClock;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The administered calendar decides when an hours term is due.
 */
class ServiceHoursAreAdministeredTest extends TestCase {

	/**
	 * The definition an administrator saves, with the opening hours they typed.
	 *
	 * @param array<string, mixed>|null $serviceHours The declared windows, or null for a calendar that keeps none.
	 *
	 * @return array<string, mixed> The stored `working-calendar` object.
	 */
	private function definition(?array $serviceHours): array {
		$definition = [
			'slug' => 'gemeente',
			'hoursPerWorkingDay' => 8,
			'workingWeekdays' => [1, 2, 3, 4, 5],
			'dayStartsAt' => '09:00',
			'timezone' => 'Europe/Amsterdam',
			'rules' => [['kind' => 'fixed', 'month' => 12, 'day' => 25, 'name' => 'Eerste Kerstdag']],
		];

		if ($serviceHours !== null) {
			$definition['serviceHours'] = $serviceHours;
		}

		return $definition;
	}//end definition()

	/**
	 * Nine to five, Monday to Friday, as the admin form writes it.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private function nineToFive(): array {
		$declared = [];
		foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
			$declared[$day] = [['start' => '09:00', 'end' => '17:00']];
		}

		return $declared;
	}//end nineToFive()

	/**
	 * One instant in the calendar's own zone.
	 *
	 * @param string $moment The local wall-clock time.
	 *
	 * @return DateTimeImmutable The instant.
	 */
	private function amsterdam(string $moment): DateTimeImmutable {
		return new DateTimeImmutable($moment, new DateTimeZone('Europe/Amsterdam'));
	}//end amsterdam()

	/**
	 * A calendar carries the service hours the administrator saved.
	 *
	 * @return void
	 */
	public function testACalendarReadsTheServiceHoursItWasSaved(): void {
		$calendar = WorkingCalendar::fromArray($this->definition($this->nineToFive()));

		$this->assertTrue($calendar->getServiceHours()->areDeclared());
		$this->assertSame(
			[['start' => 540, 'end' => 1020]],
			$calendar->getServiceHours()->forWeekday(iso: 5)
		);
	}//end testACalendarReadsTheServiceHoursItWasSaved()

	/**
	 * 🔴 THE WORKED EXAMPLE OF REQ-SHR-001, TAKEN FROM THE DEFINITION.
	 *
	 * A counter open 09:00 to 17:00, Monday to Friday, and a four-hour term
	 * armed on Friday at 16:00. Friday gives one hour, leaving three, and three
	 * hours from Monday's opening is 12:00.
	 *
	 * The requirement said 11:00 until this change. That was never a defect in
	 * the arithmetic: 11:00 is the answer to a three-hour term, or to a
	 * four-hour one against a counter opening at 08:00. The requirement now
	 * states the rule in terms of the configured window and carries this
	 * example, so the two cannot drift apart again without one of them turning
	 * red.
	 *
	 * @return void
	 */
	public function testFourServiceHoursFromFridayAfternoonAreDueMondayAtNoon(): void {
		$calculator = new SlaCalculator();
		$calendar = WorkingCalendar::fromArray($this->definition($this->nineToFive()));

		$due = $calculator->add(
			from: $this->amsterdam('2026-09-18 16:00:00'),
			value: 4.0,
			unit: SlaCalculator::UNIT_HOURS,
			calendar: $calendar
		);

		$this->assertSame('2026-09-21 12:00', $due->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'));
	}//end testFourServiceHoursFromFridayAfternoonAreDueMondayAtNoon()

	/**
	 * A closed midday is closed. The same four hours against a counter that
	 * shuts for lunch are due an hour later than against one that does not,
	 * which is the whole reason a single opening minute and a day length could
	 * not express this.
	 *
	 * @return void
	 */
	public function testTheLunchBreakIsNotCounted(): void {
		$calculator = new SlaCalculator();
		$split = [];
		foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
			$split[$day] = [
				['start' => '09:00', 'end' => '12:30'],
				['start' => '13:30', 'end' => '17:00'],
			];
		}

		$calendar = WorkingCalendar::fromArray($this->definition($split));

		$due = $calculator->add(
			from: $this->amsterdam('2026-09-21 11:00:00'),
			value: 4.0,
			unit: SlaCalculator::UNIT_HOURS,
			calendar: $calendar
		);

		$this->assertSame('2026-09-21 16:00', $due->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'));
		$this->assertSame(7.0, $calendar->getHoursPerWorkingDay());
	}//end testTheLunchBreakIsNotCounted()

	/**
	 * A holiday is skipped, because the days are the calendar's and the hours
	 * are the windows'. Two hours armed at 16:30 on Christmas Eve, with the
	 * 25th closed and the 26th and 27th a weekend, are due on the Monday.
	 *
	 * @return void
	 */
	public function testAClosedDayIsSkippedEntirely(): void {
		$calculator = new SlaCalculator();
		$calendar = WorkingCalendar::fromArray($this->definition($this->nineToFive()));

		$due = $calculator->add(
			from: $this->amsterdam('2026-12-24 16:30:00'),
			value: 2.0,
			unit: SlaCalculator::UNIT_HOURS,
			calendar: $calendar
		);

		$this->assertSame('2026-12-28 10:30', $due->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'));
	}//end testAClosedDayIsSkippedEntirely()

	/**
	 * A calendar that declares no windows counts hours exactly as it did
	 * before this existed. This is what lets an instance upgrade without
	 * recomputing a single live term, and it is asserted rather than assumed.
	 *
	 * @return void
	 */
	public function testACalendarWithoutWindowsCountsHoursAsItAlwaysDid(): void {
		$calculator = new SlaCalculator();
		$calendar = WorkingCalendar::fromArray($this->definition(null));

		$due = $calculator->add(
			from: $this->amsterdam('2026-09-18 16:00:00'),
			value: 4.0,
			unit: SlaCalculator::UNIT_HOURS,
			calendar: $calendar
		);

		$this->assertFalse($calendar->getServiceHours()->areDeclared());
		$this->assertSame('2026-09-18 20:00', $due->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'));
	}//end testACalendarWithoutWindowsCountsHoursAsItAlwaysDid()

	/**
	 * The calculator asks the clock when the calendar declares windows, and
	 * does not ask it when the calendar declares none.
	 *
	 * The double uses `onlyMethods`, so it cannot answer a method
	 * `ServiceHoursClock` does not have: a double that invents the method it is
	 * asked for turns a broken call site into a green test.
	 *
	 * @return void
	 */
	public function testTheClockIsAskedOnlyWhenWindowsAreDeclared(): void {
		$clock = $this->getMockBuilder(ServiceHoursClock::class)
			->onlyMethods(['due'])
			->getMock();
		$clock->expects($this->once())
			->method('due')
			->willReturn($this->amsterdam('2026-09-21 12:00:00'));

		$calculator = new SlaCalculator(hoursClock: $clock);
		$calculator->add(
			from: $this->amsterdam('2026-09-18 16:00:00'),
			value: 4.0,
			unit: SlaCalculator::UNIT_HOURS,
			calendar: WorkingCalendar::fromArray($this->definition($this->nineToFive()))
		);

		$quiet = $this->getMockBuilder(ServiceHoursClock::class)
			->onlyMethods(['due'])
			->getMock();
		$quiet->expects($this->never())->method('due');

		$plain = new SlaCalculator(hoursClock: $quiet);
		$plain->add(
			from: $this->amsterdam('2026-09-18 16:00:00'),
			value: 4.0,
			unit: SlaCalculator::UNIT_HOURS,
			calendar: WorkingCalendar::fromArray($this->definition(null))
		);
	}//end testTheClockIsAskedOnlyWhenWindowsAreDeclared()

	/**
	 * Elapsed time is measured inside the same windows the deadline was
	 * computed in. Friday 16:00 to Monday 10:00 is one open hour on the Friday
	 * and one on the Monday, not the sixty-six the wall clock reports and not
	 * the eight a day-length conversion would.
	 *
	 * @return void
	 */
	public function testElapsedHoursAreMeasuredInsideTheWindows(): void {
		$calculator = new SlaCalculator();
		$calendar = WorkingCalendar::fromArray($this->definition($this->nineToFive()));

		$elapsed = $calculator->elapsedBusinessHours(
			from: $this->amsterdam('2026-09-18 16:00:00'),
			to: $this->amsterdam('2026-09-21 10:00:00'),
			calendar: $calendar
		);

		$this->assertSame(2.0, $elapsed);
	}//end testElapsedHoursAreMeasuredInsideTheWindows()

	/**
	 * A calendar with no holiday list keeps no holidays, and says so rather
	 * than refusing to exist.
	 *
	 * The schema required `rules` until this change, so an organisation that
	 * closes on no fixed day at all could not save a calendar without
	 * inventing a holiday it does not keep. An empty list is an answer.
	 *
	 * @return void
	 */
	public function testACalendarWithNoHolidayListKeepsNoHolidays(): void {
		$calendar = WorkingCalendar::fromArray(
			[
				'slug' => 'always-open',
				'hoursPerWorkingDay' => 8,
				'workingWeekdays' => [1, 2, 3, 4, 5],
				'timezone' => 'Europe/Amsterdam',
			]
		);

		$this->assertSame([], $calendar->nonWorkingDates(year: 2026));
		$this->assertTrue($calendar->isWorkingDay($this->amsterdam('2026-12-25 10:00:00')));
	}//end testACalendarWithNoHolidayListKeepsNoHolidays()
}//end class

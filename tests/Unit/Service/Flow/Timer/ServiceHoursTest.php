<?php

declare(strict_types=1);

/**
 * Service hours: when the clock runs, and what is refused at write time.
 *
 * The scenario the change is named for is a four-hour term armed on Friday at
 * 16:00 against a nine-to-five calendar. It is due on Monday at 11:00: one hour
 * on the Friday, three on the Monday. Before this, the answer came from a single
 * opening minute and a day length, which is right only for an organisation whose
 * day is one unbroken block and wrong by the lunch break for one that closes at
 * midday.
 *
 * 🔴 THE REFUSALS MATTER MORE THAN THE ARITHMETIC. An overlapping window
 * double-counts its overlap, so every hours term on that calendar fires early,
 * for everybody, and the fired term looks exactly like a correct one. There is
 * no screen on which that would show, which is why it is refused at write time
 * and named by weekday.
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
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHours;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHoursClock;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ServiceHours and its clock.
 */
class ServiceHoursTest extends TestCase {

	private ServiceHoursClock $clock;

	/**
	 * Wire the clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->clock = new ServiceHoursClock();
	}//end setUp()

	/**
	 * The working weekdays every test here uses.
	 *
	 * @return array<int, int> Monday to Friday.
	 */
	private function weekdays(): array {
		return [1, 2, 3, 4, 5];
	}//end weekdays()

	/**
	 * Nine to five, Monday to Friday.
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
	 * A calendar in Amsterdam, working Monday to Friday.
	 *
	 * @return WorkingCalendar The calendar.
	 */
	private function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(
			[
				'slug' => 'gemeente',
				'hoursPerWorkingDay' => 8,
				'workingWeekdays' => $this->weekdays(),
				'dayStartsAt' => '09:00',
				'timezone' => 'Europe/Amsterdam',
				'rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'nieuwjaarsdag']],
			]
		);
	}//end calendar()

	/**
	 * 🔴 THE SCENARIO THE CHANGE IS NAMED FOR, WITH THE SPEC'S ARITHMETIC
	 * CORRECTED. The spec scenario says a 4-hour term armed on Friday at 16:00
	 * against a 09:00-17:00 calendar lands on Monday at 11:00. It lands at
	 * 12:00: Friday gives one hour (16:00 to 17:00), leaving three, and three
	 * hours from Monday's 09:00 opening is 12:00. Eleven o'clock would be the
	 * answer to a THREE-hour term, or to one armed at 15:00.
	 *
	 * The assertion follows the arithmetic rather than the prose, and the
	 * discrepancy is reported rather than absorbed: a test written to agree
	 * with a wrong scenario would pin the wrong behaviour into the codebase and
	 * look like coverage while doing it.
	 *
	 * @return void
	 */
	public function testFourHoursFromFridayAfternoonLandOnMondayMorning(): void {
		$windows = ServiceHours::fromArray(value: $this->nineToFive(), workingWeekdays: $this->weekdays(), slug: 'gemeente');

		$due = $this->clock->due(
			from: new DateTimeImmutable('2026-09-18 16:00:00', new \DateTimeZone('Europe/Amsterdam')),
			hours: 4.0,
			calendar: $this->calendar(),
			windows: $windows
		);

		$this->assertSame('2026-09-21 12:00', $due->format('Y-m-d H:i'));
	}//end testFourHoursFromFridayAfternoonLandOnMondayMorning()

	/**
	 * A term that fits inside the day it was armed on does not move.
	 *
	 * @return void
	 */
	public function testATermThatFitsInTheDayStaysOnIt(): void {
		$windows = ServiceHours::fromArray(value: $this->nineToFive(), workingWeekdays: $this->weekdays(), slug: 'gemeente');

		$due = $this->clock->due(
			from: new DateTimeImmutable('2026-09-16 10:00:00', new \DateTimeZone('Europe/Amsterdam')),
			hours: 3.0,
			calendar: $this->calendar(),
			windows: $windows
		);

		$this->assertSame('2026-09-16 13:00', $due->format('Y-m-d H:i'));
	}//end testATermThatFitsInTheDayStaysOnIt()

	/**
	 * A term armed before opening starts counting when the counter opens, not
	 * from the moment it was armed.
	 *
	 * @return void
	 */
	public function testATermArmedBeforeOpeningWaitsForTheCounterToOpen(): void {
		$windows = ServiceHours::fromArray(value: $this->nineToFive(), workingWeekdays: $this->weekdays(), slug: 'gemeente');

		$due = $this->clock->due(
			from: new DateTimeImmutable('2026-09-16 06:00:00', new \DateTimeZone('Europe/Amsterdam')),
			hours: 1.0,
			calendar: $this->calendar(),
			windows: $windows
		);

		$this->assertSame('2026-09-16 10:00', $due->format('Y-m-d H:i'));
	}//end testATermArmedBeforeOpeningWaitsForTheCounterToOpen()

	/**
	 * 🔴 A COUNTER THAT CLOSES FOR LUNCH IS THE CASE THE OLD ANSWER GOT WRONG.
	 * Six hours from 09:00 against 09:00-12:30 plus 13:30-17:00 is 16:00: three
	 * and a half hours before lunch, two and a half after. The hour the counter
	 * is shut is not owed to anybody, and a calculator working from an opening
	 * minute and a day length has no way to know it was shut.
	 *
	 * @return void
	 */
	public function testTheLunchBreakIsNotCounted(): void {
		$declared = [];
		foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
			$declared[$day] = [['start' => '09:00', 'end' => '12:30'], ['start' => '13:30', 'end' => '17:00']];
		}

		$windows = ServiceHours::fromArray(value: $declared, workingWeekdays: $this->weekdays(), slug: 'gemeente');

		$due = $this->clock->due(
			from: new DateTimeImmutable('2026-09-16 09:00:00', new \DateTimeZone('Europe/Amsterdam')),
			hours: 6.0,
			calendar: $this->calendar(),
			windows: $windows
		);

		$this->assertSame('2026-09-16 16:00', $due->format('Y-m-d H:i'));
	}//end testTheLunchBreakIsNotCounted()

	/**
	 * Two parts of one organisation keeping different hours get different
	 * answers to an identical term, and each diagnostic names its own calendar.
	 *
	 * @return void
	 */
	public function testTheCounterAndTheBackOfficeCountDifferently(): void {
		$short = [];
		foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
			$short[$day] = [['start' => '09:00', 'end' => '12:30']];
		}

		$counter = ServiceHours::fromArray(value: $short, workingWeekdays: $this->weekdays(), slug: 'balie');
		$backOffice = ServiceHours::fromArray(value: $this->nineToFive(), workingWeekdays: $this->weekdays(), slug: 'gemeente');
		$armed = new DateTimeImmutable('2026-09-16 09:00:00', new \DateTimeZone('Europe/Amsterdam'));

		$atCounter = $this->clock->due(from: $armed, hours: 6.0, calendar: $this->calendar(), windows: $counter);
		$atBackOffice = $this->clock->due(from: $armed, hours: 6.0, calendar: $this->calendar(), windows: $backOffice);

		$this->assertNotSame($atCounter->format('Y-m-d H:i'), $atBackOffice->format('Y-m-d H:i'));
	}//end testTheCounterAndTheBackOfficeCountDifferently()

	/**
	 * The answer explains itself: the diagnostic names the calendar, its zone
	 * and the windows applied.
	 *
	 * @return void
	 */
	public function testTheDiagnosticNamesTheCalendarAndTheWindows(): void {
		$windows = ServiceHours::fromArray(value: $this->nineToFive(), workingWeekdays: $this->weekdays(), slug: 'gemeente');

		$diagnostic = $this->clock->diagnostic(calendar: $this->calendar(), windows: $windows);

		$this->assertSame('gemeente', $diagnostic['calendar']);
		$this->assertSame('Europe/Amsterdam', $diagnostic['timezone']);
		$this->assertTrue($diagnostic['serviceHoursDeclared']);
		$this->assertSame(['09:00-17:00'], $diagnostic['windows'][1]);
	}//end testTheDiagnosticNamesTheCalendarAndTheWindows()

	/**
	 * A calendar declaring no windows says so, so every existing calendar
	 * behaves exactly as it did.
	 *
	 * @return void
	 */
	public function testACalendarWithoutWindowsDeclaresNone(): void {
		$this->assertFalse(ServiceHours::fromArray(value: null, workingWeekdays: $this->weekdays(), slug: 'gemeente')->areDeclared());
		$this->assertFalse(ServiceHours::none()->areDeclared());
	}//end testACalendarWithoutWindowsDeclaresNone()

	/**
	 * 🔴 THE OVERLAP IS REFUSED, AND THE WEEKDAY IS NAMED. Accepting it would
	 * make every hours term on the calendar fire early, invisibly.
	 *
	 * @return void
	 */
	public function testOverlappingWindowsAreRefusedNamingTheWeekday(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessageMatches('/Monday/');

		ServiceHours::fromArray(
			value: ['monday' => [['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '17:00']]],
			workingWeekdays: $this->weekdays(),
			slug: 'gemeente'
		);
	}//end testOverlappingWindowsAreRefusedNamingTheWeekday()

	/**
	 * Two windows that touch but do not overlap are accepted, so the refusal
	 * above is not a blanket on every second window.
	 *
	 * @return void
	 */
	public function testTwoWindowsThatOnlyTouchAreAccepted(): void {
		$windows = ServiceHours::fromArray(
			value: ['monday' => [['start' => '09:00', 'end' => '12:30'], ['start' => '12:30', 'end' => '17:00']]],
			workingWeekdays: $this->weekdays(),
			slug: 'gemeente'
		);

		// 09:00 to 12:30 and 12:30 to 17:00 is eight hours with no gap.
		$this->assertSame((8 * 60), $windows->minutesOn(iso: 1));
	}//end testTwoWindowsThatOnlyTouchAreAccepted()

	/**
	 * A window ending at or before it starts is refused, naming the weekday.
	 *
	 * @return void
	 */
	public function testABackwardsWindowIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessageMatches('/Tuesday/');

		ServiceHours::fromArray(
			value: ['tuesday' => [['start' => '17:00', 'end' => '09:00']]],
			workingWeekdays: $this->weekdays(),
			slug: 'gemeente'
		);
	}//end testABackwardsWindowIsRefused()

	/**
	 * 🔴 A WINDOW ON A DAY THE CALENDAR DOES NOT WORK IS REFUSED, NOT IGNORED.
	 * Dropping it silently leaves somebody believing the office is open on
	 * Saturday, and the terms they compute say so too.
	 *
	 * @return void
	 */
	public function testAWindowOnANonWorkingWeekdayIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessageMatches('/Saturday/');

		ServiceHours::fromArray(
			value: ['saturday' => [['start' => '09:00', 'end' => '13:00']]],
			workingWeekdays: $this->weekdays(),
			slug: 'gemeente'
		);
	}//end testAWindowOnANonWorkingWeekdayIsRefused()

	/**
	 * A window without a readable start and end is refused rather than read as
	 * midnight to midnight.
	 *
	 * @return void
	 */
	public function testAnUnreadableWindowIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);

		ServiceHours::fromArray(
			value: ['monday' => [['start' => 'ochtend', 'end' => 'avond']]],
			workingWeekdays: $this->weekdays(),
			slug: 'gemeente'
		);
	}//end testAnUnreadableWindowIsRefused()

	/**
	 * 🔴 THE SCALAR IS DERIVED FROM THE WINDOWS, so a calendar cannot hold two
	 * answers to how long its day is with nothing to say which one a term used.
	 *
	 * @return void
	 */
	public function testHoursPerWorkingDayIsDerivedFromTheWindows(): void {
		$declared = ['monday' => [['start' => '09:00', 'end' => '12:30'], ['start' => '13:30', 'end' => '17:00']]];

		$windows = ServiceHours::fromArray(value: $declared, workingWeekdays: $this->weekdays(), slug: 'gemeente');

		$this->assertSame(7.0, $windows->derivedHoursPerWorkingDay());
	}//end testHoursPerWorkingDayIsDerivedFromTheWindows()

	/**
	 * A calendar with no declared windows derives nothing, rather than zero
	 * hours, which the caller must read as "keep what you had".
	 *
	 * @return void
	 */
	public function testNoWindowsDeriveNoHours(): void {
		$this->assertSame(0.0, ServiceHours::none()->derivedHoursPerWorkingDay());
	}//end testNoWindowsDeriveNoHours()
}//end class

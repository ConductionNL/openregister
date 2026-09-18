<?php

/**
 * Business-time arithmetic across weekends and holidays, in all three units,
 * and the SLA shape gate.
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
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 */
class SlaCalculatorTest extends TestCase {

	private SlaCalculator $calculator;

	private WorkingCalendar $calendar;

	private DateTimeZone $tz;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new SlaCalculator();
		$this->calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
		$this->tz = new DateTimeZone('Europe/Amsterdam');
	}//end setUp()

	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, $this->tz);
	}//end at()

	public function testThreeBusinessDaysFromThursdayLandOnTuesday(): void {
		// 3 September 2026 is a Thursday.
		$landing = $this->calculator->add(from: $this->at('2026-09-03 10:00'), value: 3, unit: 'businessDays', calendar: $this->calendar);
		self::assertSame('2026-09-08 10:00 Tuesday', $landing->format('Y-m-d H:i l'));
	}//end testThreeBusinessDaysFromThursdayLandOnTuesday()

	public function testSubtractionIsTheInverseOfAddition(): void {
		$back = $this->calculator->sub(from: $this->at('2026-09-08 10:00'), value: 3, unit: 'businessDays', calendar: $this->calendar);
		self::assertSame('2026-09-03 10:00', $back->format('Y-m-d H:i'));
	}//end testSubtractionIsTheInverseOfAddition()

	public function testHolidaysAreSkippedLikeWeekends(): void {
		// Thursday 24 December 2026 + 1 business day skips Kerst (25, 26 falls on Saturday) and the weekend.
		$landing = $this->calculator->add(from: $this->at('2026-12-24 09:00'), value: 1, unit: 'businessDays', calendar: $this->calendar);
		self::assertSame('2026-12-28 09:00 Monday', $landing->format('Y-m-d H:i l'));
	}//end testHolidaysAreSkippedLikeWeekends()

	public function testAWeekendMeasuresZeroBusinessDays(): void {
		self::assertSame(0.0, $this->calculator->measure(from: $this->at('2026-09-05 10:00'), to: $this->at('2026-09-06 18:00'), unit: 'businessDays', calendar: $this->calendar));
		// Friday 17:00 to Monday 09:00: 7 hours of Friday plus 9 hours of Monday, as fractions of a day.
		$span = $this->calculator->measure(from: $this->at('2026-09-04 17:00'), to: $this->at('2026-09-07 09:00'), unit: 'businessDays', calendar: $this->calendar);
		self::assertEqualsWithDelta((7 + 9) / 24, $span, 0.0001);
		// Negative direction is signed.
		self::assertEqualsWithDelta(-((7 + 9) / 24), $this->calculator->measure(from: $this->at('2026-09-07 09:00'), to: $this->at('2026-09-04 17:00'), unit: 'businessDays', calendar: $this->calendar), 0.0001);
	}//end testAWeekendMeasuresZeroBusinessDays()

	public function testMeasureAndAddAgreeAcrossAWeekend(): void {
		$from = $this->at('2026-09-04 17:00');
		$to = $this->calculator->add(from: $from, value: 1, unit: 'businessDays', calendar: $this->calendar);
		self::assertSame('2026-09-07 17:00', $to->format('Y-m-d H:i'));
		self::assertEqualsWithDelta(1.0, $this->calculator->measure(from: $from, to: $to, unit: 'businessDays', calendar: $this->calendar), 0.0001);
	}//end testMeasureAndAddAgreeAcrossAWeekend()

	public function testHoursAndCalendarDaysIgnoreTheCalendar(): void {
		$from = $this->at('2026-09-04 17:00');
		self::assertSame('2026-09-06 17:00', $this->calculator->add(from: $from, value: 48, unit: 'hours', calendar: $this->calendar)->format('Y-m-d H:i'));
		self::assertSame('2026-09-06 17:00', $this->calculator->add(from: $from, value: 2, unit: 'calendarDays', calendar: $this->calendar)->format('Y-m-d H:i'));
		self::assertSame(48.0, $this->calculator->measure(from: $from, to: $this->at('2026-09-06 17:00'), unit: 'hours', calendar: $this->calendar));
		self::assertSame(2.0, $this->calculator->measure(from: $from, to: $this->at('2026-09-06 17:00'), unit: 'calendarDays', calendar: $this->calendar));
	}//end testHoursAndCalendarDaysIgnoreTheCalendar()

	public function testCalendarDaysAreDatesAcrossADstChange(): void {
		// DST ends on 25 October 2026 in Europe/Amsterdam: 60 calendar days still land at 09:00.
		$from = $this->at('2026-09-01 09:00');
		$to = $this->calculator->add(from: $from, value: 60, unit: 'calendarDays', calendar: $this->calendar);
		self::assertSame('2026-10-31 09:00', $to->format('Y-m-d H:i'));
		self::assertSame(60.0, $this->calculator->measure(from: $from, to: $to, unit: 'calendarDays', calendar: $this->calendar));
		self::assertSame(-60.0, $this->calculator->measure(from: $to, to: $from, unit: 'calendarDays', calendar: $this->calendar));
		self::assertSame(1441.0, $this->calculator->measure(from: $from, to: $to, unit: 'hours', calendar: $this->calendar), 'hours are elapsed time');
		// A fractional calendar day is the fraction of a day in seconds.
		self::assertSame('2026-09-02 21:00', $this->calculator->add(from: $from, value: 1.5, unit: 'calendarDays', calendar: $this->calendar)->format('Y-m-d H:i'));
		self::assertSame('2026-08-30 21:00', $this->calculator->sub(from: $from, value: 1.5, unit: 'calendarDays', calendar: $this->calendar)->format('Y-m-d H:i'));
	}//end testCalendarDaysAreDatesAcrossADstChange()

	public function testConversionPivotsOnWorkingHours(): void {
		self::assertSame(16.0, $this->calculator->convert(value: 2, fromUnit: 'businessDays', toUnit: 'hours', calendar: $this->calendar));
		self::assertSame(1.0, $this->calculator->convert(value: 24, fromUnit: 'hours', toUnit: 'calendarDays', calendar: $this->calendar));
		self::assertSame(3.0, $this->calculator->convert(value: 1, fromUnit: 'calendarDays', toUnit: 'businessDays', calendar: $this->calendar));
		self::assertSame(5.0, $this->calculator->convert(value: 5, fromUnit: 'hours', toUnit: 'hours', calendar: $this->calendar));
	}//end testConversionPivotsOnWorkingHours()

	/**
	 * The default is OFF, and this is the test that keeps it off.
	 *
	 * Rolling changes a deadline. One that moved because the software thought
	 * it should is worse than one that lands on a Sunday, so a budget that says
	 * nothing about rolling gets exactly the moment it got before this existed.
	 *
	 * @return void
	 */
	public function testWithoutARollTheDeadlineStaysWhereTheBudgetPutIt(): void {
		// 42 calendar days from 23 February 2026 is Sunday 5 April 2026, which
		// is Easter Sunday on this calendar.
		$landed = $this->calculator->add(from: $this->at('2026-02-22 09:00'), value: 42, unit: 'calendarDays', calendar: $this->calendar);
		self::assertSame('2026-04-05 09:00 Sunday', $landed->format('Y-m-d H:i l'));

		$rolled = $this->calculator->roll(moment: $landed, roll: SlaCalculator::ROLL_NONE, calendar: $this->calendar);
		self::assertSame($landed->format('c'), $rolled['at']->format('c'));
		self::assertNull($rolled['unrolledAt'], 'nothing moved, so nothing is reported as having moved');
		self::assertNull($rolled['rolledBy']);
	}//end testWithoutARollTheDeadlineStaysWhereTheBudgetPutIt()

	/**
	 * The Easter cluster: Sunday the 5th and Tweede Paasdag the 6th, so `next`
	 * walks to Tuesday the 7th and keeps the time of day.
	 *
	 * @return void
	 */
	public function testNextWalksTheWholeEasterCluster(): void {
		$landed = $this->at('2026-04-05 09:00');
		$rolled = $this->calculator->roll(moment: $landed, roll: SlaCalculator::ROLL_NEXT, calendar: $this->calendar);

		self::assertSame('2026-04-07 09:00 Tuesday', $rolled['at']->format('Y-m-d H:i l'));
		self::assertSame('2026-04-05', $rolled['unrolledAt']->format('Y-m-d'));
		self::assertSame('weekend', $rolled['rolledBy'], 'the Sunday stopped it, not the Monday it walked past');
	}//end testNextWalksTheWholeEasterCluster()

	/**
	 * A named holiday is named, in the calendar's own words.
	 *
	 * The name comes from the rule an administrator declared. This class knows
	 * one name, `weekend`, because it is the one rule it decides itself.
	 *
	 * @return void
	 */
	public function testANamedHolidayIsReportedByItsDeclaredName(): void {
		// Tweede Paasdag 2026 is Monday 6 April.
		$rolled = $this->calculator->roll(moment: $this->at('2026-04-06 14:30'), roll: SlaCalculator::ROLL_NEXT, calendar: $this->calendar);

		self::assertSame('2026-04-07 14:30', $rolled['at']->format('Y-m-d H:i'));
		self::assertSame('Tweede Paasdag', $rolled['rolledBy']);
	}//end testANamedHolidayIsReportedByItsDeclaredName()

	/**
	 * `previous` walks the other way, and keeps the time of day.
	 *
	 * @return void
	 */
	public function testPreviousWalksBackwards(): void {
		$rolled = $this->calculator->roll(moment: $this->at('2026-04-06 16:45'), roll: SlaCalculator::ROLL_PREVIOUS, calendar: $this->calendar);

		// Back past Easter Sunday and the Saturday to Friday 3 April — which is
		// Goede Vrijdag on this calendar, so back again to Thursday the 2nd.
		self::assertSame('2026-04-02 16:45 Thursday', $rolled['at']->format('Y-m-d H:i l'));
		self::assertSame('Tweede Paasdag', $rolled['rolledBy']);
	}//end testPreviousWalksBackwards()

	/**
	 * Koningsdag on a Sunday is observed the day before, and the roll follows
	 * the calendar's observed date rather than the nominal one.
	 *
	 * 27 April 2031 is a Sunday, so the calendar observes Koningsdag on the
	 * 26th; both days are non-working and `next` lands on Monday the 28th.
	 *
	 * @return void
	 */
	public function testAnObservedShiftIsFollowed(): void {
		$rolled = $this->calculator->roll(moment: $this->at('2031-04-26 09:00'), roll: SlaCalculator::ROLL_NEXT, calendar: $this->calendar);

		self::assertSame('2031-04-28 09:00 Monday', $rolled['at']->format('Y-m-d H:i l'));
		self::assertSame('Koningsdag', $rolled['rolledBy'], 'the observed date is the one that stopped it');
	}//end testAnObservedShiftIsFollowed()

	/**
	 * A business-day budget already lands on a working day, so the option is
	 * accepted and changes nothing.
	 *
	 * @return void
	 */
	public function testABusinessDayBudgetNeedsNoRoll(): void {
		$landed = $this->calculator->add(from: $this->at('2026-04-02 09:00'), value: 1, unit: 'businessDays', calendar: $this->calendar);
		$rolled = $this->calculator->roll(moment: $landed, roll: SlaCalculator::ROLL_NEXT, calendar: $this->calendar);

		self::assertSame($landed->format('c'), $rolled['at']->format('c'));
		self::assertNull($rolled['unrolledAt']);
	}//end testABusinessDayBudgetNeedsNoRoll()

	/**
	 * With no calendar there is nothing to roll against, and the moment stands.
	 *
	 * Inventing a working week here would move a deadline by a rule nobody
	 * declared, which is the one thing this option must never do.
	 *
	 * @return void
	 */
	public function testWithoutACalendarNothingRolls(): void {
		$landed = $this->at('2026-04-05 09:00');
		$rolled = $this->calculator->roll(moment: $landed, roll: SlaCalculator::ROLL_NEXT, calendar: null);

		self::assertSame($landed->format('c'), $rolled['at']->format('c'));
		self::assertNull($rolled['rolledBy']);
	}//end testWithoutACalendarNothingRolls()

	public function testSlaShapeIsValidated(): void {
		// The normalised shape now carries the roll, defaulting to `none`: a
		// deadline that moved without anybody asking is worse than one that
		// lands on a Sunday.
		self::assertSame(
			['value' => 5, 'unit' => 'businessDays', 'rollToWorkingDay' => 'none'],
			$this->calculator->validateSla(sla: ['value' => '5', 'unit' => 'businessDays'])
		);
		self::assertSame(
			['value' => 10000, 'unit' => 'hours', 'rollToWorkingDay' => 'none'],
			$this->calculator->validateSla(sla: ['value' => 10000, 'unit' => 'hours'])
		);
		self::assertSame(
			['value' => 42, 'unit' => 'calendarDays', 'rollToWorkingDay' => 'next'],
			$this->calculator->validateSla(sla: ['value' => 42, 'unit' => 'calendarDays', 'rollToWorkingDay' => 'next'])
		);

		$refusals = [
			['value' => 0, 'unit' => 'hours'],
			['value' => 10001, 'unit' => 'hours'],
			['value' => 1.5, 'unit' => 'hours'],
			['value' => 2, 'unit' => 'weeks'],
			['value' => 2],
			'nope',
			// An unknown roll is refused, not read as `none`. On a deadline
			// with legal effect a silent default is the worst kind.
			['value' => 2, 'unit' => 'hours', 'rollToWorkingDay' => 'nextWorkingDay'],
		];
		foreach ($refusals as $bad) {
			try {
				$this->calculator->validateSla(sla: $bad);
				self::fail('accepted ' . json_encode($bad));
			} catch (FlowTimerValidationException $refused) {
				self::assertNotSame('', $refused->getMessage());
			}
		}
	}//end testSlaShapeIsValidated()

	public function testUnknownUnitIsRefusedEverywhere(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->calculator->add(from: $this->at('2026-09-04 17:00'), value: 1, unit: 'fortnights', calendar: $this->calendar);
	}//end testUnknownUnitIsRefusedEverywhere()
}//end class

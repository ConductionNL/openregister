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

	public function testConversionPivotsOnWorkingHours(): void {
		self::assertSame(16.0, $this->calculator->convert(value: 2, fromUnit: 'businessDays', toUnit: 'hours', calendar: $this->calendar));
		self::assertSame(1.0, $this->calculator->convert(value: 24, fromUnit: 'hours', toUnit: 'calendarDays', calendar: $this->calendar));
		self::assertSame(3.0, $this->calculator->convert(value: 1, fromUnit: 'calendarDays', toUnit: 'businessDays', calendar: $this->calendar));
		self::assertSame(5.0, $this->calculator->convert(value: 5, fromUnit: 'hours', toUnit: 'hours', calendar: $this->calendar));
	}//end testConversionPivotsOnWorkingHours()

	public function testSlaShapeIsValidated(): void {
		self::assertSame(['value' => 5, 'unit' => 'businessDays'], $this->calculator->validateSla(sla: ['value' => '5', 'unit' => 'businessDays']));
		self::assertSame(['value' => 10000, 'unit' => 'hours'], $this->calculator->validateSla(sla: ['value' => 10000, 'unit' => 'hours']));

		foreach ([['value' => 0, 'unit' => 'hours'], ['value' => 10001, 'unit' => 'hours'], ['value' => 1.5, 'unit' => 'hours'], ['value' => 2, 'unit' => 'weeks'], ['value' => 2], 'nope'] as $bad) {
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

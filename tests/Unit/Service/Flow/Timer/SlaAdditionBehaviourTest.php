<?php

/**
 * One SLA addition: forward and backward amounts with their sign and
 * description, the calendar it must have for business units, and the
 * service-hours calendar it uses only for a forward addition on a calendar
 * that declares service hours.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\SlaAddition;
use OCA\OpenRegister\Service\Flow\Timer\WalkCollector;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * SlaAddition.
 */
class SlaAdditionBehaviourTest extends TestCase {

	private static function start(): DateTimeImmutable {
		return new DateTimeImmutable('2026-03-02T09:00:00+00:00');
	}//end start()

	private static function calendar(bool $serviceHours): WorkingCalendar {
		$definition = ['slug' => 'office', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8];
		if ($serviceHours === true) {
			$definition['serviceHours'] = ['monday' => [['start' => '09:00', 'end' => '17:00']]];
		}

		return WorkingCalendar::fromArray($definition);
	}//end calendar()

	public function testAForwardAdditionAddsItsAmount(): void {
		$addition = new SlaAddition(self::start(), 3.0, 'businessDays', null);

		$this->assertTrue($addition->isForward());
		$this->assertSame([3.0, 1], [$addition->amount(), $addition->sign()]);
		$this->assertSame('Adding 3 business days from 2026-03-02T09:00:00+00:00', $addition->describe());
	}//end testAForwardAdditionAddsItsAmount()

	public function testANegativeAdditionSubtractsItsMagnitude(): void {
		$addition = new SlaAddition(self::start(), -2.5, 'businessDays', null);

		$this->assertFalse($addition->isForward());
		$this->assertSame([2.5, -1], [$addition->amount(), $addition->sign()]);
		$this->assertSame('Subtracting 2.5 business days from 2026-03-02T09:00:00+00:00', $addition->describe());
	}//end testANegativeAdditionSubtractsItsMagnitude()

	public function testZeroCountsAsForward(): void {
		$this->assertSame(1, (new SlaAddition(self::start(), 0.0, 'hours', null))->sign());
	}//end testZeroCountsAsForward()

	public function testABusinessUnitWithoutACalendarIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("Unit 'businessDays' is counted against a working calendar and none was given; it would silently become wall-clock time.");

		(new SlaAddition(self::start(), 1.0, 'businessDays', null))->calendar();
	}//end testABusinessUnitWithoutACalendarIsRefused()

	public function testTheGivenCalendarIsReturned(): void {
		$calendar = self::calendar(false);

		$this->assertSame($calendar, (new SlaAddition(self::start(), 1.0, 'businessDays', $calendar))->calendar());
	}//end testTheGivenCalendarIsReturned()

	public function testServiceHoursApplyOnlyForwardOnACalendarThatDeclaresThem(): void {
		$withHours = self::calendar(true);

		$this->assertSame($withHours, (new SlaAddition(self::start(), 4.0, 'hours', $withHours))->serviceHoursCalendar());
		$this->assertNull((new SlaAddition(self::start(), 0.0, 'hours', $withHours))->serviceHoursCalendar());
		$this->assertNull((new SlaAddition(self::start(), -4.0, 'hours', $withHours))->serviceHoursCalendar());
		$this->assertNull((new SlaAddition(self::start(), 4.0, 'hours', self::calendar(false)))->serviceHoursCalendar());
		$this->assertNull((new SlaAddition(self::start(), 4.0, 'hours', null))->serviceHoursCalendar());
	}//end testServiceHoursApplyOnlyForwardOnACalendarThatDeclaresThem()

	public function testEachExaminedDayIsCountedOrSkippedWithTheRuleThatClosedIt(): void {
		$calendar = WorkingCalendar::fromArray(
			[
				'slug' => 'office',
				'workingWeekdays' => [1, 2, 3, 4, 5],
				'hoursPerWorkingDay' => 8,
				'rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'Nieuwjaarsdag']],
			]
		);
		$addition = new SlaAddition(self::start(), 2.0, 'businessDays', $calendar);
		$collector = new WalkCollector();

		$this->assertTrue($addition->examine(new DateTimeImmutable('2026-03-03T00:00:00+00:00'), $collector));
		$this->assertFalse($addition->examine(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $collector));
		$this->assertFalse($addition->examine(new DateTimeImmutable('2026-03-07T00:00:00+00:00'), $collector));
		$this->assertFalse($addition->examine(new DateTimeImmutable('2026-03-08T00:00:00+00:00'), null));

		$this->assertSame(
			[
				['date' => '2026-03-03', 'kind' => 'working', 'counted' => true],
				['date' => '2026-01-01', 'kind' => 'Nieuwjaarsdag', 'counted' => false],
				['date' => '2026-03-07', 'kind' => 'weekend', 'counted' => false],
			],
			$collector->walk()
		);
	}//end testEachExaminedDayIsCountedOrSkippedWithTheRuleThatClosedIt()
}//end class

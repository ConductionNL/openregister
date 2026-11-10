<?php

/**
 * Rolling a deadline off a non-working day: no roll, no calendar or a working
 * day leaves it; `next` and `previous` walk to the nearest working day and
 * report the moment before the roll and the rule (a named date or the
 * weekend) that stopped the first day.
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
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll;
use PHPUnit\Framework\TestCase;

/**
 * WorkingDayRoll::apply().
 */
class WorkingDayRollBehaviourTest extends TestCase {

	private static function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(
			[
				'slug' => 'office',
				'workingWeekdays' => [1, 2, 3, 4, 5],
				'hoursPerWorkingDay' => 8,
				'rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'Nieuwjaarsdag']],
			]
		);
	}//end calendar()

	/**
	 * @param array{at: DateTimeImmutable, unrolledAt: ?DateTimeImmutable, rolledBy: ?string} $result
	 *
	 * @return array{string, ?string, ?string}
	 */
	private static function shown(array $result): array {
		return [$result['at']->format('Y-m-d H:i'), $result['unrolledAt']?->format('Y-m-d H:i'), $result['rolledBy']];
	}//end shown()

	public function testNoRollNoCalendarOrAWorkingDayLeaveTheMoment(): void {
		$saturday = new DateTimeImmutable('2026-03-07T17:00:00+00:00');
		$roll = new WorkingDayRoll();

		$this->assertSame(['2026-03-07 17:00', null, null], self::shown($roll->apply($saturday, 'none', self::calendar())));
		$this->assertSame(['2026-03-07 17:00', null, null], self::shown($roll->apply($saturday, 'next', null)));
		$this->assertSame(
			['2026-03-06 17:00', null, null],
			self::shown($roll->apply(new DateTimeImmutable('2026-03-06T17:00:00+00:00'), 'next', self::calendar()))
		);
	}//end testNoRollNoCalendarOrAWorkingDayLeaveTheMoment()

	public function testAWeekendDeadlineRollsForwardOrBack(): void {
		$saturday = new DateTimeImmutable('2026-03-07T17:00:00+00:00');
		$roll = new WorkingDayRoll();

		$this->assertSame(['2026-03-09 17:00', '2026-03-07 17:00', 'weekend'], self::shown($roll->apply($saturday, 'next', self::calendar())));
		$this->assertSame(['2026-03-06 17:00', '2026-03-07 17:00', 'weekend'], self::shown($roll->apply($saturday, 'previous', self::calendar())));
	}//end testAWeekendDeadlineRollsForwardOrBack()

	public function testAHolidayDeadlineNamesTheHolidayItRolledOff(): void {
		$newYear = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

		$this->assertSame(
			['2026-01-02 12:00', '2026-01-01 12:00', 'Nieuwjaarsdag'],
			self::shown((new WorkingDayRoll())->apply($newYear, 'next', self::calendar()))
		);
	}//end testAHolidayDeadlineNamesTheHolidayItRolledOff()
}//end class

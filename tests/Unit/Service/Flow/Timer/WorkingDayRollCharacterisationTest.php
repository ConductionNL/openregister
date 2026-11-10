<?php

/**
 * Characterisation of WorkingDayRoll's walk, including its exhaustion refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 */
final class WorkingDayRollCharacterisationTest extends TestCase {

	/**
	 * A fixed, non-working moment: Saturday 4 April 2026.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function saturday(): DateTimeImmutable {
		return new DateTimeImmutable('2026-04-04 10:00', new DateTimeZone('Europe/Amsterdam'));
	}//end saturday()

	/**
	 * A calendar whose only working weekday is excepted on every date the
	 * walk can reach is refused, naming the limit, the start date and the
	 * calendar, in both directions.
	 *
	 * @return void
	 */
	public function testACalendarWithNoWorkingDayIsRefused(): void {
		$definition = WorkingCalendarTest::nlNational();
		$definition['workingWeekdays'] = [1];
		$definition['exceptions'] = [];
		$monday = new DateTimeImmutable('2025-02-03');
		while ($monday < new DateTimeImmutable('2027-07-01')) {
			$definition['exceptions'][] = $monday->format('Y-m-d');
			$monday = $monday->modify('+7 days');
		}

		$calendar = WorkingCalendar::fromArray(definition: $definition);

		foreach ([SlaCalculatorInterface::ROLL_NEXT, SlaCalculatorInterface::ROLL_PREVIOUS] as $roll) {
			try {
				(new WorkingDayRoll())->apply($this->saturday(), $roll, $calendar);
				$this->fail('a calendar with no working day cannot be rolled to');
			} catch (FlowTimerValidationException $refused) {
				$this->assertSame(
					'No working day within 400 days of 2026-04-04 on calendar nl-national: '
					. 'the calendar declares no working days to roll to.',
					$refused->getMessage()
				);
			}
		}
	}//end testACalendarWithNoWorkingDayIsRefused()

	/**
	 * Next and previous walk opposite ways and keep the time of day; the
	 * unrolled moment is the input, not the walked one.
	 *
	 * @return void
	 */
	public function testNextAndPreviousWalkOppositeWays(): void {
		$calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
		$roll = new WorkingDayRoll();

		$next = $roll->apply($this->saturday(), SlaCalculatorInterface::ROLL_NEXT, $calendar);
		$previous = $roll->apply($this->saturday(), SlaCalculatorInterface::ROLL_PREVIOUS, $calendar);

		// Easter Monday 6 April and Good Friday 3 April 2026 are declared.
		$this->assertSame('2026-04-07 10:00', $next['at']->format('Y-m-d H:i'));
		$this->assertSame('2026-04-02 10:00', $previous['at']->format('Y-m-d H:i'));
		$this->assertSame('2026-04-04 10:00', $next['unrolledAt']->format('Y-m-d H:i'));
		$this->assertSame('weekend', $next['rolledBy']);
		$this->assertSame('weekend', $previous['rolledBy']);
	}//end testNextAndPreviousWalkOppositeWays()
}//end class

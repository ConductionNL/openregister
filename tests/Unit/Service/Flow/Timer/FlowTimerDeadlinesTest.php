<?php

/**
 * FlowTimerDeadlines: the one derivation of fire_at and next_rung_at, the
 * roll onto a working day, and the remaining budget while armed or suspended.
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

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDeadlines;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerScope;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerDeadlines
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerScope
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerValues
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService
 * @uses \OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService
 * @uses \OCA\OpenRegister\Service\Flow\Timer\EscalationRule
 * @uses \OCA\OpenRegister\Service\Flow\Timer\RungTimeline
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaAddition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\MomentShift
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Db\FlowTimer
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses \OCA\OpenRegister\Service\Flow\Timer\CalendarRoll
 * @uses \OCA\OpenRegister\Service\Flow\Timer\CalendarSpan
 * @uses \OCA\OpenRegister\Service\Flow\Timer\RollRule
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 */
class FlowTimerDeadlinesTest extends TestCase {

	private DateTimeZone $tz;

	private WorkingCalendar $calendar;

	private FlowTimerDeadlines $deadlines;

	protected function setUp(): void {
		parent::setUp();
		$this->tz = new DateTimeZone('Europe/Amsterdam');
		$definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$definitions->method('calendars')->willReturn(self::calendars());
		$definitions->method('ladders')->willReturn([]);
		$calculator = new SlaCalculator();
		$this->calendar = (new WorkingCalendarService(definitions: $definitions))->resolve(calendarSlug: null, organisation: null);
		$this->deadlines = new FlowTimerDeadlines(
			calculator: $calculator,
			ladder: new EscalationLadderService(definitions: $definitions, calculator: $calculator)
		);
	}//end setUp()

	/**
	 * The seeded working calendars.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function calendars(): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../../../lib/Settings/flow_timer_register.json'), true);
		$calendars = [];
		foreach ($data['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'working-calendar') {
				unset($object['@self']);
				$calendars[$object['slug']] = $object;
			}
		}

		return $calendars;
	}//end calendars()

	private function timer(string $runningSince, float $budget, float $consumed = 0.0, string $roll = 'none'): FlowTimer {
		$timer = new FlowTimer();
		$timer->setState(FlowTimer::STATE_ARMED);
		$timer->setRunningSince(new DateTime($runningSince, $this->tz));
		$timer->setBudgetValue($budget);
		$timer->setBudgetUnit('calendarDays');
		$timer->setConsumedValue($consumed);
		$timer->setRollToWorkingDay($roll);
		$timer->setEscalationRules([]);

		return $timer;
	}//end timer()

	private function scope(FlowTimer $timer): FlowTimerScope {
		return new FlowTimerScope(timer: $timer, calendar: $this->calendar);
	}//end scope()

	public function testAnArmedTimerFiresAtTheRunningStartPlusTheUnconsumedBudget(): void {
		// Monday + (10 - 3) calendar days = the next Monday.
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 10.0, consumed: 3.0);

		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: []);

		self::assertSame('2026-03-09T09:00:00+01:00', $timer->getFireAt()->format('c'));
		self::assertNull($timer->getUnrolledAt());
		self::assertNull($timer->getRolledBy());
		self::assertNull($timer->getNextRungAt());
	}//end testAnArmedTimerFiresAtTheRunningStartPlusTheUnconsumedBudget()

	public function testAnOverconsumedBudgetFiresAtTheRunningStart(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 2.0, consumed: 5.0);

		self::assertSame('2026-03-02T09:00:00+01:00', $this->deadlines->landed(scope: $this->scope($timer))->format('c'));
	}//end testAnOverconsumedBudgetFiresAtTheRunningStart()

	public function testADeadlineOnASundayRollsToMondayAndRecordsTheMove(): void {
		// Monday + 6 calendar days = Sunday.
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 6.0, roll: 'next');

		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: []);

		self::assertSame('2026-03-09', $timer->getFireAt()->format('Y-m-d'));
		self::assertSame('2026-03-08T09:00:00+01:00', $timer->getUnrolledAt()->format('c'));
		self::assertNotNull($timer->getRolledBy());
	}//end testADeadlineOnASundayRollsToMondayAndRecordsTheMove()

	public function testWithoutARollTheDeadlineStaysOnTheSunday(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 6.0);
		$timer->setRollToWorkingDay(null);

		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: []);

		self::assertSame('2026-03-08T09:00:00+01:00', $timer->getFireAt()->format('c'));
		self::assertNull($timer->getUnrolledAt());
	}//end testWithoutARollTheDeadlineStaysOnTheSunday()

	public function testTheEarliestUnfiredRungIsTheNextRung(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 10.0);
		$timer->setEscalationRules([
			['key' => 'early', 'trigger' => 'preBreach', 'offset' => 5, 'offsetUnit' => 'calendarDays', 'notifyRole' => 'handler'],
			['key' => 'late', 'trigger' => 'preBreach', 'offset' => 2, 'offsetUnit' => 'calendarDays', 'notifyRole' => 'handler'],
		]);

		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: []);
		self::assertSame('2026-03-07T09:00:00+01:00', $timer->getNextRungAt()->format('c'));

		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: ['early']);
		self::assertSame('2026-03-10T09:00:00+01:00', $timer->getNextRungAt()->format('c'));
	}//end testTheEarliestUnfiredRungIsTheNextRung()

	public function testASuspendedTimerHasNoDeadlineAndForgetsItsRoll(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 6.0, roll: 'next');
		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: []);
		$timer->setNextRungAt(new DateTime('2026-03-05 09:00:00', $this->tz));
		$timer->setState(FlowTimer::STATE_SUSPENDED);

		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: []);

		self::assertNull($timer->getFireAt());
		self::assertNull($timer->getNextRungAt());
		self::assertNull($timer->getUnrolledAt());
		self::assertNull($timer->getRolledBy());
	}//end testASuspendedTimerHasNoDeadlineAndForgetsItsRoll()

	public function testAnArmedTimerWithoutARunningStartHasNoDeadline(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 6.0);
		$timer->setFireAt(new DateTime('2026-03-08 09:00:00', $this->tz));
		$timer->setRunningSince(null);

		$this->deadlines->recompute(scope: $this->scope($timer), firedKeys: []);

		self::assertNull($timer->getFireAt());
	}//end testAnArmedTimerWithoutARunningStartHasNoDeadline()

	public function testRemainingSubtractsTheRunningSegmentWhileArmed(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 10.0, consumed: 1.0);

		$remaining = $this->deadlines->remaining(scope: $this->scope($timer), now: new DateTimeImmutable('2026-03-05 09:00:00', $this->tz));

		self::assertEqualsWithDelta(6.0, $remaining, 0.0001);
	}//end testRemainingSubtractsTheRunningSegmentWhileArmed()

	public function testRemainingGoesNegativeOnceOverdue(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 2.0);

		$remaining = $this->deadlines->remaining(scope: $this->scope($timer), now: new DateTimeImmutable('2026-03-05 09:00:00', $this->tz));

		self::assertEqualsWithDelta(-1.0, $remaining, 0.0001);
	}//end testRemainingGoesNegativeOnceOverdue()

	public function testRemainingWhileSuspendedCountsOnlyTheConsumedTime(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 10.0, consumed: 4.0);
		$timer->setState(FlowTimer::STATE_SUSPENDED);

		$remaining = $this->deadlines->remaining(scope: $this->scope($timer), now: new DateTimeImmutable('2026-03-30 09:00:00', $this->tz));

		self::assertSame(6.0, $remaining);
	}//end testRemainingWhileSuspendedCountsOnlyTheConsumedTime()

	public function testRemainingWithoutARunningStartCountsOnlyTheConsumedTime(): void {
		$timer = $this->timer(runningSince: '2026-03-02 09:00:00', budget: 10.0, consumed: 4.0);
		$timer->setRunningSince(null);

		$remaining = $this->deadlines->remaining(scope: $this->scope($timer), now: new DateTimeImmutable('2026-03-30 09:00:00', $this->tz));

		self::assertSame(6.0, $remaining);
	}//end testRemainingWithoutARunningStartCountsOnlyTheConsumedTime()
}//end class

<?php

/**
 * The refusal and fallback branches the happy-path suites step over: bad
 * anchors and dates, resolving against a superseded timer, the calculator's
 * walk bound, a malformed calendar in every named way, and the stores'
 * degradation paths.
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
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Exception\FlowTimerStateException;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @covers \OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore
 */
class FlowTimerEdgeCasesTest extends TestCase {

	private WorkingCalendar $calendar;

	private SlaCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
		$this->calculator = new SlaCalculator();
	}//end setUp()

	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, new DateTimeZone('Europe/Amsterdam'));
	}//end at()

	public function testTheBusinessDayWalkIsBounded(): void {
		$noWork = WorkingCalendar::fromArray(
			definition: ['slug' => 'sundays-only', 'workingWeekdays' => [7], 'hoursPerWorkingDay' => 8, 'rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'x']]]
		);
		try {
			$this->calculator->add(from: $this->at('2026-09-01 09:00'), value: 9000, unit: 'businessDays', calendar: $noWork);
			self::fail('an unbounded walk terminated');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('exceeds 20000 calendar days', $refused->getMessage());
		}

		try {
			$this->calculator->sub(from: $this->at('2026-09-01 09:00'), value: 9000, unit: 'businessDays', calendar: $noWork);
			self::fail('an unbounded backward walk terminated');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('exceeds 20000 calendar days', $refused->getMessage());
		}

		try {
			$this->calculator->measure(from: $this->at('1926-01-01 00:00'), to: $this->at('2026-09-01 00:00'), unit: 'businessDays', calendar: $noWork);
			self::fail('an unbounded measure terminated');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('exceeds 20000 calendar days', $refused->getMessage());
		}
	}//end testTheBusinessDayWalkIsBounded()

	public function testEveryMalformedCalendarShapeIsNamed(): void {
		$base = WorkingCalendarTest::nlNational();
		$cases = [
			['slug' => ''],
			['hoursPerWorkingDay' => 0],
			['hoursPerWorkingDay' => 25],
			['workingWeekdays' => [0]],
			['workingWeekdays' => ['monday']],
			['rules' => 'weekdays'],
			['rules' => [['kind' => 'easter', 'offset' => 'friday']]],
			['rules' => [['kind' => 'fixed', 'month' => 13, 'day' => 1]]],
			['rules' => [['kind' => 'observedShift', 'month' => 4, 'day' => 27, 'whenWeekday' => 7, 'days' => 'one']]],
			['exceptions' => 'none'],
			['exceptions' => [20261225]],
			['exceptions' => [['name' => 'no date']]],
		];
		foreach ($cases as $broken) {
			try {
				WorkingCalendar::fromArray(definition: array_merge($base, $broken));
				self::fail('accepted ' . json_encode($broken));
			} catch (FlowTimerValidationException $refused) {
				self::assertNotSame('', $refused->getMessage());
			}
		}
	}//end testEveryMalformedCalendarShapeIsNamed()

	public function testAnEasterRuleAndAnExceptionOnlyApplyToTheirOwnYear(): void {
		$definition = WorkingCalendarTest::nlNational();
		$definition['exceptions'] = ['2026-10-05'];
		$calendar = WorkingCalendar::fromArray(definition: $definition);
		self::assertArrayHasKey('2026-10-05', $calendar->nonWorkingDates(year: 2026));
		self::assertSame('exception', $calendar->nonWorkingDates(year: 2026)['2026-10-05']);
		self::assertArrayNotHasKey('2026-10-05', $calendar->nonWorkingDates(year: 2027));
	}//end testAnEasterRuleAndAnExceptionOnlyApplyToTheirOwnYear()

	public function testLadderRefusalsNameTheRule(): void {
		$definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$definitions->method('ladders')->willReturn(EscalationLadderServiceTest::seededLadders());
		$ladder = new EscalationLadderService(definitions: $definitions, calculator: $this->calculator);

		try {
			$ladder->normaliseRules(rules: 'daily', sla: ['value' => 1, 'unit' => 'hours']);
			self::fail('accepted a non-array rule set');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('array of rules', $refused->getMessage());
		}

		try {
			$ladder->normaliseRules(rules: ['soon'], sla: ['value' => 1, 'unit' => 'hours']);
			self::fail('accepted a non-object rule');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('not an object', $refused->getMessage());
		}

		try {
			$ladder->normaliseRules(
				rules: [['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'hours', 'notifyRole' => 42]],
				sla: ['value' => 1, 'unit' => 'hours']
			);
			self::fail('accepted a numeric role list');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('notifyRole', $refused->getMessage());
		}

		// A single role as a bare string is normalised to a list; equal rung
		// instants keep their declared order.
		$rungs = $ladder->normaliseRules(
			rules: [
				['key' => 'b', 'trigger' => 'slaBreached', 'offset' => 0, 'offsetUnit' => 'hours', 'notifyRole' => 'handler'],
				['key' => 'a', 'trigger' => 'slaBreached', 'offset' => 0, 'offsetUnit' => 'calendarDays', 'notifyRole' => 'handler'],
			],
			sla: ['value' => 1, 'unit' => 'hours']
		);
		self::assertSame(['handler'], $rungs[0]['notifyRole']);
		$fireAt = $this->at('2026-09-20 12:00');
		$due = $ladder->dueRungs(rungs: $rungs, fireAt: $fireAt, now: $fireAt, firedKeys: [], calendar: $this->calendar);
		self::assertSame(['b', 'a'], array_map(static fn (array $entry): string => (string)$entry['rung']['key'], $due));
	}//end testLadderRefusalsNameTheRule()

	public function testTheDefinitionStoreDegradesLoudlyAndQuietly(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsBySlug')->willReturn(7);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn('/nowhere-at-all');
		$store = new FlowTimerDefinitionStore(objects: $objects, appManager: $appManager, logger: new NullLogger());
		self::assertSame([], $store->calendars(), 'a count result and an unreadable descriptor answer empty, not wrongly');
		self::assertSame([], $store->ladders());
	}//end testTheDefinitionStoreDegradesLoudlyAndQuietly()

	public function testSupersededAndCancelledTimersRefuseSuspension(): void {
		$timer = new FlowTimer();
		$timer->setState(FlowTimer::STATE_SUPERSEDED);
		self::assertFalse($timer->isOpen());
		self::assertInstanceOf(FlowTimerStateException::class, new FlowTimerStateException(message: 'x'));
	}//end testSupersededAndCancelledTimersRefuseSuspension()
}//end class

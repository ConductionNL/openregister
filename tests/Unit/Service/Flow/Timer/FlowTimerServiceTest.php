<?php

/**
 * The timer lifecycle against fakes with real semantics: arm-time refusals,
 * the anchor, the hersteltermijn remainder, a business-day term across a
 * weekend, bounded extension, supersession with inherited rungs, cancellation
 * by completion, the four distinct expiry outcomes, rung idempotency across
 * passes and restarts, and the fire_at identity after EVERY operation.
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
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Exception\FlowTimerStateException;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerService
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService
 * @covers \OCA\OpenRegister\Db\FlowTimer
 * @covers \OCA\OpenRegister\Db\FlowTimerEvent
 * @covers \OCA\OpenRegister\Db\FlowTimerEventMapper
 * @covers \OCA\OpenRegister\Db\FlowTimerFireMapper
 * @covers \OCA\OpenRegister\Db\FlowTimerMapper
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Event\FlowTimerFiredEvent
 * @covers \OCA\OpenRegister\Exception\TaskConflictException
 * @covers \OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService
 * @covers \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 */
class FlowTimerServiceTest extends TestCase {

	private InMemoryTimerStore $store;

	private TaskService&MockObject $taskService;

	private WorkingCalendarService $calendars;

	private SlaCalculator $calculator;

	/**
	 * @var array<int, FlowTimerFiredEvent>
	 */
	private array $dispatched = [];

	private FlowTimerService $service;

	private DateTimeZone $tz;

	protected function setUp(): void {
		parent::setUp();
		$this->tz = new DateTimeZone('Europe/Amsterdam');
		$db = $this->createMock(IDBConnection::class);
		$this->store = new InMemoryTimerStore(db: $db);
		$this->taskService = $this->createMock(TaskService::class);
		$this->calculator = new SlaCalculator();
		$this->service = $this->buildService();
	}//end setUp()

	/**
	 * A service over the SAME store: a "restart" keeps the rows and loses everything else.
	 */
	private function buildService(): FlowTimerService {
		$definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$definitions->method('calendars')->willReturn(self::calendars());
		$definitions->method('ladders')->willReturn(EscalationLadderServiceTest::seededLadders());
		$this->calendars = new WorkingCalendarService(definitions: $definitions);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->dispatched[] = $event;
		});

		return new FlowTimerService(
			timers: $this->store->timerMapper(),
			fires: $this->store->fireMapper(),
			events: $this->store->eventMapper(),
			tasks: $this->store->taskMapper(),
			taskService: $this->taskService,
			calendars: $this->calendars,
			calculator: $this->calculator,
			ladder: new EscalationLadderService(definitions: $definitions, calculator: $this->calculator),
			db: $this->createMock(IDBConnection::class),
			dispatcher: $dispatcher,
			logger: new NullLogger()
		);
	}//end buildService()

	/**
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

	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, $this->tz);
	}//end at()

	private function task(string $uuid = 'task-1', string $performerType = Task::PERFORMER_USER, ?string $assignee = 'alice'): Task {
		$task = new Task();
		$task->setId(1);
		$task->setUuid($uuid);
		$task->setState(Task::STATE_ACTIVE);
		$task->setPerformerType($performerType);
		$task->setAssignee($assignee);
		$this->store->tasks[$uuid] = $task;

		return $task;
	}//end task()

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function config(array $overrides = []): array {
		return array_merge(
			[
				'subjectType' => 'task',
				'subjectUuid' => 'task-1',
				'purpose' => 'due',
				'legalEffect' => 'servicenorm',
				'sla' => ['value' => 56, 'unit' => 'calendarDays'],
				'anchorEventAt' => $this->at('2026-09-01 09:00'),
				'ladder' => 'nl-termijn-default',
			],
			$overrides
		);
	}//end config()

	/**
	 * The identity every armed timer satisfies, and NULLs every suspended one has.
	 */
	private function assertInvariants(FlowTimer $timer): void {
		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());
		if ($timer->getState() === FlowTimer::STATE_ARMED) {
			$expected = $this->calculator->add(
				from: $timer->getRunningSince(),
				value: (float)$timer->getBudgetValue() - (float)$timer->getConsumedValue(),
				unit: (string)$timer->getBudgetUnit(),
				calendar: $calendar
			);
			self::assertNotNull($timer->getFireAt());
			self::assertEqualsWithDelta($expected->getTimestamp(), $timer->getFireAt()->getTimestamp(), 1, 'fire_at = add(running_since, budget - consumed)');
		}

		if ($timer->getState() === FlowTimer::STATE_SUSPENDED) {
			self::assertNull($timer->getFireAt());
			self::assertNull($timer->getRunningSince());
			self::assertNull($timer->getNextRungAt());
		}

		if ($timer->getSubjectType() === 'task' && isset($this->store->tasks[(string)$timer->getSubjectUuid()]) === true) {
			$task = $this->store->tasks[(string)$timer->getSubjectUuid()];
			$open = array_filter(
				$this->store->timers,
				static fn (FlowTimer $t): bool => $t->getSubjectUuid() === $timer->getSubjectUuid() && $t->isOpen() && $t->getFireAt() !== null
			);
			$due = array_filter($open, static fn (FlowTimer $t): bool => $t->getPurpose() === 'due');
			$expiry = array_filter($open, static fn (FlowTimer $t): bool => $t->getPurpose() === 'expiry');
			self::assertSame(
				self::earliest($due),
				($task->getDueAt() === null) ? null : $task->getDueAt()->getTimestamp(),
				'task.due_at is the earliest open due timer'
			);
			self::assertSame(
				self::earliest($expiry),
				($task->getExpiresAt() === null) ? null : $task->getExpiresAt()->getTimestamp(),
				'task.expires_at is the earliest open expiry timer'
			);
		}
	}//end assertInvariants()

	/**
	 * @param array<int|string, FlowTimer> $timers
	 */
	private static function earliest(array $timers): ?int {
		$min = null;
		foreach ($timers as $timer) {
			$ts = $timer->getFireAt()->getTimestamp();
			if ($min === null || $ts < $min) {
				$min = $ts;
			}
		}

		return $min;
	}//end earliest()

	public function testArmStoresTheAnchorAndProjectsOntoTheTask(): void {
		$this->task();
		$timer = $this->service->arm(config: $this->config(), actor: 'alice', now: $this->at('2026-09-01 09:00'));

		self::assertSame(FlowTimer::STATE_ARMED, $timer->getState());
		self::assertSame('2026-09-01 09:00', $timer->getAnchorAt()->format('Y-m-d H:i'));
		self::assertSame('2026-10-27 09:00', $timer->getFireAt()->format('Y-m-d H:i'), '56 calendar days');
		self::assertSame('2026-10-13 09:00', $timer->getNextRungAt()->format('Y-m-d H:i'), 'the 14-day rung');
		self::assertSame('2026-10-27 09:00', $this->store->tasks['task-1']->getDueAt()->format('Y-m-d H:i'));
		self::assertNull($this->store->tasks['task-1']->getExpiresAt());
		self::assertCount(1, $this->store->events);
		self::assertSame(FlowTimerEvent::TYPE_ARMED, $this->store->events[0]->getType());
		self::assertSame('alice', $this->store->events[0]->getActor());
		$this->assertInvariants($timer);
	}//end testArmStoresTheAnchorAndProjectsOntoTheTask()

	public function testTheClockStartsTheDayAfterTheWindowCloses(): void {
		// Objection received on the 3rd, window closes on the 20th, term anchored to window_closed + 1 calendar day.
		$timer = $this->service->arm(
			config: $this->config([
				'subjectType' => 'object',
				'subjectUuid' => 'bezwaar-1',
				'anchorEvent' => 'window_closed',
				'anchorEventAt' => $this->at('2026-03-20 00:00'),
				'anchorOffset' => 1,
				'anchorOffsetUnit' => 'calendarDays',
				'sla' => ['value' => 6, 'unit' => 'businessDays'],
				'ladder' => null,
			]),
			actor: null,
			now: $this->at('2026-03-03 10:00')
		);
		self::assertSame('2026-03-21', $timer->getAnchorAt()->format('Y-m-d'), 'the term starts on the 21st, not the 3rd');
		self::assertSame('window_closed', $timer->getAnchorEvent());
		self::assertSame(1, $timer->getAnchorOffset());
		self::assertSame('calendarDays', $timer->getAnchorOffsetUnit());
		self::assertNull($timer->getRunUuid(), 'a run is not required');
		self::assertNull($timer->getNextRungAt(), 'no ladder, no rung');
		$this->assertInvariants($timer);
	}//end testTheClockStartsTheDayAfterTheWindowCloses()

	public function testAnUnknownCalendarIsRefusedNotDowngraded(): void {
		$this->task();
		try {
			$this->service->arm(config: $this->config(['calendar' => 'moon-phases']), actor: null, now: $this->at('2026-09-01 09:00'));
			self::fail('armed against a calendar that does not exist');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString("Working calendar 'moon-phases' does not exist", $refused->getMessage());
		}

		self::assertSame([], $this->store->timers, 'no timer was created with a substituted calendar');
	}//end testAnUnknownCalendarIsRefusedNotDowngraded()

	public function testOnlyAWettelijkExpiryTimerMayEnforce(): void {
		$this->task();
		try {
			$this->service->arm(config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'servicenorm', 'onExpiry' => 'skip']), actor: null);
			self::fail('a servicenorm timer accepted an enforcing outcome');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString("only a 'wettelijk' timer may carry an enforcing outcome", $refused->getMessage());
		}

		try {
			$this->service->arm(config: $this->config(['purpose' => 'due', 'legalEffect' => 'wettelijk', 'onExpiry' => 'skip']), actor: null);
			self::fail('a due timer accepted an enforcing outcome');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('only an expiry timer enforces', $refused->getMessage());
		}

		try {
			$this->service->arm(config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'vanish']), actor: null);
			self::fail('an unknown outcome was accepted');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('use skip, error, dead_letter or transition:<action>', $refused->getMessage());
		}

		$ok = $this->service->arm(config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'transition:approve']), actor: null);
		self::assertTrue($ok->isEnforcing());
		self::assertSame([], array_filter($this->store->timers, static fn (FlowTimer $t): bool => $t->getOnExpiry() === 'skip'));
	}//end testOnlyAWettelijkExpiryTimerMayEnforce()

	public function testAPreBreachRuleBeyondTheSlaIsRefusedAtArmTimeNamingTheAnchor(): void {
		$this->task();
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('before the anchor 2026-09-01T09:00:00+02:00');
		$this->service->arm(
			config: $this->config([
				'sla' => ['value' => 48, 'unit' => 'hours'],
				'ladder' => null,
				'escalationRules' => [['trigger' => 'preBreach', 'offset' => 5, 'offsetUnit' => 'businessDays', 'notifyRole' => 'handler']],
			]),
			actor: null,
			now: $this->at('2026-09-01 09:00')
		);
	}//end testAPreBreachRuleBeyondTheSlaIsRefusedAtArmTimeNamingTheAnchor()

	public function testAHersteltermijnPauseReturnsTheRemainderIntact(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(config: $this->config(), actor: 'alice', now: $start);
		$uuid = (string)$timer->getUuid();

		// Day 19: suspended for a 14-day hersteltermijn.
		$suspended = $this->service->suspend(
			uuid: $uuid,
			reason: 'Hersteltermijn: aanvulling gevraagd',
			until: $start->modify('+33 days'),
			actor: 'bob',
			basis: 'Awb 4:15',
			now: $start->modify('+19 days')
		);
		self::assertSame(FlowTimer::STATE_SUSPENDED, $suspended->getState());
		self::assertEqualsWithDelta(19.0, $suspended->getConsumedValue(), 0.001);
		self::assertNull($suspended->getFireAt());
		$this->assertInvariants($suspended);
		self::assertNull($this->store->tasks['task-1']->getDueAt(), 'a suspended term projects no due date');
		self::assertSame('2026-10-04', $this->store->tasks['task-1']->getSuspendedUntil()->format('Y-m-d'));

		// Remaining is answerable while suspended, and the term is not overdue even past the original fire moment.
		$read = $this->service->describe(timer: $suspended, now: $start->modify('+70 days'));
		self::assertEqualsWithDelta(37.0, $read['remaining'], 0.001);
		self::assertFalse($read['overdue']);
		self::assertNull($read['overdueBy']);

		// The applicant responds on day 6 of the suspension.
		$resumed = $this->service->resume(uuid: $uuid, reason: 'Aanvulling ontvangen', actor: 'bob', now: $start->modify('+25 days'));
		self::assertSame(FlowTimer::STATE_ARMED, $resumed->getState());
		self::assertEqualsWithDelta(37.0, $this->service->describe(timer: $resumed, now: $start->modify('+25 days'))['remaining'], 0.001);
		self::assertSame($start->modify('+62 days')->format('Y-m-d H:i'), $resumed->getFireAt()->format('Y-m-d H:i'), '8 weeks minus 19 days, from the resume instant');
		self::assertSame(6 * 86400, $resumed->getSuspendedTotalSeconds());
		$this->assertInvariants($resumed);

		// Evidenced: actor, moment, reason and basis on both events.
		$history = $this->service->history(uuid: $uuid);
		self::assertSame([FlowTimerEvent::TYPE_ARMED, FlowTimerEvent::TYPE_SUSPENDED, FlowTimerEvent::TYPE_RESUMED], array_map(static fn (FlowTimerEvent $e): string => $e->getType(), $history));
		self::assertSame('bob', $history[1]->getActor());
		self::assertSame('Awb 4:15', $history[1]->getBasis());
		self::assertStringContainsString('Hersteltermijn', $history[1]->getReason());
		self::assertSame($start->modify('+19 days')->getTimestamp(), $history[1]->getCreated()->getTimestamp());
		self::assertSame('Aanvulling ontvangen', $history[2]->getReason());
	}//end testAHersteltermijnPauseReturnsTheRemainderIntact()

	public function testABusinessDayTermSuspendedOverAWeekendKeepsItsBusinessDays(): void {
		$this->task();
		// Armed Monday 09:00 with 10 business days.
		$timer = $this->service->arm(
			config: $this->config(['sla' => ['value' => 10, 'unit' => 'businessDays'], 'anchorEventAt' => $this->at('2026-08-31 09:00')]),
			actor: null,
			now: $this->at('2026-08-31 09:00')
		);
		$uuid = (string)$timer->getUuid();
		$friday = $this->at('2026-09-04 17:00');
		$monday = $this->at('2026-09-07 09:00');

		$before = $this->service->describe(timer: $timer, now: $friday)['remaining'];
		$this->service->suspend(uuid: $uuid, reason: 'weekend closure test', until: null, actor: null, now: $friday);
		$resumed = $this->service->resume(uuid: $uuid, reason: null, actor: null, now: $monday);
		$after = $this->service->describe(timer: $resumed, now: $monday)['remaining'];

		self::assertEqualsWithDelta($before, $after, 0.0001, 'the weekend consumed nothing');
		self::assertEqualsWithDelta(10 - (4 + (8 / 24)), $after, 0.0001, 'four full days and 8 hours of Friday ran');
		$this->assertInvariants($resumed);
	}//end testABusinessDayTermSuspendedOverAWeekendKeepsItsBusinessDays()

	public function testSuspendAndResumeRefuseTheWrongState(): void {
		$this->task();
		$timer = $this->service->arm(config: $this->config(), actor: null, now: $this->at('2026-09-01 09:00'));
		try {
			$this->service->resume(uuid: (string)$timer->getUuid(), reason: null, actor: null);
			self::fail('resumed a running timer');
		} catch (FlowTimerStateException $refused) {
			self::assertStringContainsString("'armed', not 'suspended'", $refused->getMessage());
		}

		try {
			$this->service->suspend(uuid: (string)$timer->getUuid(), reason: '   ', until: null, actor: null);
			self::fail('suspended without a reason');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('non-empty reason', $refused->getMessage());
		}
	}//end testSuspendAndResumeRefuseTheWrongState()

	public function testTheSecondExtensionIsRefusedNamingTheBoundAndTheOverrideIsRecordedAsOne(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(config: $this->config(), actor: null, now: $start);
		$uuid = (string)$timer->getUuid();

		$extended = $this->service->extend(uuid: $uuid, amount: 7, unit: 'calendarDays', rationale: 'Verdaging: advies derde nodig', actor: 'carol', now: $start->modify('+10 days'));
		self::assertSame(1, $extended->getExtensionCount());
		self::assertSame('2026-11-03 09:00', $extended->getFireAt()->format('Y-m-d H:i'));
		$this->assertInvariants($extended);
		$events = $this->service->history(uuid: $uuid);
		self::assertSame(FlowTimerEvent::TYPE_EXTENDED, $events[1]->getType());
		self::assertSame('Awb 4:14', $events[1]->getBasis());
		self::assertSame('2026-10-27', $events[1]->getPriorFireAt()->format('Y-m-d'));
		self::assertSame('2026-11-03', $events[1]->getNewFireAt()->format('Y-m-d'));

		try {
			$this->service->extend(uuid: $uuid, amount: 7, unit: 'calendarDays', rationale: 'nog een keer', actor: 'carol', now: $start->modify('+11 days'));
			self::fail('a second extension went through the standard path');
		} catch (FlowTimerStateException $refused) {
			self::assertStringContainsString('extension bound of 1', $refused->getMessage());
		}

		self::assertSame('2026-11-03 09:00', $this->store->timers[$uuid]->getFireAt()->format('Y-m-d H:i'), 'the fire moment is unchanged');

		// Cross-unit extension: 2 business days on a calendar-day budget is 16 hours = 2/3 day.
		$overridden = $this->service->extendWithOverride(uuid: $uuid, amount: 2, unit: 'businessDays', rationale: 'Supervisor override', actor: 'supervisor', now: $start->modify('+12 days'));
		self::assertSame(2, $overridden->getExtensionCount());
		self::assertEqualsWithDelta(63 + (16 / 24), $overridden->getBudgetValue(), 0.0001);
		self::assertSame('override', $this->service->history(uuid: $uuid)[2]->getBasis());
		$this->assertInvariants($overridden);

		try {
			$this->service->extendWithOverride(uuid: $uuid, amount: 1, unit: 'hours', rationale: '', actor: 'supervisor', now: $start->modify('+12 days'));
			self::fail('extended without a rationale');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('non-empty rationale', $refused->getMessage());
		}

		try {
			$this->service->extendWithOverride(uuid: $uuid, amount: 1, unit: 'hours', rationale: 'x', actor: '  ', now: $start->modify('+12 days'));
			self::fail('an override went through without an authorizing identity');
		} catch (FlowTimerValidationException $refused) {
			self::assertStringContainsString('authorizing identity', $refused->getMessage());
		}
	}//end testTheSecondExtensionIsRefusedNamingTheBoundAndTheOverrideIsRecordedAsOne()

	public function testExtendingAfterExpiryIsRefusedAndTheBreachStays(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(
			config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'error', 'ladder' => null]),
			actor: null,
			now: $start
		);
		$uuid = (string)$timer->getUuid();

		// Past the fire moment but not yet swept: refused because the term has run out.
		try {
			$this->service->extend(uuid: $uuid, amount: 1, unit: 'calendarDays', rationale: 'te laat', actor: null, now: $start->modify('+57 days'));
			self::fail('extended a term that had run out');
		} catch (FlowTimerStateException $refused) {
			self::assertStringContainsString('has passed', $refused->getMessage());
		}

		self::assertTrue($this->service->fireExpiry(timer: $this->store->timers[$uuid], now: $start->modify('+57 days')));
		self::assertTrue($this->store->timers[$uuid]->getBreached());
		try {
			$this->service->extend(uuid: $uuid, amount: 1, unit: 'calendarDays', rationale: 'te laat', actor: null, now: $start->modify('+58 days'));
			self::fail('extended a fired timer');
		} catch (FlowTimerStateException $refused) {
			self::assertStringContainsString("its state is 'fired'", $refused->getMessage());
		}

		self::assertTrue($this->store->timers[$uuid]->getBreached(), 'the recorded breach remains recorded');
		self::assertSame(FlowTimerEvent::TYPE_BREACHED, $this->service->history(uuid: $uuid)[1]->getType());
	}//end testExtendingAfterExpiryIsRefusedAndTheBreachStays()

	public function testAMovedAnchorSupersedesTheTimerAndInheritsOnlyTheRungsStillInThePast(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(config: $this->config(), actor: null, now: $start);
		$uuid = (string)$timer->getUuid();

		// Day 50: the 14-day and 7-day rungs have both fired.
		self::assertSame(2, $this->service->fireRungs(timer: $this->store->timers[$uuid], now: $start->modify('+50 days')));
		self::assertCount(2, $this->store->fires);

		// The window is extended by 3 days: the anchoring event moves.
		$successor = $this->service->supersede(uuid: $uuid, anchorEventAt: $start->modify('+3 days'), reason: 'Bezwaartermijn verlengd', actor: 'carol', now: $start->modify('+50 days'));
		$prior = $this->store->timers[$uuid];

		self::assertSame(FlowTimer::STATE_SUPERSEDED, $prior->getState());
		self::assertNull($prior->getFireAt());
		self::assertSame($uuid, $successor->getSupersedesUuid());
		self::assertSame('2026-09-04 09:00', $successor->getAnchorAt()->format('Y-m-d H:i'));
		self::assertSame('2026-10-30 09:00', $successor->getFireAt()->format('Y-m-d H:i'));
		$this->assertInvariants($successor);

		// The 14-day rung (16 Oct) is still in the past under the new deadline: inherited.
		// The 7-day rung (23 Oct) moved back into the future: NOT inherited, it fires again legitimately.
		$inherited = array_filter($this->store->fires, static fn ($f): bool => $f->getTimerUuid() === $successor->getUuid());
		self::assertCount(1, $inherited);
		$row = array_values($inherited)[0];
		self::assertSame('preBreach:14:calendarDays', $row->getRungKey());
		self::assertTrue($row->getInherited());
		self::assertSame('2026-10-23 09:00', $successor->getNextRungAt()->format('Y-m-d H:i'));

		// The superseded row never fires: no scan returns it.
		$mapper = $this->store->timerMapper();
		self::assertSame([], $mapper->findDueExpiries(now: $start->modify('+400 days'), limit: 100));
		self::assertSame([$successor], $mapper->findDueRungs(now: $start->modify('+400 days'), limit: 100));
		self::assertSame([$successor], $mapper->findSuccessors(uuid: $uuid));
		self::assertSame(FlowTimerEvent::TYPE_SUPERSEDED, $this->service->history(uuid: $uuid)[1]->getType());
	}//end testAMovedAnchorSupersedesTheTimerAndInheritsOnlyTheRungsStillInThePast()

	public function testCompletingTheWorkCancelsBothTimersAndRaisesNothing(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$due = $this->service->arm(config: $this->config(), actor: null, now: $start);
		$expiry = $this->service->arm(config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'skip', 'sla' => ['value' => 70, 'unit' => 'calendarDays']]), actor: null, now: $start);
		self::assertSame('2026-11-10 09:00', $this->store->tasks['task-1']->getExpiresAt()->format('Y-m-d H:i'));

		$cancelled = $this->service->cancelForSubject(subjectType: 'task', subjectUuid: 'task-1', reason: "Task 'task-1' reached terminal state 'completed'.", actor: 'task:task-1', now: $start->modify('+10 days'));
		self::assertSame(2, $cancelled);
		foreach ([$due, $expiry] as $timer) {
			$row = $this->store->timers[(string)$timer->getUuid()];
			self::assertSame(FlowTimer::STATE_CANCELLED, $row->getState());
			self::assertStringContainsString('completed', (string)$row->getCancelReason());
			self::assertNotNull($row->getCancelledAt());
			self::assertNull($row->getFireAt());
		}

		self::assertNull($this->store->tasks['task-1']->getDueAt());
		self::assertNull($this->store->tasks['task-1']->getExpiresAt());
		self::assertSame(0, $this->service->cancelForSubject(subjectType: 'task', subjectUuid: 'task-1', reason: 'again', actor: null), 'idempotent');

		// Long after both would have been due: nothing is selected, nothing raised, nothing applied.
		$mapper = $this->store->timerMapper();
		$later = $start->modify('+200 days');
		self::assertSame([], $mapper->findDueExpiries(now: $later, limit: 10));
		self::assertSame([], $mapper->findDueRungs(now: $later, limit: 10));
		self::assertSame([], $this->dispatched);
		$this->taskService->expects(self::never())->method('applyTimerOutcome');
		self::assertCount(4, array_filter($this->store->events, static fn (FlowTimerEvent $e): bool => $e->getType() === FlowTimerEvent::TYPE_CANCELLED || $e->getType() === FlowTimerEvent::TYPE_ARMED));
	}//end testCompletingTheWorkCancelsBothTimersAndRaisesNothing()

	public function testARunTerminalityCancelsSubjectAndProvenanceTimers(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$this->service->arm(config: $this->config(['runUuid' => 'run-1']), actor: null, now: $start);
		$this->service->arm(config: $this->config(['subjectType' => 'run', 'subjectUuid' => 'run-1']), actor: null, now: $start);
		$this->service->arm(config: $this->config(['subjectType' => 'object', 'subjectUuid' => 'o-1']), actor: null, now: $start);

		self::assertSame(2, $this->service->cancelForRun(runUuid: 'run-1', reason: 'run failed', actor: 'flow-run:run-1', now: $start));
		self::assertSame(0, $this->service->cancelForRun(runUuid: '', reason: 'x', actor: null));
		self::assertCount(1, array_filter($this->store->timers, static fn (FlowTimer $t): bool => $t->getState() === FlowTimer::STATE_ARMED));
	}//end testARunTerminalityCancelsSubjectAndProvenanceTimers()

	public function testTheFourExpiryOutcomesAreAppliedAsDistinctNamedTaskActions(): void {
		$start = $this->at('2026-09-01 09:00');
		$applied = [];
		$this->taskService->method('applyTimerOutcome')->willReturnCallback(
			function (string $uuid, string $outcome, string $source, string $reason) use (&$applied): Task {
				$applied[$uuid] = [$outcome, $source];

				return $this->store->tasks[$uuid];
			}
		);

		$outcomes = ['skip', 'error', 'dead_letter', 'transition:approve'];
		foreach ($outcomes as $index => $outcome) {
			$this->task(uuid: 'task-' . $index);
			$this->service->arm(
				config: $this->config(['subjectUuid' => 'task-' . $index, 'purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => $outcome, 'ladder' => null]),
				actor: null,
				now: $start
			);
		}

		$due = $this->store->timerMapper()->findDueExpiries(now: $start->modify('+57 days'), limit: 10);
		self::assertCount(4, $due);
		foreach ($due as $timer) {
			self::assertTrue($this->service->fireExpiry(timer: $timer, now: $start->modify('+57 days')));
		}

		self::assertSame(
			['task-0' => 'skip', 'task-1' => 'error', 'task-2' => 'dead_letter', 'task-3' => 'transition:approve'],
			array_map(static fn (array $pair): string => $pair[0], $applied),
			'each outcome reaches the task service by its own name'
		);
		self::assertCount(4, array_unique(array_map(static fn (FlowTimerFiredEvent $e): string => $e->getTransition(), $this->dispatched)));
		foreach ($applied as $pair) {
			self::assertStringStartsWith('flow-timer:', $pair[1], 'the audit names the timer as actor');
		}
	}//end testTheFourExpiryOutcomesAreAppliedAsDistinctNamedTaskActions()

	public function testADueTimerPastItsMomentStaysArmedAndIsOverdueOnRead(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(config: $this->config(), actor: null, now: $start);
		$later = $start->modify('+60 days');

		self::assertSame([], $this->store->timerMapper()->findDueExpiries(now: $later, limit: 10), 'a due timer is never an expiry');
		$read = $this->service->describe(timer: $timer, now: $later);
		self::assertTrue($read['overdue']);
		self::assertEqualsWithDelta(4.0, $read['overdueBy'], 0.001);
		self::assertEqualsWithDelta(-4.0, $read['remaining'], 0.001);
		self::assertSame(FlowTimer::STATE_ARMED, $read['state']);
		// The work stays open: the task's state was never touched.
		self::assertSame(Task::STATE_ACTIVE, $this->store->tasks['task-1']->getState());
	}//end testADueTimerPastItsMomentStaysArmedAndIsOverdueOnRead()

	public function testASixWeekTimerSurvivesARestartAndFiresExactlyOnce(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$this->service->arm(
			config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'skip', 'sla' => ['value' => 42, 'unit' => 'calendarDays'], 'ladder' => null]),
			actor: null,
			now: $start
		);
		$this->taskService->method('applyTimerOutcome')->willReturnCallback(fn (string $uuid): Task => $this->store->tasks[$uuid]);

		// Before the moment: nothing to do.
		self::assertSame([], $this->store->timerMapper()->findDueExpiries(now: $start->modify('+41 days'), limit: 10));

		// "Restart": a fresh service over the same rows, first sweep after the moment.
		$this->service = $this->buildService();
		$due = $this->store->timerMapper()->findDueExpiries(now: $start->modify('+43 days'), limit: 10);
		self::assertCount(1, $due);
		self::assertTrue($this->service->fireExpiry(timer: $due[0], now: $start->modify('+43 days')));

		// Every later sweep: the row is fired, the scan is empty, a stale copy cannot re-fire it.
		self::assertSame([], $this->store->timerMapper()->findDueExpiries(now: $start->modify('+44 days'), limit: 10));
		self::assertFalse($this->service->fireExpiry(timer: $due[0], now: $start->modify('+44 days')), 'the conditional claim lost');
		self::assertCount(1, $this->dispatched);
	}//end testASixWeekTimerSurvivesARestartAndFiresExactlyOnce()

	public function testTwoOverlappingPassesFireAnExpiryOnce(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'error', 'ladder' => null]), actor: null, now: $start);
		$this->taskService->expects(self::once())->method('applyTimerOutcome')->willReturn($this->store->tasks['task-1']);

		// Both passes read the same armed row before either claims it.
		$copyA = $this->store->timerMapper()->findDueExpiries(now: $start->modify('+57 days'), limit: 10)[0];
		$copyB = clone $copyA;
		self::assertTrue($this->service->fireExpiry(timer: $copyA, now: $start->modify('+57 days')));
		self::assertFalse($this->service->fireExpiry(timer: $copyB, now: $start->modify('+57 days')));
		self::assertCount(1, $this->dispatched);
		self::assertSame(FlowTimer::STATE_FIRED, $this->store->timers[(string)$timer->getUuid()]->getState());
	}//end testTwoOverlappingPassesFireAnExpiryOnce()

	public function testATaskClosedConcurrentlyIsNothingToDo(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'error', 'ladder' => null]), actor: null, now: $start);
		$this->taskService->method('applyTimerOutcome')->willThrowException(new TaskConflictException('closed concurrently'));
		self::assertTrue($this->service->fireExpiry(timer: $timer, now: $start->modify('+57 days')));
		self::assertSame(FlowTimer::STATE_FIRED, $timer->getState());
	}//end testATaskClosedConcurrentlyIsNothingToDo()

	public function testADowntimeGapFiresTheSkippedRungsInOrderOnceEachAndAddressesTheGroup(): void {
		$this->task(performerType: Task::PERFORMER_GROUP, assignee: 'vergunningen');
		$start = $this->at('2026-09-01 09:00');
		$timer = $this->service->arm(config: $this->config(), actor: null, now: $start);

		// Deadline 5 days away, nothing fired yet: the 14-day and 7-day rungs both fire, in ladder order.
		$now = $start->modify('+51 days');
		self::assertSame([$timer], $this->store->timerMapper()->findDueRungs(now: $now, limit: 10));
		self::assertSame(2, $this->service->fireRungs(timer: $timer, now: $now));
		self::assertSame(
			['escalation:preBreach:14:calendarDays', 'escalation:preBreach:7:calendarDays'],
			array_map(static fn (FlowTimerFiredEvent $e): string => $e->getTransition(), $this->dispatched)
		);
		self::assertSame([['type' => 'group', 'id' => 'vergunningen', 'role' => 'handler']], $this->dispatched[0]->getRecipients(), 'the group path');
		self::assertSame('low', $this->dispatched[0]->getPriority());
		self::assertSame('termijn-7d', $this->dispatched[1]->getMessage());
		self::assertSame(['handler', 'teamleader'], $this->store->fires[1]->getRecipientRoles());
		self::assertSame('2026-10-25 09:00', $timer->getNextRungAt()->format('Y-m-d H:i'), 'next: the 2-day rung');
		$this->assertInvariants($timer);

		// The daily sweep does not repeat a rung.
		self::assertSame(0, $this->service->fireRungs(timer: $timer, now: $now->modify('+1 day')));
		self::assertCount(2, $this->dispatched);

		// Two concurrent passes on the 2-day rung: one claim wins.
		$rungDay = $start->modify('+54 days');
		$copy = clone $timer;
		self::assertSame(1, $this->service->fireRungs(timer: $timer, now: $rungDay));
		self::assertSame(0, $this->service->fireRungs(timer: $copy, now: $rungDay));
		self::assertCount(3, $this->dispatched);
		self::assertCount(3, $this->store->fires, 'one ledger row per rung, decided by the unique key');
	}//end testADowntimeGapFiresTheSkippedRungsInOrderOnceEachAndAddressesTheGroup()

	public function testTheOrganisationCalendarIsResolvedWhenTheTimerNamesNone(): void {
		// The seeded example-organisation calendar has 7 working hours and a local closure on 2026-10-05.
		$org = $this->calendars->resolve(calendarSlug: null, organisation: '00000000-0000-0000-0000-000000000000');
		self::assertSame('example-organisation', $org->getSlug());
		self::assertSame(7.0, $org->getHoursPerWorkingDay());
		self::assertFalse($org->isWorkingDay($this->at('2026-10-05 10:00')));

		self::assertSame(WorkingCalendar::DEFAULT_SLUG, $this->calendars->resolve(calendarSlug: null, organisation: 'unknown-org')->getSlug());
		self::assertSame(WorkingCalendar::DEFAULT_SLUG, $this->calendars->resolve(calendarSlug: null, organisation: null)->getSlug());
		self::assertSame('example-organisation', $this->calendars->resolve(calendarSlug: 'example-organisation', organisation: null)->getSlug());
		$this->calendars->reset();
		self::assertSame(WorkingCalendar::DEFAULT_SLUG, $this->calendars->resolve(calendarSlug: null, organisation: null)->getSlug());
	}//end testTheOrganisationCalendarIsResolvedWhenTheTimerNamesNone()

	public function testAnchorDatesArriveAsStringsOrDateTimesAndBadOnesAreNamed(): void {
		$this->task();
		$timer = $this->service->arm(
			config: $this->config([
				'anchorEventAt' => '2026-09-01T09:00:00+02:00',
				'title' => 'Beslistermijn',
				'metadata' => ['basis' => 'Awb 4:13'],
				'ladder' => null,
			]),
			actor: null,
			now: $this->at('2026-09-01 09:00')
		);
		self::assertSame('Beslistermijn', $timer->getTitle());
		self::assertSame(['basis' => 'Awb 4:13'], $timer->getMetadata());
		self::assertSame('2026-09-01 09:00', $timer->getAnchorAt()->format('Y-m-d H:i'));

		foreach ([['anchorEventAt' => 'not-a-date'], ['anchorEventAt' => 42], ['anchorOffset' => 'three'], ['anchorOffset' => 1, 'anchorOffsetUnit' => 'weeks']] as $bad) {
			try {
				$this->service->arm(config: $this->config($bad + ['ladder' => null]), actor: null, now: $this->at('2026-09-01 09:00'));
				self::fail('accepted ' . json_encode($bad));
			} catch (FlowTimerValidationException $refused) {
				self::assertNotSame('', $refused->getMessage());
			}
		}
	}//end testAnchorDatesArriveAsStringsOrDateTimesAndBadOnesAreNamed()

	public function testATerminalTimerCannotBeSuperseded(): void {
		$this->task();
		$timer = $this->service->arm(config: $this->config(['ladder' => null]), actor: null, now: $this->at('2026-09-01 09:00'));
		$this->service->cancelForSubject(subjectType: 'task', subjectUuid: 'task-1', reason: 'done', actor: null, now: $this->at('2026-09-02 09:00'));
		$this->expectException(FlowTimerStateException::class);
		$this->expectExceptionMessage("cannot be superseded: its state is 'cancelled'");
		$this->service->supersede(uuid: (string)$timer->getUuid(), anchorEventAt: $this->at('2026-09-03 09:00'), reason: 'moved', actor: null, now: $this->at('2026-09-02 10:00'));
	}//end testATerminalTimerCannotBeSuperseded()

	public function testAnAbsentTaskSubjectIsLoggedNotFatal(): void {
		// No task row exists for this subject: arming still works (the store is
		// subject-agnostic), the projection warns, the expiry outcome warns.
		$timer = $this->service->arm(
			config: $this->config(['subjectUuid' => 'task-gone', 'purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'skip', 'ladder' => null]),
			actor: null,
			now: $this->at('2026-09-01 09:00')
		);
		$this->taskService->expects(self::once())->method('applyTimerOutcome')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		self::assertTrue($this->service->fireExpiry(timer: $timer, now: $this->at('2026-10-28 09:00')));
		self::assertSame(FlowTimer::STATE_FIRED, $timer->getState());
	}//end testAnAbsentTaskSubjectIsLoggedNotFatal()

	public function testRungsFireOnlyOnAnArmedTimerWithAFireMoment(): void {
		$this->task();
		$timer = $this->service->arm(config: $this->config(), actor: null, now: $this->at('2026-09-01 09:00'));
		$this->service->suspend(uuid: (string)$timer->getUuid(), reason: 'pauze', until: null, actor: null, now: $this->at('2026-09-02 09:00'));
		self::assertSame(0, $this->service->fireRungs(timer: $this->store->timers[(string)$timer->getUuid()], now: $this->at('2026-12-01 09:00')), 'a suspended timer neither fires nor escalates');
		self::assertSame([], $this->dispatched);
	}//end testRungsFireOnlyOnAnArmedTimerWithAFireMoment()

	public function testASubjectMayCarryThreeDeadlinesWithDifferentMomentsIndependently(): void {
		$this->task();
		$start = $this->at('2026-09-01 09:00');
		$planned = $this->service->arm(config: $this->config(['legalEffect' => 'none', 'sla' => ['value' => 20, 'unit' => 'calendarDays'], 'ladder' => null]), actor: null, now: $start);
		$service = $this->service->arm(config: $this->config(['legalEffect' => 'servicenorm', 'sla' => ['value' => 40, 'unit' => 'calendarDays']]), actor: null, now: $start);
		$legal = $this->service->arm(config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'skip', 'sla' => ['value' => 56, 'unit' => 'calendarDays'], 'ladder' => null]), actor: null, now: $start);
		$this->taskService->method('applyTimerOutcome')->willReturn($this->store->tasks['task-1']);

		self::assertSame($planned->getFireAt()->getTimestamp(), $this->store->tasks['task-1']->getDueAt()->getTimestamp(), 'the earliest due timer projects');
		self::assertSame($legal->getFireAt()->getTimestamp(), $this->store->tasks['task-1']->getExpiresAt()->getTimestamp());

		// Each reached in turn: the planned date is overdue and silent, the service norm escalates, the legal term enforces.
		$mapper = $this->store->timerMapper();
		self::assertTrue($this->service->describe(timer: $planned, now: $start->modify('+21 days'))['overdue']);
		self::assertSame([$service], $mapper->findDueRungs(now: $start->modify('+27 days'), limit: 10), 'only the ladder-bearing timer has a rung due');
		self::assertSame(1, $this->service->fireRungs(timer: $service, now: $start->modify('+27 days')));
		self::assertSame([$legal], $mapper->findDueExpiries(now: $start->modify('+57 days'), limit: 10));
		self::assertTrue($this->service->fireExpiry(timer: $legal, now: $start->modify('+57 days')));

		self::assertSame(FlowTimer::STATE_ARMED, $this->store->timers[(string)$planned->getUuid()]->getState());
		self::assertSame(FlowTimer::STATE_ARMED, $this->store->timers[(string)$service->getUuid()]->getState());
		self::assertSame(FlowTimer::STATE_FIRED, $this->store->timers[(string)$legal->getUuid()]->getState());
		foreach ([$planned, $service] as $timer) {
			$this->assertInvariants($timer);
		}
	}//end testASubjectMayCarryThreeDeadlinesWithDifferentMomentsIndependently()
}//end class

<?php

/**
 * A moved object's timers follow the calendar its new schema names.
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
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerArithmetic;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerStorage;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSubjects;
use OCA\OpenRegister\Service\Flow\Timer\RecordTypeCalendars;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\TimerCalendarSwitch;
use OCA\OpenRegister\Service\Flow\Timer\TimerSuspension;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarScope;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `recalendarForSubject()`: which timers follow a move, and what they become.
 *
 * Schema `schema-a` names `example-organisation` (a local closing day on
 * 5 October 2026), schema `schema-b` names `nl-national`, schema `schema-c`
 * names none. Ten business days from Monday 28 September end on 12 October
 * under the national calendar and on 13 October under the local one.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerService
 * @covers \OCA\OpenRegister\Service\Flow\Timer\TimerCalendarSwitch
 */
class FlowTimerRecalendarTest extends TestCase {

	private InMemoryTimerStore $store;

	private FlowTimerService $service;

	private DateTimeZone $tz;

	protected function setUp(): void {
		parent::setUp();
		$this->tz    = new DateTimeZone('Europe/Amsterdam');
		$this->store = new InMemoryTimerStore(db: $this->createMock(IDBConnection::class));

		$definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$definitions->method('calendars')->willReturn(self::calendars());
		$definitions->method('ladders')->willReturn(EscalationLadderServiceTest::seededLadders());
		$recordTypes = $this->createMock(RecordTypeCalendars::class);
		$recordTypes->method('calendarOf')->willReturnCallback(
			static fn (string $schema): ?string => ['schema-a' => 'example-organisation', 'schema-b' => 'nl-national'][$schema] ?? null
		);
		$calculator = new SlaCalculator();
		$calendars  = new WorkingCalendarService(definitions: $definitions, recordTypes: $recordTypes);

		$this->service = new FlowTimerService(
			storage: new FlowTimerStorage(timers: $this->store->timerMapper(), fires: $this->store->fireMapper(), events: $this->store->eventMapper(), db: $this->createMock(IDBConnection::class)),
			subjects: new FlowTimerSubjects(tasks: $this->store->taskMapper(), taskService: $this->createMock(TaskService::class), dispatcher: $this->createMock(IEventDispatcher::class)),
			arithmetic: new FlowTimerArithmetic(
				calendars: $calendars,
				calculator: $calculator,
				ladder: new EscalationLadderService(definitions: $definitions, calculator: $calculator)
			),
			logger: new NullLogger()
		);
	}//end setUp()

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

	/**
	 * Arm a ten-business-day term on an object.
	 *
	 * @param array<string, mixed> $overrides Config overrides.
	 *
	 * @return FlowTimer
	 */
	private function arm(array $overrides=[]): FlowTimer {
		$start = $this->at('2026-09-28 09:00');
		$config = array_merge(
			[
				'subjectType' => 'object',
				'subjectUuid' => 'obj-1',
				'purpose' => 'due',
				'legalEffect' => 'servicenorm',
				'sla' => ['value' => 10, 'unit' => 'businessDays'],
				'anchorEventAt' => $start,
				'schema' => 'schema-a',
			],
			$overrides
		);

		return $this->service->arm(config: $config, act: FlowTimerAct::of(actor: null, now: $start));
	}//end arm()

	private function move(string $to, string $subject='obj-1'): int {
		return $this->service->recalendarForSubject(
			subject: new TimerSubjectRef(type: 'object', uuid: $subject),
			recordType: $to,
			change: new TimerChange(reason: 'moved', actor: 'alice', now: $this->at('2026-09-30 10:00'))
		);
	}//end move()

	/**
	 * The open timers of the object other than the given one.
	 *
	 * @param string $prior The superseded timer.
	 *
	 * @return array<int, FlowTimer>
	 */
	private function successorsOf(string $prior): array {
		return array_values(array_filter($this->store->timers, static fn (FlowTimer $t): bool => $t->getSupersedesUuid() === $prior));
	}//end successorsOf()

	public function testAnArmedTimerOnTheRecordTypesCalendarIsSupersededOntoTheTargetsCalendar(): void {
		$timer = $this->arm();
		$uuid  = (string)$timer->getUuid();
		self::assertSame('example-organisation', $timer->getCalendarSlug());
		self::assertSame('2026-10-13 09:00', $timer->getFireAt()->format('Y-m-d H:i'));

		self::assertSame(1, $this->move(to: 'schema-b'));

		$prior = $this->store->timers[$uuid];
		self::assertSame(FlowTimer::STATE_SUPERSEDED, $prior->getState());
		$superseded = array_values(array_filter($this->store->events, static fn (FlowTimerEvent $e): bool => $e->getTimerUuid() === $uuid && $e->getType() === FlowTimerEvent::TYPE_SUPERSEDED));
		self::assertCount(1, $superseded);
		self::assertSame('moved', $superseded[0]->getReason());
		self::assertSame('alice', $superseded[0]->getActor());

		$successors = $this->successorsOf(prior: $uuid);
		self::assertCount(1, $successors);
		$successor = $successors[0];
		self::assertSame(FlowTimer::STATE_ARMED, $successor->getState());
		self::assertSame('nl-national', $successor->getCalendarSlug());
		self::assertSame(
			['scope' => WorkingCalendarScope::SCOPE_RECORD_TYPE, 'recordType' => 'schema-b'],
			$successor->getMetadata()[WorkingCalendarScope::METADATA_KEY]
		);
		// The anchor did not move; the calendar under it did.
		self::assertSame('2026-09-28 09:00', $successor->getAnchorAt()->format('Y-m-d H:i'));
		self::assertSame('2026-09-28 09:00', $successor->getRunningSince()->format('Y-m-d H:i'));
		self::assertSame('2026-10-12 09:00', $successor->getFireAt()->format('Y-m-d H:i'));
	}

	public function testATargetWithoutACalendarFallsBackToTheUnitOrInstanceCalendar(): void {
		$uuid = (string)$this->arm()->getUuid();

		self::assertSame(1, $this->move(to: 'schema-c'));

		$successor = $this->successorsOf(prior: $uuid)[0];
		self::assertNull($successor->getCalendarSlug());
		self::assertArrayNotHasKey(WorkingCalendarScope::METADATA_KEY, ($successor->getMetadata() ?? []));
		self::assertSame(WorkingCalendarScope::SCOPE_TIMER, WorkingCalendarScope::ofTimer(timer: $successor)->namedBy);
		self::assertSame('2026-10-12 09:00', $successor->getFireAt()->format('Y-m-d H:i'));
	}

	public function testATimerWithItsOwnCalendarIsNotTouched(): void {
		$timer = $this->arm(['calendar' => 'example-organisation']);

		self::assertSame(0, $this->move(to: 'schema-b'));

		self::assertSame(FlowTimer::STATE_ARMED, $this->store->timers[(string)$timer->getUuid()]->getState());
		self::assertSame([], $this->successorsOf(prior: (string)$timer->getUuid()));
	}

	public function testATargetNamingTheSameCalendarChangesNothing(): void {
		$timer = $this->arm(['schema' => 'schema-b']);
		self::assertSame('nl-national', $timer->getCalendarSlug());

		self::assertSame(0, $this->move(to: 'schema-b'));

		self::assertSame(FlowTimer::STATE_ARMED, $this->store->timers[(string)$timer->getUuid()]->getState());
	}

	public function testASuspendedTimerKeepsItsSuspensionAndMovesToTheNewCalendar(): void {
		$timer = $this->arm();
		$uuid  = (string)$timer->getUuid();
		$this->service->suspend(
			suspension: new TimerSuspension(uuid: $uuid, reason: 'Wachten op aanvulling'),
			act: FlowTimerAct::of(actor: 'bob', now: $this->at('2026-09-29 09:00'))
		);

		self::assertSame(1, $this->move(to: 'schema-b'));

		$row = $this->store->timers[$uuid];
		self::assertSame(FlowTimer::STATE_SUSPENDED, $row->getState());
		self::assertSame('nl-national', $row->getCalendarSlug());
		self::assertSame('schema-b', $row->getMetadata()[WorkingCalendarScope::METADATA_KEY]['recordType']);
		self::assertSame([], $this->successorsOf(prior: $uuid));
	}

	public function testAnotherSubjectsTimerIsNotTouched(): void {
		$other = $this->arm(['subjectUuid' => 'obj-2']);

		self::assertSame(0, $this->move(to: 'schema-b'));

		self::assertSame(FlowTimer::STATE_ARMED, $this->store->timers[(string)$other->getUuid()]->getState());
	}

	public function testTheSwitchIgnoresATimerWhoseCalendarIsNotNamedByItsRecordType(): void {
		$timer = new FlowTimer();
		$timer->setCalendarSlug('example-organisation');

		self::assertNull(TimerCalendarSwitch::forTimer(timer: $timer, calendarSlug: 'nl-national', recordType: 'schema-b'));
	}
}//end class

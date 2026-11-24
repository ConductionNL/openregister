<?php

/**
 * The timer ledger names its calendar's zone (calendar-time-zone 2.3).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Db\FlowTimerEventMapperInterface;
use OCA\OpenRegister\Db\FlowTimerFireMapperInterface;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerArithmetic;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerStorage;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSubjects;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\TimerSuspension;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\FlowTimerEvent
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerService
 */
class FlowTimerLedgerTimeZoneTest extends TestCase {

	/**
	 * The ledger rows a suspension writes, the calendar resolving as given.
	 *
	 * @param WorkingCalendar|null $calendar The calendar, or null when it cannot be resolved.
	 *
	 * @return array<int, FlowTimerEvent>
	 */
	private function suspend(?WorkingCalendar $calendar): array {
		$calendars = $this->createMock(WorkingCalendarService::class);
		if ($calendar === null) {
			$calendars->method('resolve')->willReturnOnConsecutiveCalls(
				WorkingCalendar::fromArray(['slug' => 'nl-national', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5]]),
				$this->throwException(new RuntimeException('gone')),
				$this->throwException(new RuntimeException('gone'))
			);
		}

		if ($calendar !== null) {
			$calendars->method('resolve')->willReturn($calendar);
		}

		$calculator = $this->createMock(SlaCalculatorInterface::class);
		$calculator->method('measure')->willReturn(1.0);

		$timer = new FlowTimer();
		$timer->setUuid('t-1');
		$timer->setState(FlowTimer::STATE_ARMED);
		$timer->setCalendarSlug('nl-national');
		$timer->setBudgetUnit('workingDays');
		$timer->setBudgetValue(10.0);
		$timer->setConsumedValue(0.0);
		$timer->setRunningSince(new DateTime('2027-06-01T07:00:00+00:00'));
		$timer->setFireAt(new DateTime('2027-06-30T21:59:00+00:00'));

		$timers = $this->createMock(FlowTimerMapperInterface::class);
		$timers->method('findByUuid')->willReturn($timer);
		$timers->method('update')->willReturnArgument(0);
		$written = [];
		$events = $this->createMock(FlowTimerEventMapperInterface::class);
		$events->method('findByTimer')->willReturn([]);
		$events->method('insert')->willReturnCallback(
			function (FlowTimerEvent $event) use (&$written): FlowTimerEvent {
				$written[] = $event;
				return $event;
			}
		);

		$service = new FlowTimerService(
			new FlowTimerStorage($timers, $this->createMock(FlowTimerFireMapperInterface::class), $events, $this->createMock(IDBConnection::class)),
			new FlowTimerSubjects($this->createMock(TaskMapper::class), $this->createMock(TaskServiceInterface::class), $this->createMock(IEventDispatcher::class)),
			new FlowTimerArithmetic($calendars, $calculator, $this->createMock(EscalationLadderService::class)),
			new NullLogger()
		);
		$service->suspend(new TimerSuspension(uuid: 't-1', reason: 'Waiting for the applicant'), new FlowTimerAct(actor: 'admin', now: new DateTimeImmutable('2027-06-02T07:00:00+00:00')));

		return $written;
	}//end suspend()

	public function testALedgerRowNamesTheCalendarZoneAndItsLocalDates(): void {
		$rows = $this->suspend(WorkingCalendar::fromArray(['slug' => 'nl-national', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5], 'timezone' => 'Europe/Amsterdam']));

		$this->assertCount(1, $rows);
		$this->assertSame('Europe/Amsterdam', $rows[0]->getTimeZone());
		$json = $rows[0]->jsonSerialize();
		$this->assertSame('Europe/Amsterdam', $json['timeZone']);
		$this->assertSame('2027-06-30 23:59 Europe/Amsterdam', $json['priorFireAtLocal']);
		$this->assertNull($json['newFireAtLocal']);
	}//end testALedgerRowNamesTheCalendarZoneAndItsLocalDates()

	public function testARowIsStillWrittenWhenTheCalendarCannotBeResolved(): void {
		$rows = $this->suspend(null);

		$this->assertCount(1, $rows);
		$this->assertNull($rows[0]->getTimeZone());
		$this->assertArrayNotHasKey('priorFireAtLocal', $rows[0]->jsonSerialize());
	}//end testARowIsStillWrittenWhenTheCalendarCannotBeResolved()

	public function testARowWithAnUnknownZoneCarriesNoLocalDates(): void {
		$event = new FlowTimerEvent();
		$event->setNewFireAt(new DateTime('2027-01-14T23:30:00+00:00'));
		$event->setTimeZone('Mars/Olympus');

		$this->assertArrayNotHasKey('newFireAtLocal', $event->jsonSerialize());

		$event->setTimeZone('Europe/Amsterdam');
		$this->assertSame('2027-01-15 00:30 Europe/Amsterdam', $event->jsonSerialize()['newFireAtLocal']);
	}//end testARowWithAnUnknownZoneCarriesNoLocalDates()
}//end class

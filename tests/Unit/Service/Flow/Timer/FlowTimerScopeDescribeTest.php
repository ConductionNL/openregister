<?php

/**
 * Unit tests for describing a timer against the calendar its scope resolves.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEventMapperInterface;
use OCA\OpenRegister\Db\FlowTimerFireMapperInterface;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\CalendarSpan;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerArithmetic;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerStorage;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSubjects;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The scope pairs the timer with the calendar of its own slug and organisation; overdue and remaining are measured on it.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerScope
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerService
 */
class FlowTimerScopeDescribeTest extends TestCase {

	/**
	 * The measurements asked of the calculator: [from, to, unit, same calendar?].
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: bool}>
	 */
	private array $measured = [];

	/**
	 * Describe a timer in a service whose calendar service resolves `gemeente` for `org-1`.
	 *
	 * @param FlowTimer $timer The timer.
	 * @param string    $now   The moment, UTC.
	 *
	 * @return array<string, mixed> The description.
	 */
	private function describe(FlowTimer $timer, string $now): array {
		$calendar  = WorkingCalendar::fromArray(['slug' => 'gemeente', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5]]);
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects($this->once())->method('resolve')->with('gemeente', 'org-1')->willReturn($calendar);

		$calculator = $this->createMock(SlaCalculatorInterface::class);
		$calculator->method('measure')->willReturnCallback(
			function (CalendarSpan $span, string $unit) use ($calendar): float {
				$this->measured[] = [$span->from->format('Y-m-d'), $span->to->format('Y-m-d'), $unit, $span->calendar === $calendar];
				return $span->from->format('Y-m-d') === '2026-09-10' ? 2.0 : 9.0;
			}
		);

		$service = new FlowTimerService(
			new FlowTimerStorage(
				$this->createMock(FlowTimerMapperInterface::class),
				$this->createMock(FlowTimerFireMapperInterface::class),
				$this->createMock(FlowTimerEventMapperInterface::class),
				$this->createMock(IDBConnection::class)
			),
			new FlowTimerSubjects($this->createMock(TaskMapper::class), $this->createMock(TaskServiceInterface::class), $this->createMock(IEventDispatcher::class)),
			new FlowTimerArithmetic($calendars, $calculator, $this->createMock(EscalationLadderService::class)),
			new NullLogger()
		);

		return $service->describe($timer, new DateTimeImmutable($now));

	}//end describe()

	/**
	 * A timer on calendar `gemeente` of `org-1`, budget 10 working days with 1 consumed.
	 *
	 * @param string $state The state.
	 *
	 * @return FlowTimer
	 */
	private function timer(string $state): FlowTimer {
		$timer = new FlowTimer();
		$timer->setState($state);
		$timer->setCalendarSlug('gemeente');
		$timer->setOrganisation('org-1');
		$timer->setBudgetUnit('workingDays');
		$timer->setBudgetValue(10.0);
		$timer->setConsumedValue(1.0);
		$timer->setRunningSince(new DateTime('2026-09-01T09:00:00+00:00'));
		$timer->setFireAt(new DateTime('2026-09-10T09:00:00+00:00'));

		return $timer;

	}//end timer()

	/**
	 * An armed timer past its deadline is overdue, measured on the scope's calendar.
	 *
	 * @return void
	 */
	public function testAnArmedTimerPastItsDeadlineIsMeasuredOnItsCalendar(): void {
		$described = $this->describe($this->timer(FlowTimer::STATE_ARMED), '2026-09-12T09:00:00+00:00');

		$this->assertTrue($described['overdue']);
		$this->assertSame(2.0, $described['overdueBy']);
		$this->assertSame(0.0, $described['remaining'], '10 budgeted - 1 consumed - 9 running');
		$this->assertSame(
			[['2026-09-10', '2026-09-12', 'workingDays', true], ['2026-09-01', '2026-09-12', 'workingDays', true]],
			$this->measured
		);

	}//end testAnArmedTimerPastItsDeadlineIsMeasuredOnItsCalendar()

	/**
	 * A suspended timer is never overdue and its clock is not running.
	 *
	 * @return void
	 */
	public function testASuspendedTimerIsNotOverdue(): void {
		$described = $this->describe($this->timer(FlowTimer::STATE_SUSPENDED), '2026-09-12T09:00:00+00:00');

		$this->assertFalse($described['overdue']);
		$this->assertNull($described['overdueBy']);
		$this->assertSame(9.0, $described['remaining']);
		$this->assertSame([], $this->measured);

	}//end testASuspendedTimerIsNotOverdue()
}//end class

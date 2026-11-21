<?php

/**
 * Unit tests for the local dates a timer description reports beside its UTC instants (calendar-time-zone 2.3).
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
 * A description names the calendar's zone and formats each reported date in it.
 *
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerScope
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerService
 * @uses \OCA\OpenRegister\Db\FlowTimer
 * @uses \OCA\OpenRegister\Service\Flow\Timer\CalendarSpan
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerArithmetic
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerDeadlines
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerStorage
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerSubjects
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerValues
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class FlowTimerDescribeTimeZoneTest extends TestCase {

	/**
	 * Describe an armed timer on a calendar declaring the given zone.
	 *
	 * @param string|null $zone     The calendar's zone, or null for none declared.
	 * @param string      $fireAt   The timer's fire moment, UTC.
	 * @param string|null $unrolled The pre-roll moment, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(?string $zone, string $fireAt, ?string $unrolled = null): array {
		$definition = ['slug' => 'nl-national', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5]];
		if ($zone !== null) {
			$definition['timezone'] = $zone;
		}

		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->method('resolve')->willReturn(WorkingCalendar::fromArray($definition));
		$calculator = $this->createMock(SlaCalculatorInterface::class);
		$calculator->method('measure')->willReturn(0.0);

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

		$timer = new FlowTimer();
		$timer->setState(FlowTimer::STATE_ARMED);
		$timer->setCalendarSlug('nl-national');
		$timer->setBudgetUnit('workingDays');
		$timer->setBudgetValue(10.0);
		$timer->setConsumedValue(0.0);
		$timer->setFireAt(new DateTime($fireAt));
		if ($unrolled !== null) {
			$timer->setUnrolledAt(new DateTime($unrolled));
		}

		return $service->describe($timer, new DateTimeImmutable('2027-06-01T09:00:00+00:00'));
	}//end describe()

	/**
	 * The spec scenario: 21:59Z on 30 June reads 23:59 in Amsterdam, beside the UTC instant.
	 *
	 * @return void
	 */
	public function testAHandlerSeesTheLocalDateBesideTheInstant(): void {
		$described = $this->describe('Europe/Amsterdam', '2027-06-30T21:59:00+00:00', '2027-06-28T22:30:00+00:00');

		$this->assertSame('2027-06-30T21:59:00+00:00', $described['fireAt']);
		$this->assertSame('Europe/Amsterdam', $described['timeZone']);
		$this->assertSame('2027-06-30 23:59 Europe/Amsterdam', $described['fireAtLocal']);
		$this->assertSame('2027-06-29 00:30 Europe/Amsterdam', $described['unrolledAtLocal']);
	}//end testAHandlerSeesTheLocalDateBesideTheInstant()

	/**
	 * Winter time is an hour less; a calendar naming no zone reports in UTC; no instant, no local date.
	 *
	 * @return void
	 */
	public function testTheOffsetFollowsTheDateAndTheDefaultIsUtc(): void {
		$this->assertSame('2027-01-15 00:30 Europe/Amsterdam', $this->describe('Europe/Amsterdam', '2027-01-14T23:30:00+00:00')['fireAtLocal']);

		$utc = $this->describe(null, '2027-01-14T23:30:00+00:00');
		$this->assertSame('UTC', $utc['timeZone']);
		$this->assertSame('2027-01-14 23:30 UTC', $utc['fireAtLocal']);
		$this->assertNull($utc['unrolledAtLocal']);
	}//end testTheOffsetFollowsTheDateAndTheDefaultIsUtc()
}//end class

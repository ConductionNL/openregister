<?php

/**
 * CalendarRecompute and CalendarDependency: a timer naming the changed calendar, a projection that cannot be made, and a supersession that fails without abandoning the batch.
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
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\CalendarBatch;
use OCA\OpenRegister\Service\Flow\Timer\CalendarDependency;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecomputeSources;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute
 * @covers \OCA\OpenRegister\Service\Flow\Timer\CalendarDependency
 * @uses \OCA\OpenRegister\Service\Flow\Timer\CalendarRecomputeSources
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaAddition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\MomentShift
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Db\FlowTimer
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 */
class CalendarRecomputeFailureBehaviourTest extends TestCase {

	private WorkingCalendar $calendar;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function resolving(): WorkingCalendarService&MockObject {
		$service = $this->createMock(WorkingCalendarService::class);
		$service->method('resolve')->willReturn($this->calendar);

		return $service;
	}//end resolving()

	private function failing(): WorkingCalendarService&MockObject {
		$service = $this->createMock(WorkingCalendarService::class);
		$service->method('resolve')->willThrowException(new FlowTimerValidationException(message: 'calendar gone'));

		return $service;
	}//end failing()

	private function recompute(WorkingCalendarService $forDependency, WorkingCalendarService $forProjection): CalendarRecompute {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new CalendarRecompute(
			sources: new CalendarRecomputeSources(
				dependency: new CalendarDependency(calendars: $forDependency),
				calendars: $forProjection,
				calculator: new SlaCalculator()
			),
			appConfig: $appConfig,
			logger: $this->logger
		);
	}//end recompute()

	private function armed(string $uuid, ?string $runningSince = '2027-05-03 09:00:00', ?string $fireAt = '2027-05-04 09:00:00'): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setState(FlowTimer::STATE_ARMED);
		$timer->setBudgetValue(1.0);
		$timer->setBudgetUnit('calendarDays');
		$timer->setConsumedValue(0.0);
		$timer->setCalendarSlug('nl-national');
		if ($runningSince !== null) {
			$timer->setRunningSince(new DateTime($runningSince));
		}

		if ($fireAt !== null) {
			$timer->setFireAt(new DateTime($fireAt));
		}

		return $timer;
	}//end armed()

	public function testATimerNamingTheChangedCalendarDependsWithoutALookup(): void {
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects(self::never())->method('resolve');
		$dependency = new CalendarDependency(calendars: $calendars);

		self::assertSame(CalendarDependency::DEPENDS, $dependency->verdictFor(timer: $this->armed('t-1'), changedSlug: 'nl-national'));
	}//end testATimerNamingTheChangedCalendarDependsWithoutALookup()

	public function testABlankChangedSlugNeverMatchesAnUnnamedTimerByName(): void {
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects(self::once())->method('resolve')->willReturn($this->calendar);
		$timer = $this->armed('t-1');
		$timer->setCalendarSlug(null);

		self::assertSame(
			CalendarDependency::INDEPENDENT,
			(new CalendarDependency(calendars: $calendars))->verdictFor(timer: $timer, changedSlug: '')
		);
	}//end testABlankChangedSlugNeverMatchesAnUnnamedTimerByName()

	public function testAnArmedTimerThatCannotBeProjectedIsUnresolvableAndNotSuperseded(): void {
		$superseded = [];
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('timer t-1 could not be projected against nl-national: calendar gone'));

		$counts = $this->recompute(forDependency: $this->resolving(), forProjection: $this->failing())->recompute(batch: new CalendarBatch(
			slug: 'nl-national',
			version: 'v2',
			timers: [$this->armed('t-1')],
			supersede: static function (FlowTimer $timer) use (&$superseded): void {
				$superseded[] = $timer->getUuid();
			}
		));

		self::assertSame(1, $counts['unresolvable']);
		self::assertSame(0, $counts['moved']);
		self::assertSame([], $superseded);
	}//end testAnArmedTimerThatCannotBeProjectedIsUnresolvableAndNotSuperseded()

	public function testAnArmedTimerWithoutARunningStartHasNoProjection(): void {
		$this->logger->expects(self::never())->method('warning');
		$recompute = $this->recompute(forDependency: $this->resolving(), forProjection: $this->resolving());

		self::assertNull($recompute->projectedFireAt(timer: $this->armed('t-1', runningSince: null), slug: 'nl-national'));
		self::assertSame(
			strtotime('2027-05-04 09:00:00'),
			$recompute->projectedFireAt(timer: $this->armed('t-2'), slug: 'nl-national')
		);
	}//end testAnArmedTimerWithoutARunningStartHasNoProjection()

	public function testAFailedSupersessionIsCountedAndTheBatchCarriesOn(): void {
		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains('timer t-1 could not be superseded for nl-national: locked'));
		$superseded = [];

		$counts = $this->recompute(forDependency: $this->resolving(), forProjection: $this->resolving())->recompute(batch: new CalendarBatch(
			slug: 'nl-national',
			version: 'v2',
			// Both stored moments differ from the projection, so both have moved.
			timers: [$this->armed('t-1', fireAt: '2027-05-10 09:00:00'), $this->armed('t-2', fireAt: '2027-05-10 09:00:00')],
			supersede: static function (FlowTimer $timer) use (&$superseded): void {
				if ($timer->getUuid() === 't-1') {
					throw new RuntimeException('locked');
				}

				$superseded[] = $timer->getUuid();
			}
		));

		self::assertSame(2, $counts['examined']);
		self::assertSame(1, $counts['unresolvable']);
		self::assertSame(1, $counts['moved']);
		self::assertSame(['t-2'], $superseded);
	}//end testAFailedSupersessionIsCountedAndTheBatchCarriesOn()
}//end class

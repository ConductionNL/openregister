<?php

/**
 * Unit tests for DeadlineDateResolver.
 *
 * The point of this class is that the feed never publishes a date the term
 * engine would not enforce, so the three branches are asserted separately:
 * the engine's own number wins, a raw date lands on a working day, and a
 * calendar that does not resolve publishes nothing at all rather than a
 * plausible weekday.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Calendar\DeadlineDateResolver;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DeadlineDateResolverTest extends TestCase {

	private FlowTimerMapper&MockObject $timers;
	private WorkingCalendarService&MockObject $calendars;
	private LoggerInterface&MockObject $logger;
	private DeadlineDateResolver $resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->timers = $this->createMock(FlowTimerMapper::class);
		$this->calendars = $this->createMock(WorkingCalendarService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->resolver = new DeadlineDateResolver(
			timers: $this->timers,
			calendars: $this->calendars,
			logger: $this->logger
		);
	}

	/** A five-day week with no holidays, enough to move a Saturday. */
	private function weekdayCalendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(
			[
				'slug' => 'test-weekdays',
				'hoursPerWorkingDay' => 8,
				'workingWeekdays' => [1, 2, 3, 4, 5],
				'rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'Nieuwjaarsdag']],
				'exceptions' => [],
			]
		);
	}

	private function declaration(array $overrides = []): ObjectDateDeclaration {
		return ObjectDateDeclaration::fromArray(
			property: 'beslistermijn',
			config: array_merge(['kind' => 'deadline'], $overrides)
		);
	}

	public function testTheEngineSNumberWinsOverTheRawDate(): void {
		$timer = new FlowTimer();
		$timer->setPurpose(FlowTimer::PURPOSE_DUE);
		$timer->setFireAt(new DateTime('2026-10-15T09:00:00+02:00'));

		$this->timers->expects($this->once())
			->method('findBySubject')
			->with('object', 'object-uuid', [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED])
			->willReturn([$timer]);

		$this->calendars->expects($this->never())->method('resolve');

		$resolved = $this->resolver->resolve(
			objectUuid: 'object-uuid',
			declaration: $this->declaration(['timerPurpose' => 'due']),
			rawDate: new DateTimeImmutable('2026-10-01T00:00:00+02:00'),
			organisation: null
		);

		$this->assertNotNull($resolved);
		$this->assertSame('2026-10-15', $resolved->format('Y-m-d'));
	}

	public function testATimerOfAnotherPurposeIsNotTheAnswer(): void {
		$timer = new FlowTimer();
		$timer->setPurpose(FlowTimer::PURPOSE_EXPIRY);
		$timer->setFireAt(new DateTime('2026-10-15T09:00:00+02:00'));

		$this->timers->method('findBySubject')->willReturn([$timer]);
		$this->calendars->method('resolve')->willReturn($this->weekdayCalendar());

		$resolved = $this->resolver->resolve(
			objectUuid: 'object-uuid',
			declaration: $this->declaration(['timerPurpose' => 'due']),
			rawDate: new DateTimeImmutable('2026-10-20T00:00:00+02:00'),
			organisation: null
		);

		$this->assertNotNull($resolved);
		$this->assertSame('2026-10-20', $resolved->format('Y-m-d'));
	}

	public function testARawDateOnAClosureDayMovesToTheDayWorkResumes(): void {
		$this->timers->expects($this->never())->method('findBySubject');
		$this->calendars->expects($this->once())
			->method('resolve')
			->with(null, 'org-uuid')
			->willReturn($this->weekdayCalendar());

		// 17 October 2026 is a Saturday.
		$resolved = $this->resolver->resolve(
			objectUuid: 'object-uuid',
			declaration: $this->declaration(),
			rawDate: new DateTimeImmutable('2026-10-17T00:00:00+02:00'),
			organisation: 'org-uuid'
		);

		$this->assertNotNull($resolved);
		$this->assertSame('2026-10-19', $resolved->format('Y-m-d'));
	}

	public function testAWorkingDayIsLeftWhereItIs(): void {
		$this->calendars->method('resolve')->willReturn($this->weekdayCalendar());

		$resolved = $this->resolver->resolve(
			objectUuid: 'object-uuid',
			declaration: $this->declaration(),
			rawDate: new DateTimeImmutable('2026-10-20T00:00:00+02:00'),
			organisation: null
		);

		$this->assertNotNull($resolved);
		$this->assertSame('2026-10-20', $resolved->format('Y-m-d'));
	}

	public function testACalendarThatDoesNotResolvePublishesNothing(): void {
		$this->calendars->method('resolve')
			->willThrowException(new FlowTimerValidationException(message: "Working calendar 'typo' does not exist"));

		$resolved = $this->resolver->resolve(
			objectUuid: 'object-uuid',
			declaration: $this->declaration(['calendar' => 'typo']),
			rawDate: new DateTimeImmutable('2026-10-17T00:00:00+02:00'),
			organisation: null
		);

		$this->assertNull($resolved);
	}

	public function testNoDateAndNoTimerPublishesNothing(): void {
		$resolved = $this->resolver->resolve(
			objectUuid: 'object-uuid',
			declaration: $this->declaration(),
			rawDate: null,
			organisation: null
		);

		$this->assertNull($resolved);
	}
}

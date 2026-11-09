<?php

/**
 * Pins what the calendar-change recompute job does with its argument.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\RecomputeTimersForCalendarJob;
use OCA\OpenRegister\BackgroundJob\RecomputeTimersForCalendarJobServices;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowTimerControlInterface;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\RecomputeTimersForCalendarJob
 */
final class RecomputeTimersForCalendarJobTest extends TestCase {

	/**
	 * The timers.
	 *
	 * @var FlowTimerMapperInterface&MockObject
	 */
	private FlowTimerMapperInterface&MockObject $timers;

	/**
	 * The supersession path.
	 *
	 * @var FlowTimerControlInterface&MockObject
	 */
	private FlowTimerControlInterface&MockObject $service;

	/**
	 * The rule.
	 *
	 * @var CalendarRecompute&MockObject
	 */
	private CalendarRecompute&MockObject $recompute;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timers    = $this->createMock(FlowTimerMapperInterface::class);
		$this->service   = $this->createMock(FlowTimerControlInterface::class);
		$this->recompute = $this->createMock(CalendarRecompute::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The job under test.
	 *
	 * @return RecomputeTimersForCalendarJob
	 */
	private function job(): RecomputeTimersForCalendarJob {
		return new RecomputeTimersForCalendarJob(
			$this->createMock(ITimeFactory::class),
			new RecomputeTimersForCalendarJobServices($this->timers, $this->service, $this->recompute),
			$this->logger
		);
	}//end job()

	/**
	 * Run the protected run() with an argument.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function runWith(mixed $argument): void {
		(new ReflectionMethod(RecomputeTimersForCalendarJob::class, 'run'))->invoke($this->job(), $argument);
	}//end runWith()

	/**
	 * A missing slug or version does nothing but warn.
	 *
	 * @return void
	 */
	public function testAnIncompleteArgumentDoesNothing(): void {
		$this->recompute->expects($this->never())->method('recomputeBatch');
		$this->recompute->expects($this->never())->method('markRan');
		$this->logger->expects($this->exactly(3))->method('warning');

		$this->runWith(['slug' => 'nl', 'version' => ' ']);
		$this->runWith(['version' => '1.0.0']);
		$this->runWith('not an array');
	}//end testAnIncompleteArgumentDoesNothing()

	/**
	 * Every open timer is offered, superseded with the calendar reason, and the version is marked.
	 *
	 * @return void
	 */
	public function testTheOpenTimersAreSupersededAndTheVersionMarked(): void {
		$armed = new FlowTimer();
		$armed->setId(7);
		$armed->setUuid('t-armed');
		$armed->setAnchorAt(new DateTime('2026-01-05T09:00:00+00:00'));
		$this->timers->method('findByStatePaged')->willReturnCallback(
			static fn (string $state, int $afterId, int $limit): array => ($state === FlowTimer::STATE_ARMED && $afterId === 0 && $limit === 500) ? [$armed] : []
		);
		$this->service->expects($this->once())->method('supersede')->with(
			't-armed',
			$armed->getAnchorAt(),
			'calendar-changed',
			'calendar-recompute'
		);
		$this->recompute->expects($this->once())->method('recomputeBatch')->willReturnCallback(
			function (string $slug, string $version, iterable $timers, callable $supersede): array {
				$this->assertSame('nl', $slug);
				$this->assertSame('2.0.0', $version);
				foreach ($timers as $timer) {
					$supersede($timer);
				}

				return ['examined' => 1, 'moved' => 1, 'unchanged' => 0, 'unresolvable' => 0, 'deferred' => 0, 'skipped' => false];
			}
		);
		$this->recompute->expects($this->once())->method('markRan')->with('nl', '2.0.0');

		$this->runWith(['slug' => ' nl ', 'version' => '2.0.0']);
	}//end testTheOpenTimersAreSupersededAndTheVersionMarked()

	/**
	 * A skipped pass and a failed pass are not marked.
	 *
	 * @return void
	 */
	public function testASkippedOrFailedPassIsNotMarked(): void {
		$this->recompute->method('recomputeBatch')->willReturnOnConsecutiveCalls(
			['examined' => 0, 'moved' => 0, 'unchanged' => 0, 'unresolvable' => 0, 'deferred' => 0, 'skipped' => true],
			$this->throwException(new RuntimeException('boom'))
		);
		$this->recompute->expects($this->never())->method('markRan');
		$this->logger->expects($this->once())->method('error');

		$this->runWith(['slug' => 'nl', 'version' => '2.0.0']);
		$this->runWith(['slug' => 'nl', 'version' => '2.0.0']);
	}//end testASkippedOrFailedPassIsNotMarked()
}//end class

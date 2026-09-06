<?php

/**
 * Release layer 1: a terminal run lets go of its object locks.
 *
 * Each of the FOUR terminal statuses is proven SEPARATELY. Asserting only the
 * happy path would leave the cases that matter most untested: a run that
 * completes is also a run that reached an unlock step, while a run that
 * failed, stopped or was dead-lettered is precisely the one that did not.
 *
 * NOTE for anyone reading the brief that commissioned this: there is no
 * `cancelled` run status. `FlowRun::TERMINAL` is completed, stopped, failed
 * and dead_letter; a cancellation and a queue-TTL expiry both land on
 * `failed` with an explanatory error. The four below are the four that exist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Listener\FlowRunLockReleaseListener;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\FlowRunLockReleaseListener
 */
final class FlowRunLockReleaseListenerTest extends TestCase {

	private const RUN = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	/**
	 * Every terminal status the engine can actually reach.
	 *
	 * Read from the PRODUCTION constant rather than typed out, so a status
	 * added to `FlowRun::TERMINAL` without a release arrives here as a
	 * failure instead of as silence.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function terminalStatuses(): array {
		$cases = [];
		foreach (FlowRun::TERMINAL as $status) {
			$cases[$status] = [$status];
		}

		return $cases;
	}//end terminalStatuses()

	/**
	 * A run releases its locks on every terminal status, not just the happy one.
	 *
	 * @param string $status The terminal status.
	 *
	 * @return void
	 *
	 * @dataProvider terminalStatuses
	 */
	public function testEveryTerminalStatusReleasesTheRunsLocks(string $status): void {
		$locks = $this->createMock(originalClassName: RunLockRegistry::class);
		$locks->expects($this->once())
			->method('releaseRunLocks')
			->with(self::RUN)
			->willReturn(1);

		$listener = new FlowRunLockReleaseListener($locks, $this->createMock(LoggerInterface::class));
		$listener->handle(new FlowRunTerminalEvent(runUuid: self::RUN, status: $status));
	}//end testEveryTerminalStatusReleasesTheRunsLocks()

	/**
	 * The four statuses covered above really are all of them.
	 *
	 * A guard on the data provider itself: if `FlowRun::TERMINAL` grows and
	 * nobody notices, the count moves and this fails.
	 *
	 * @return void
	 */
	public function testThereAreExactlyFourTerminalStatuses(): void {
		$this->assertSame(
			['completed', 'stopped', 'dead_letter', 'failed'],
			FlowRun::TERMINAL,
			'a terminal status was added or renamed; the release must cover it'
		);
	}//end testThereAreExactlyFourTerminalStatuses()

	/**
	 * The listener is idempotent: terminality can be observed twice.
	 *
	 * @return void
	 */
	public function testASecondObservationIsHarmless(): void {
		$locks = $this->createMock(originalClassName: RunLockRegistry::class);
		$locks->expects($this->exactly(2))
			->method('releaseRunLocks')
			->with(self::RUN)
			->willReturnOnConsecutiveCalls(2, 0);

		$listener = new FlowRunLockReleaseListener($locks, $this->createMock(LoggerInterface::class));
		$event = new FlowRunTerminalEvent(runUuid: self::RUN, status: FlowRun::STATUS_COMPLETED);
		$listener->handle($event);
		$listener->handle($event);
	}//end testASecondObservationIsHarmless()

	/**
	 * A failed release MUST NOT propagate.
	 *
	 * The dispatch happens inside FlowRunCommit's open transaction on the
	 * stream-walk path, so a throw here would unwind the run's own terminal
	 * write. Lock bookkeeping must never be able to do that.
	 *
	 * @return void
	 */
	public function testAFailedReleaseDoesNotUnwindTheRunsTerminalWrite(): void {
		$locks = $this->createMock(originalClassName: RunLockRegistry::class);
		$locks->method('releaseRunLocks')->willThrowException(new RuntimeException('database went away'));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$listener = new FlowRunLockReleaseListener($locks, $logger);
		$listener->handle(new FlowRunTerminalEvent(runUuid: self::RUN, status: FlowRun::STATUS_FAILED));

		$this->addToAssertionCount(1);
	}//end testAFailedReleaseDoesNotUnwindTheRunsTerminalWrite()

	/**
	 * Another event type is ignored.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$locks = $this->createMock(originalClassName: RunLockRegistry::class);
		$locks->expects($this->never())->method('releaseRunLocks');

		$listener = new FlowRunLockReleaseListener($locks, $this->createMock(LoggerInterface::class));
		$listener->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()
}//end class

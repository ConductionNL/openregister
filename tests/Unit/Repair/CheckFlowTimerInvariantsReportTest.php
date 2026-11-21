<?php

/**
 * Characterisation: the lines CheckFlowTimerInvariants prints and logs when
 * the check cannot run, when the store is clean and when it holds defects.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Repair\CheckFlowTimerInvariants;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Records every output and log line of one run.
 */
class CheckFlowTimerInvariantsReportTest extends TestCase {

	/**
	 * Every output and log line, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Run the check over a timer table that answers $page for the armed state, or throws.
	 *
	 * @param array<int, FlowTimer>|null $armed The armed timers, or null for a table that throws.
	 *
	 * @return array<int, string> The call log.
	 */
	private function check(?array $armed): array {
		$this->calls = [];
		$timers = $this->createMock(FlowTimerMapperInterface::class);
		$timers->method('findByStatePaged')->willReturnCallback(
			static function (string $state) use ($armed): array {
				if ($armed === null) {
					throw new RuntimeException('no such table');
				}

				if ($state === FlowTimer::STATE_ARMED) {
					return $armed;
				}

				return [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'log.warning ' . $message . ' ' . json_encode($context);
			}
		);
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = 'output.' . $level . ' ' . $message;
				}
			);
		}

		(new CheckFlowTimerInvariants(timers: $timers, tasks: $this->createMock(TaskMapper::class), logger: $logger))->run($output);

		return $this->calls;
	}//end check()

	/**
	 * A table that cannot be read skips the check with a warning and a log line.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableSkipsTheCheck(): void {
		$this->assertSame(
			[
				'log.warning [CheckFlowTimerInvariants] Check skipped: no such table []',
				'output.warning Business-timer invariant check skipped: no such table',
			],
			$this->check(null)
		);
	}//end testAnUnreadableTableSkipsTheCheck()

	/**
	 * A clean store prints the counts as info and logs nothing.
	 *
	 * @return void
	 */
	public function testACleanStorePrintsTheCountsAsInfo(): void {
		$this->assertSame(
			['output.info Business-timer invariants: 0 armed without fire_at, 0 suspended with fire_at or running_since, 0 orphaned (subject terminal or absent).'],
			$this->check([])
		);
	}//end testACleanStorePrintsTheCountsAsInfo()

	/**
	 * A defect is printed as a warning and logged with the counts.
	 *
	 * @return void
	 */
	public function testADefectIsWarnedAndLoggedWithTheCounts(): void {
		$timer = new FlowTimer();
		$timer->setId(1);
		$timer->setUuid('t-1');
		$timer->setState(FlowTimer::STATE_ARMED);
		$timer->setSubjectType('other');
		$timer->setSubjectUuid('s-1');

		$message = 'Business-timer invariants: 1 armed without fire_at, 0 suspended with fire_at or running_since, 0 orphaned (subject terminal or absent).';
		$this->assertSame(
			[
				'output.warning ' . $message . ' These are DEFECTS in whoever completed the subject; they are reported, not cancelled.',
				'log.warning [CheckFlowTimerInvariants] ' . $message . ' {"armedWithoutFireAt":1,"suspendedWithClock":0,"orphaned":0}',
			],
			$this->check([$timer])
		);
	}//end testADefectIsWarnedAndLoggedWithTheCounts()
}//end class

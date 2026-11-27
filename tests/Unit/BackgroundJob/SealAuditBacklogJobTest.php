<?php

/**
 * Tests for SealAuditBacklogJob: its caps, its silence, its yield and its failure handling.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-the-scheduled-seal-job-shall-be-hard-bounded-per-invocation
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\SealAuditBacklogJob;
use OCA\OpenRegister\Service\Audit\AuditSealBacklogInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Runs the job against a mocked driver.
 */
#[CoversClass(SealAuditBacklogJob::class)]
class SealAuditBacklogJobTest extends TestCase {

	/**
	 * Run the job once.
	 *
	 * @param AuditSealBacklogInterface $backlog The driver.
	 * @param LoggerInterface           $logger  The logger.
	 *
	 * @return void
	 */
	private function runJob(AuditSealBacklogInterface $backlog, LoggerInterface $logger): void {
		$job = new SealAuditBacklogJob($this->createMock(ITimeFactory::class), $backlog, $logger);
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * A drain result.
	 *
	 * @param int    $sealed    Rows sealed.
	 * @param int    $abandoned Windows abandoned.
	 * @param string $stoppedBy Why it stopped.
	 *
	 * @return array{rowsSealed: int, windowsProcessed: int, windowsAbandoned: int, stoppedBy: string, unsealedBehindChain: int}
	 */
	private static function drained(int $sealed, int $abandoned, string $stoppedBy): array {
		return [
			'rowsSealed' => $sealed,
			'windowsProcessed' => (int)ceil($sealed / 2000),
			'windowsAbandoned' => $abandoned,
			'stoppedBy' => $stoppedBy,
			'unsealedBehindChain' => 3,
		];
	}//end result()

	/**
	 * Each run is capped by both rows and seconds, never unbounded.
	 *
	 * @return void
	 */
	public function testEachRunIsCappedByRowsAndByWallClock(): void {
		$backlog = $this->createMock(AuditSealBacklogInterface::class);
		$backlog->expects($this->once())->method('drain')
			->with(SealAuditBacklogJob::MAX_ROWS_PER_RUN, SealAuditBacklogJob::MAX_SECONDS_PER_RUN)
			->willReturn(self::drained(20000, 0, 'row-cap'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->logicalAnd(
				$this->stringContains('Sealed 20000 audit row(s) in 10 window(s); stopped: row-cap'),
				$this->stringContains('3 unsealed row(s) lie before the newest sealed row')
			)
		);

		$this->runJob($backlog, $logger);

		$this->assertGreaterThan(0, SealAuditBacklogJob::MAX_ROWS_PER_RUN);
		$this->assertGreaterThan(0, SealAuditBacklogJob::MAX_SECONDS_PER_RUN);
	}//end testEachRunIsCappedByRowsAndByWallClock()

	/**
	 * Nothing to seal: the steady state writes no log line.
	 *
	 * @return void
	 */
	public function testANoOpRunStaysSilent(): void {
		$backlog = $this->createMock(AuditSealBacklogInterface::class);
		$backlog->method('drain')->willReturn(self::drained(0, 0, 'drained'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->never())->method('error');

		$this->runJob($backlog, $logger);
	}//end testANoOpRunStaysSilent()

	/**
	 * A busy lock yields: the run ends without error and says it stopped on the lock.
	 *
	 * @return void
	 */
	public function testABusyLockYieldsWithoutError(): void {
		$backlog = $this->createMock(AuditSealBacklogInterface::class);
		$backlog->method('drain')->willReturn(self::drained(0, 1, 'lock'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('info')->with($this->stringContains('stopped: lock'));

		$this->runJob($backlog, $logger);
	}//end testABusyLockYieldsWithoutError()

	/**
	 * A failing drain is logged and never escapes to break cron.
	 *
	 * @return void
	 */
	public function testAFailingDrainIsLoggedAndContained(): void {
		$backlog = $this->createMock(AuditSealBacklogInterface::class);
		$backlog->method('drain')->willThrowException(new \RuntimeException('db gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('db gone'));

		$this->runJob($backlog, $logger);
	}//end testAFailingDrainIsLoggedAndContained()
}//end class

<?php

/**
 * Characterisation of CronFileTextExtractionJob::logOutcome(): the line it logs for an empty
 * queue, a completed run and a truncated walk, with the completion context key for key.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Files\BackgroundJob\CronExtractionRun;
use OCA\OpenRegister\Files\BackgroundJob\CronFileTextExtractionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden outcome lines of the cron text extraction job.
 *
 * @covers \OCA\OpenRegister\Files\BackgroundJob\CronFileTextExtractionJob
 */
final class CronFileTextExtractionOutcomeGoldenTest extends TestCase {

	/**
	 * Log the outcome of a run with these stats and answer what was logged.
	 *
	 * @param array<string, mixed> $stats The run's stats.
	 *
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}> Level, message and context.
	 */
	private function logged(array $stats): array {
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context=[]) use (&$lines, $level): void {
					$lines[] = [$level, $message, $context];
				}
			);
		}

		$job = new CronFileTextExtractionJob($this->createMock(ITimeFactory::class), $this->createMock(ContainerInterface::class));
		(new ReflectionMethod(CronFileTextExtractionJob::class, 'logOutcome'))->invoke($job, new CronExtractionRun($logger, microtime(true) - 2.0), $stats);

		return $lines;
	}//end logged()

	/**
	 * An empty queue logs one info line.
	 *
	 * @return void
	 */
	public function testNothingPending(): void {
		$lines = $this->logged(stats: ['total' => 0, 'processed' => 0, 'failed' => 0]);

		$this->assertCount(1, $lines);
		$this->assertSame(['info', '[CronFileTextExtractionJob] No pending files found for cron extraction'], [$lines[0][0], $lines[0][1]]);
		$this->assertSame(['file', 'line'], array_keys($lines[0][2]));
	}//end testNothingPending()

	/**
	 * A completed run logs one info line with its counters, in this key order.
	 *
	 * @return void
	 */
	public function testCompletedRun(): void {
		$lines = $this->logged(stats: ['total' => 3, 'processed' => 2, 'failed' => 1]);

		$this->assertCount(1, $lines);
		[$level, $message, $context] = $lines[0];
		$this->assertSame('info', $level);
		$this->assertSame('[CronFileTextExtractionJob] ✅ Cron File Text Extraction Job Completed', $message);
		$this->assertSame(['file', 'line', 'job_id', 'execution_time_seconds', 'files_processed', 'files_failed', 'truncated', 'next_run'], array_keys($context));
		$this->assertSame([2, 1, false], [$context['files_processed'], $context['files_failed'], $context['truncated']]);
		$this->assertGreaterThanOrEqual(2.0, $context['execution_time_seconds']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $context['next_run']);
		$this->assertLessThan(5, abs(strtotime($context['next_run']) - (time() + 15 * 60)));
	}//end testCompletedRun()

	/**
	 * A truncated walk logs one warning with the same context, truncated true.
	 *
	 * @return void
	 */
	public function testTruncatedWalk(): void {
		$lines = $this->logged(stats: ['total' => 100, 'processed' => 0, 'failed' => 100, 'truncated' => true]);

		$this->assertCount(1, $lines);
		[$level, $message, $context] = $lines[0];
		$this->assertSame('warning', $level);
		$this->assertSame(
			'[CronFileTextExtractionJob] Cron File Text Extraction Job stopped on the window limit before filling its batch - the queue head is not extractable and every tick will re-walk it',
			$message
		);
		$this->assertSame(['file', 'line', 'job_id', 'execution_time_seconds', 'files_processed', 'files_failed', 'truncated', 'next_run'], array_keys($context));
		$this->assertTrue($context['truncated']);
	}//end testTruncatedWalk()
}//end class

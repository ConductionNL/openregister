<?php

/**
 * Golden test for SchemaRunJob::run().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\SchemaRunJob;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use OCA\OpenRegister\BackgroundJob\Support\SchemaRunServices;

/**
 * Pins every call run() makes on the mapper, the engines, the job list and the logger.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\SchemaRunJob
 */
class SchemaRunJobGoldenTest extends TestCase {

	/**
	 * The recorded trace.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	/**
	 * Run the job once.
	 *
	 * @param mixed $argument The job argument.
	 * @param mixed $run      The run find() returns, or a RuntimeException it throws.
	 * @param mixed $batch    What processBatch() returns, or a RuntimeException it throws.
	 *
	 * @return array The trace.
	 */
	private function act(mixed $argument, mixed $run, mixed $batch): array {
		$mapper = $this->createMock(SchemaRunMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (int $id) use ($run): SchemaRun {
				$this->trace[] = ['find', $id];
				if ($run instanceof RuntimeException) {
					throw $run;
				}

				return $run;
			}
		);
		$mapper->method('save')->willReturnCallback(
			function (SchemaRun $saved): SchemaRun {
				$this->trace[] = ['save', $saved->getState(), $saved->getReport()];
				return $saved;
			}
		);

		$engine = function (string $name) use ($batch): callable {
			return function (SchemaRun $run, int $size) use ($name, $batch): bool {
				$this->trace[] = [$name, $run->getType(), $size];
				if ($batch instanceof RuntimeException) {
					throw $batch;
				}

				return $batch;
			};
		};
		$migration = $this->createMock(SchemaMigrationService::class);
		$migration->method('processBatch')->willReturnCallback($engine('migration'));
		$revalidation = $this->createMock(SchemaRevalidationService::class);
		$revalidation->method('processBatch')->willReturnCallback($engine('revalidation'));

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function ($job, $argument): void {
				$this->trace[] = ['add', $job, $argument];
			}
		);

		$logger = new class($this->trace) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, $message, array $context = []): void {
				$this->sink[] = ['log', $level, (string) $message, $context];
			}
		};

		$job = new SchemaRunJob($this->createMock(ITimeFactory::class), new SchemaRunServices($mapper, $revalidation, $migration), $jobList, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);

		return $this->trace;
	}

	private function schemaRun(string $type, string $state, ?array $report): SchemaRun {
		$run = new SchemaRun();
		$run->setType($type);
		$run->setState($state);
		$run->setReport($report);
		return $run;
	}

	public function testMissingRunId(): void {
		$this->assertSame(
			[['log', 'error', '[SchemaRunJob] Missing run_id argument', ['argument' => ['batch_size' => 5]]]],
			$this->act(['batch_size' => 5], null, true)
		);
	}

	public function testRunNotFound(): void {
		$this->assertSame(
			[
				['find', 7],
				['log', 'error', '[SchemaRunJob] Run not found', ['run_id' => '7', 'error' => 'gone']],
			],
			$this->act(['run_id' => '7'], new RuntimeException('gone'), true)
		);
	}

	public function testNonRunningRunIsLeftAlone(): void {
		$run = $this->schemaRun(SchemaRun::TYPE_MIGRATION, SchemaRun::STATE_FAILED, null);
		$this->assertSame([['find', 3]], $this->act(['run_id' => 3], $run, true));
	}

	public function testMigrationWithMoreWorkReEnqueuesWithDefaultBatch(): void {
		$run = $this->schemaRun(SchemaRun::TYPE_MIGRATION, SchemaRun::STATE_RUNNING, null);
		$this->assertSame(
			[
				['find', 3],
				['migration', SchemaRun::TYPE_MIGRATION, 100],
				['add', SchemaRunJob::class, ['run_id' => '3', 'batch_size' => 100]],
			],
			$this->act(['run_id' => '3'], $run, true)
		);
	}

	public function testRevalidationFinishedDoesNotReEnqueue(): void {
		$run = $this->schemaRun('revalidation', SchemaRun::STATE_RUNNING, null);
		$this->assertSame(
			[['find', 4], ['revalidation', 'revalidation', 25]],
			$this->act(['run_id' => 4, 'batch_size' => '25'], $run, false)
		);
	}

	public function testBatchFailureMarksRunFailedKeepingReport(): void {
		$run = $this->schemaRun(SchemaRun::TYPE_MIGRATION, SchemaRun::STATE_RUNNING, ['ok' => 2, 'fatal' => 'old']);
		$this->assertSame(
			[
				['find', 9],
				['migration', SchemaRun::TYPE_MIGRATION, 10],
				['log', 'error', '[SchemaRunJob] Batch processing failed', ['run_id' => 9, 'error' => 'boom']],
				['save', SchemaRun::STATE_FAILED, ['ok' => 2, 'fatal' => 'boom']],
			],
			$this->act(['run_id' => 9, 'batch_size' => 10], $run, new RuntimeException('boom'))
		);
	}

	public function testBatchFailureWithoutReport(): void {
		$run = $this->schemaRun('revalidation', SchemaRun::STATE_RUNNING, null);
		$trace = $this->act(['run_id' => 9], $run, new RuntimeException('boom'));
		$this->assertSame(['save', SchemaRun::STATE_FAILED, ['fatal' => 'boom']], $trace[3]);
		$this->assertCount(4, $trace);
	}
}

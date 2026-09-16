<?php

/**
 * Unit tests for BulkJobRunner — the queued executor of a committed job.
 *
 * Covers the three things a queue worker has to get right: it does nothing
 * to a job that is not running, it re-enqueues itself only while members
 * remain, and a throw inside a batch leaves the job failed with the message
 * rather than stuck at running forever.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkJobRunnerTest extends TestCase {

	/**
	 * Job persistence.
	 *
	 * @var BulkJobMapper
	 */
	private BulkJobMapper $jobMapper;

	/**
	 * The lifecycle service the runner drives.
	 *
	 * @var BulkJobService
	 */
	private BulkJobService $service;

	/**
	 * The background queue, which records the re-enqueues.
	 *
	 * @var IJobList
	 */
	private IJobList $jobList;

	/**
	 * Every re-enqueue made during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queued = [];

	protected function setUp(): void {
		parent::setUp();

		$this->queued = [];
		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->service = $this->createMock(BulkJobService::class);
		$this->jobList = $this->createMock(IJobList::class);

		$this->jobMapper->method('save')->willReturnArgument(0);
		$this->service->method('getBatchSize')->willReturn(25);
		$this->jobList->method('add')->willReturnCallback(
			function (string $class, $argument): void {
				$this->queued[] = ['class' => $class, 'argument' => $argument];
			}
		);
	}

	private function runner(): BulkJobRunner {
		return new BulkJobRunner(
			$this->createMock(ITimeFactory::class),
			$this->jobMapper,
			$this->service,
			$this->jobList,
			new NullLogger()
		);
	}

	private function invoke(BulkJobRunner $runner, array $argument): void {
		$run = new \ReflectionMethod($runner, 'run');
		$run->setAccessible(true);
		$run->invoke($runner, $argument);
	}

	private function job(string $state): BulkJob {
		$job = new BulkJob();
		$job->setId(3);
		$job->setUuid('job-uuid');
		$job->setState($state);

		return $job;
	}

	public function testACancelledJobIsNotWalkedAndNotRequeued(): void {
		$this->jobMapper->method('find')->willReturn($this->job(BulkJob::STATE_CANCELLED));
		$this->service->expects($this->never())->method('processBatch');

		$this->invoke($this->runner(), ['job_id' => 3]);

		$this->assertSame([], $this->queued);
	}

	/**
	 * A pause is only a pause if the queue stops.
	 *
	 * Writing `paused` on the row is the easy half; the half that decides
	 * whether an administrator's pause means anything is here, where the
	 * runner either re-enqueues itself or does not. Without this assertion a
	 * paused job would keep walking its members and the console would show a
	 * state nothing honours.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function testAPausedJobIsNotWalkedAndNotRequeued(): void {
		$this->jobMapper->method('find')->willReturn($this->job(BulkJob::STATE_PAUSED));
		$this->service->expects($this->never())->method('processBatch');

		$this->invoke($this->runner(), ['job_id' => 3]);

		$this->assertSame([], $this->queued);
	}

	public function testARunningJobWithMoreMembersRequeuesItself(): void {
		$this->jobMapper->method('find')->willReturn($this->job(BulkJob::STATE_RUNNING));
		$this->service->method('processBatch')->willReturn(true);

		$this->invoke($this->runner(), ['job_id' => 3]);

		$this->assertCount(1, $this->queued);
		$this->assertSame(BulkJobRunner::class, $this->queued[0]['class']);
		$this->assertSame(['job_id' => 3, 'batch_size' => 25], $this->queued[0]['argument']);
	}

	public function testAFinishedBatchDoesNotRequeue(): void {
		$this->jobMapper->method('find')->willReturn($this->job(BulkJob::STATE_RUNNING));
		$this->service->method('processBatch')->willReturn(false);

		$this->invoke($this->runner(), ['job_id' => 3]);

		$this->assertSame([], $this->queued);
	}

	public function testAThrowInsideABatchFailsTheJobWithTheMessage(): void {
		$job = $this->job(BulkJob::STATE_RUNNING);
		$this->jobMapper->method('find')->willReturn($job);
		$this->service->method('processBatch')->willThrowException(new \RuntimeException('the database went away'));

		$this->invoke($this->runner(), ['job_id' => 3]);

		$this->assertSame(BulkJob::STATE_FAILED, $job->getState());
		$this->assertSame('the database went away', $job->getReport()['fatal']);
		$this->assertSame([], $this->queued);
	}

	public function testAMissingJobIdIsLoggedAndIgnored(): void {
		$this->jobMapper->expects($this->never())->method('find');

		$this->invoke($this->runner(), []);

		$this->assertSame([], $this->queued);
	}
}

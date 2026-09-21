<?php

/**
 * Unit tests for pausing and resuming a bulk job.
 *
 * A pause keeps the cursor and takes the job out of the queue; a resume puts
 * it back and carries on at the member it stopped at. Both refuse from any
 * other state, and cancelling a paused job ends it outright rather than
 * waiting for a handshake from a runner that is not there.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutor;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkJobPauseResumeTest extends TestCase {

	/**
	 * Job persistence, which returns whatever it is handed.
	 *
	 * @var BulkJobMapper
	 */
	private BulkJobMapper $jobMapper;

	/**
	 * The background queue, which records the enqueues.
	 *
	 * @var IJobList
	 */
	private IJobList $jobList;

	/**
	 * Every enqueue made during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queued = [];

	protected function setUp(): void {
		parent::setUp();

		$this->queued = [];
		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->jobList = $this->createMock(IJobList::class);

		$this->jobMapper->method('save')->willReturnArgument(0);
		$this->jobList->method('add')->willReturnCallback(
			function (string $class, $argument): void {
				$this->queued[] = ['class' => $class, 'argument' => $argument];
			}
		);
	}

	private function service(): BulkJobService {
		return new BulkJobService(
			$this->jobMapper,
			$this->createMock(BulkJobMemberMapper::class),
			$this->createMock(BulkActionRegistry::class),
			$this->createMock(BulkSelectionResolver::class),
			$this->createMock(BulkJobExecutor::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserManager::class),
			$this->jobList,
			$this->createMock(IAppConfig::class),
			new NullLogger()
		);
	}

	private function job(string $state, int $cursor = 120): BulkJob {
		$job = new BulkJob();
		$job->setId(11);
		$job->setUuid('job-uuid');
		$job->setAction('openregister:assign');
		$job->setState($state);
		$job->setStartedBy('coordinator');
		$job->setTotal(400);
		$job->setCursor($cursor);

		return $job;
	}

	public function testPausingARunningJobHoldsItAtItsCursorAndQueuesNothing(): void {
		$paused = $this->service()->pause($this->job(BulkJob::STATE_RUNNING));

		$this->assertSame(BulkJob::STATE_PAUSED, $paused->getState());
		$this->assertSame(120, $paused->getCursor(), 'A pause keeps its place; only a retry rewinds.');
		$this->assertSame([], $this->queued);
	}

	public function testAPausedJobStillCountsAsHavingWorkAhead(): void {
		$this->assertTrue(
			$this->service()->pause($this->job(BulkJob::STATE_RUNNING))->isActive(),
			'A paused job has unwalked members, so it is active in the sense a cancelled one is not.'
		);
	}

	public function testPausingAJobThatIsNotRunningIsRefusedNamingTheState(): void {
		try {
			$this->service()->pause($this->job(BulkJob::STATE_COMPLETED));
			$this->fail('A completed job should not be pausable.');
		} catch (BulkJobRefusedException $exception) {
			$this->assertSame('not-pausable', $exception->getReason());
			$this->assertSame(['state' => BulkJob::STATE_COMPLETED], $exception->getDetails());
			$this->assertStringContainsString('completed', $exception->getMessage());
		}
	}

	public function testResumingAPausedJobRunsItAgainFromTheSameMember(): void {
		$resumed = $this->service()->resume($this->job(BulkJob::STATE_PAUSED));

		$this->assertSame(BulkJob::STATE_RUNNING, $resumed->getState());
		$this->assertSame(120, $resumed->getCursor(), 'A resume continues; it does not restart.');
		$this->assertCount(1, $this->queued);
		$this->assertSame(BulkJobRunner::class, $this->queued[0]['class']);
		$this->assertSame(['job_id' => 11], $this->queued[0]['argument']);
	}

	public function testResumingAJobThatIsNotPausedIsRefusedAndQueuesNothing(): void {
		try {
			$this->service()->resume($this->job(BulkJob::STATE_RUNNING));
			$this->fail('A running job should not be resumable.');
		} catch (BulkJobRefusedException $exception) {
			$this->assertSame('not-resumable', $exception->getReason());
			$this->assertSame([], $this->queued);
		}
	}

	public function testCancellingAPausedJobEndsItRatherThanLeavingItUnchanged(): void {
		$cancelled = $this->service()->cancel($this->job(BulkJob::STATE_PAUSED));

		$this->assertSame(
			BulkJob::STATE_CANCELLED,
			$cancelled->getState(),
			'No runner holds a paused job, so there is nobody to complete a cancelling handshake.'
		);
	}
}

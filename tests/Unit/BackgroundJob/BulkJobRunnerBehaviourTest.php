<?php

/**
 * BulkJobRunner: an unknown job id, a batch-size argument and a fatal batch keeping the report.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\BackgroundJob\Support\BulkJobServices;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapperInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the runner's protected run().
 */
class BulkJobRunnerBehaviourTest extends TestCase {

	private BulkJobMapperInterface&MockObject $jobMapper;

	private BulkJobServiceInterface&MockObject $service;

	private IJobList&MockObject $jobList;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->jobMapper = $this->createMock(BulkJobMapperInterface::class);
		$this->service = $this->createMock(BulkJobServiceInterface::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function runWith(array $argument): void {
		$runner = new BulkJobRunner(
			$this->createMock(ITimeFactory::class),
			new BulkJobServices($this->jobMapper, $this->service),
			$this->jobList,
			$this->logger
		);
		$method = new ReflectionMethod($runner, 'run');
		$method->invoke($runner, $argument);
	}

	private function runningJob(): BulkJob {
		$job = new BulkJob();
		$job->setId(8);
		$job->setState(BulkJob::STATE_RUNNING);

		return $job;
	}

	public function testAJobThatCannotBeFoundIsLoggedAndNeitherWalkedNorRequeued(): void {
		$this->jobMapper->expects($this->once())
			->method('find')
			->with(42)
			->willThrowException(new DoesNotExistException('no such job'));
		$this->service->expects($this->never())->method('processBatch');
		$this->jobList->expects($this->never())->method('add');
		$this->logger->expects($this->once())
			->method('error')
			->with('[BulkJobRunner] Job not found', ['job_id' => '42', 'error' => 'no such job']);

		$this->runWith(['job_id' => '42']);
	}

	public function testAMissingJobIdIsLoggedWithTheArgument(): void {
		$this->jobMapper->expects($this->never())->method('find');
		$this->logger->expects($this->once())
			->method('error')
			->with('[BulkJobRunner] Missing job_id argument', ['argument' => ['batch_size' => 5]]);

		$this->runWith(['batch_size' => 5]);
	}

	public function testAnExplicitBatchSizeIsUsedAndCarriedIntoTheRequeue(): void {
		$job = $this->runningJob();
		$this->jobMapper->method('find')->willReturn($job);
		$this->service->expects($this->never())->method('getBatchSize');
		$this->service->expects($this->once())->method('processBatch')->with($job, 5)->willReturn(true);
		$this->jobList->expects($this->once())
			->method('add')
			->with(BulkJobRunner::class, ['job_id' => 8, 'batch_size' => 5]);

		$this->runWith(['job_id' => 8, 'batch_size' => '5']);
	}

	public function testWithoutABatchSizeTheServiceDefaultIsUsed(): void {
		$job = $this->runningJob();
		$this->jobMapper->method('find')->willReturn($job);
		$this->service->method('getBatchSize')->willReturn(40);
		$this->service->expects($this->once())->method('processBatch')->with($job, 40)->willReturn(false);
		$this->jobList->expects($this->never())->method('add');

		$this->runWith(['job_id' => 8]);
	}

	public function testAFatalBatchKeepsTheExistingReportAndSavesTheFailedJob(): void {
		$job = $this->runningJob();
		$job->setReport(['applied' => 3]);
		$this->jobMapper->method('find')->willReturn($job);
		$this->service->method('getBatchSize')->willReturn(10);
		$this->service->method('processBatch')->willThrowException(new RuntimeException('disk full'));
		$this->jobMapper->expects($this->once())->method('save')->with($job)->willReturnArgument(0);
		$this->jobList->expects($this->never())->method('add');
		$this->logger->expects($this->once())
			->method('error')
			->with('[BulkJobRunner] Batch failed', ['job_id' => 8, 'error' => 'disk full']);

		$this->runWith(['job_id' => 8]);

		$this->assertSame(BulkJob::STATE_FAILED, $job->getState());
		$this->assertSame(['applied' => 3, 'fatal' => 'disk full'], $job->getReport());
	}
	/**
	 * @return array<string, array{0: string}>
	 */
	public static function idleStateProvider(): array {
		return [
			'paused' => [BulkJob::STATE_PAUSED],
			'cancelling' => [BulkJob::STATE_CANCELLING],
			'completed' => [BulkJob::STATE_COMPLETED],
			'failed' => [BulkJob::STATE_FAILED],
		];
	}

	/**
	 * @dataProvider idleStateProvider
	 */
	public function testAJobThatIsNotRunningIsLeftAlone(string $state): void {
		$job = $this->runningJob();
		$job->setState($state);
		$this->jobMapper->method('find')->willReturn($job);
		$this->service->expects($this->never())->method('processBatch');
		$this->jobList->expects($this->never())->method('add');
		$this->jobMapper->expects($this->never())->method('save');

		$this->runWith(['job_id' => 8]);

		$this->assertSame($state, $job->getState());
	}
}

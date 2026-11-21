<?php

/**
 * The scheme migration job advances a running run a batch at a time and stops a failing one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\IdentifierSchemeMigrationJob;
use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The job over a mocked migration service.
 */
#[CoversClass(IdentifierSchemeMigrationJob::class)]
#[UsesClass(IdentifierSchemeRun::class)]
class IdentifierSchemeMigrationJobTest extends TestCase {

	/**
	 * The migrations.
	 *
	 * @var IdentifierSchemeMigrationService&MockObject
	 */
	private IdentifierSchemeMigrationService&MockObject $migrations;

	/**
	 * The job list.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrations = $this->createMock(IdentifierSchemeMigrationService::class);
		$this->jobList    = $this->createMock(IJobList::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A batch that leaves work queues the next one with the same size.
	 *
	 * @return void
	 */
	public function testABatchLeavingWorkQueuesTheNext(): void {
		$run = self::runIn(state: IdentifierSchemeRun::STATE_RUNNING);
		$this->migrations->method('find')->with(4)->willReturn($run);
		$this->migrations->expects($this->once())->method('processBatch')->with($run, 25)->willReturn(true);
		$this->jobList->expects($this->once())->method('add')
			->with(IdentifierSchemeMigrationJob::class, ['run_id' => 4, 'batch_size' => 25]);

		$this->runJob(argument: ['run_id' => 4, 'batch_size' => 25]);
	}//end testABatchLeavingWorkQueuesTheNext()

	/**
	 * The last batch queues nothing.
	 *
	 * @return void
	 */
	public function testTheLastBatchQueuesNothing(): void {
		$run = self::runIn(state: IdentifierSchemeRun::STATE_RUNNING);
		$this->migrations->method('find')->willReturn($run);
		$this->migrations->expects($this->once())->method('processBatch')
			->with($run, IdentifierSchemeMigrationService::DEFAULT_BATCH)->willReturn(false);
		$this->jobList->expects($this->never())->method('add');

		$this->runJob(argument: ['run_id' => 4]);
	}//end testTheLastBatchQueuesNothing()

	/**
	 * A batch that throws stops the run as failed and queues nothing.
	 *
	 * @return void
	 */
	public function testAThrowingBatchFailsTheRun(): void {
		$run = self::runIn(state: IdentifierSchemeRun::STATE_RUNNING);
		$this->migrations->method('find')->willReturn($run);
		$this->migrations->method('processBatch')->willThrowException(new RuntimeException('schema gone'));
		$this->migrations->expects($this->once())->method('fail')->with($run, 'schema gone');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('run 4 stopped: schema gone'));
		$this->jobList->expects($this->never())->method('add');

		$this->runJob(argument: ['run_id' => 4]);
	}//end testAThrowingBatchFailsTheRun()

	/**
	 * A missing id, an unknown run or a stopped run is not advanced.
	 *
	 * @return void
	 */
	public function testNothingRunsWithoutARunningRun(): void {
		$this->migrations->method('find')->willReturnMap([[5, null], [6, self::runIn(state: IdentifierSchemeRun::STATE_COMPLETED)]]);
		$this->migrations->expects($this->never())->method('processBatch');
		$this->jobList->expects($this->never())->method('add');

		$this->runJob(argument: []);
		$this->runJob(argument: ['run_id' => 5]);
		$this->runJob(argument: ['run_id' => 6]);
	}//end testNothingRunsWithoutARunningRun()

	/**
	 * Run the job's body with an argument.
	 *
	 * @param array<string, int> $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(array $argument): void {
		$job = new IdentifierSchemeMigrationJob($this->createMock(ITimeFactory::class), $this->migrations, $this->jobList, $this->logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * A run with id 4 in a state.
	 *
	 * @param string $state The state.
	 *
	 * @return IdentifierSchemeRun
	 */
	private static function runIn(string $state): IdentifierSchemeRun {
		$run = new IdentifierSchemeRun();
		$run->setId(4);
		$run->setState($state);

		return $run;
	}//end runIn()
}//end class

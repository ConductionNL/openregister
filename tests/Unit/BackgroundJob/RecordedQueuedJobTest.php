<?php

/**
 * A recorded queued job runs its work inside the recorder, under the right cause and actor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\RecordedQueuedJob;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The recorder wraps the work, and the argument decides cause and actor.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\RecordedQueuedJob
 * @uses   \OCA\OpenRegister\Service\Operations\JobRunStart
 * @uses   \OCA\OpenRegister\Service\Operations\JobRunContext
 */
class RecordedQueuedJobTest extends TestCase {

	/**
	 * The start the recorder was handed, per call.
	 *
	 * @var array<int, JobRunStart>
	 */
	private array $starts = [];

	/**
	 * The arguments the work saw, per run.
	 *
	 * @var array<int, mixed>
	 */
	private array $ran = [];

	/**
	 * A job whose recorder runs the work and keeps the start it was given.
	 *
	 * @param bool $runWork Whether the recorder calls the work.
	 *
	 * @return RecordedQueuedJob
	 */
	private function job(bool $runWork = true): RecordedQueuedJob {
		$recorder = $this->createMock(JobRunRecorderInterface::class);
		$recorder->method('around')->willReturnCallback(
			function (JobRunStart $start, callable $work) use ($runWork): mixed {
				$this->starts[] = $start;
				if ($runWork === true) {
					$work();
				}

				return null;
			}
		);

		$ran = &$this->ran;
		return new class($this->createMock(ITimeFactory::class), $recorder, $ran) extends RecordedQueuedJob {

			/**
			 * The arguments the work saw.
			 *
			 * @var array<int, mixed>
			 */
			private array $ran;

			/**
			 * @param ITimeFactory            $time     The clock.
			 * @param JobRunRecorderInterface $recorder The recorder.
			 * @param array<int, mixed>       $ran      Where the work writes its argument.
			 */
			public function __construct(ITimeFactory $time, JobRunRecorderInterface $recorder, array &$ran) {
				parent::__construct(time: $time, recorder: $recorder);
				$this->ran = &$ran;
			}

			/**
			 * Note the argument.
			 *
			 * @param mixed $argument The job argument.
			 *
			 * @return void
			 */
			protected function runRecorded(mixed $argument): void {
				$this->ran[] = $argument;
			}
		};
	}//end job()

	/**
	 * Run the protected run().
	 *
	 * @param RecordedQueuedJob $job      The job.
	 * @param mixed             $argument The argument.
	 *
	 * @return void
	 */
	private function runJob(RecordedQueuedJob $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	public function testAnArgumentWithoutAnActorIsAScheduledRunWithNoActor(): void {
		$job = $this->job();

		$this->runJob($job, ['objectId' => 7]);

		self::assertCount(1, $this->starts);
		self::assertSame($job::class, $this->starts[0]->jobClass);
		self::assertSame(JobRun::CAUSE_SCHEDULE, $this->starts[0]->context->cause);
		self::assertNull($this->starts[0]->context->actor);
		self::assertSame(['objectId' => 7], $this->starts[0]->context->argument);
		self::assertSame([['objectId' => 7]], $this->ran);
	}//end testAnArgumentWithoutAnActorIsAScheduledRunWithNoActor()

	public function testAnArgumentNamingAnActorIsAManualRunByThatActor(): void {
		$job = $this->job();

		$this->runJob($job, ['actor' => 'alice', 'objectId' => 7]);

		self::assertSame(JobRun::CAUSE_MANUAL, $this->starts[0]->context->cause);
		self::assertSame('alice', $this->starts[0]->context->actor);
		self::assertSame([['actor' => 'alice', 'objectId' => 7]], $this->ran);
	}//end testAnArgumentNamingAnActorIsAManualRunByThatActor()

	public function testANonStringActorMakesTheRunManualButNamesNoActor(): void {
		$this->runJob($this->job(), ['actor' => 42]);

		self::assertSame(JobRun::CAUSE_MANUAL, $this->starts[0]->context->cause);
		self::assertNull($this->starts[0]->context->actor);
	}//end testANonStringActorMakesTheRunManualButNamesNoActor()

	public function testANullActorIsStillAScheduledRun(): void {
		$this->runJob($this->job(), ['actor' => null]);

		self::assertSame(JobRun::CAUSE_SCHEDULE, $this->starts[0]->context->cause);
		self::assertNull($this->starts[0]->context->actor);
	}//end testANullActorIsStillAScheduledRun()

	public function testAScalarArgumentIsAScheduledRunAndPassesThroughUnchanged(): void {
		$this->runJob($this->job(), 'tenant-1');

		self::assertSame(JobRun::CAUSE_SCHEDULE, $this->starts[0]->context->cause);
		self::assertNull($this->starts[0]->context->actor);
		self::assertSame('tenant-1', $this->starts[0]->context->argument);
		self::assertSame(['tenant-1'], $this->ran);
	}//end testAScalarArgumentIsAScheduledRunAndPassesThroughUnchanged()

	public function testTheWorkRunsOnlyInsideTheRecorder(): void {
		$this->runJob($this->job(runWork: false), null);

		// The recorder owns the run: work it does not call does not happen.
		self::assertCount(1, $this->starts);
		self::assertSame([], $this->ran);
	}//end testTheWorkRunsOnlyInsideTheRecorder()

	public function testAFailingWorkReachesTheRecorder(): void {
		$recorder = $this->createMock(JobRunRecorderInterface::class);
		$seen     = null;
		$recorder->method('around')->willReturnCallback(
			static function (JobRunStart $start, callable $work) use (&$seen): mixed {
				try {
					$work();
				} catch (RuntimeException $e) {
					$seen = $e->getMessage();
				}

				return null;
			}
		);

		$job = new class($this->createMock(ITimeFactory::class), $recorder) extends RecordedQueuedJob {

			/**
			 * Fail.
			 *
			 * @param mixed $argument The job argument.
			 *
			 * @return void
			 */
			protected function runRecorded(mixed $argument): void {
				throw new RuntimeException('work failed');
			}
		};

		$this->runJob($job, null);

		self::assertSame('work failed', $seen);
	}//end testAFailingWorkReachesTheRecorder()
}//end class

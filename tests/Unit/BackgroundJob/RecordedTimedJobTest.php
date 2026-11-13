<?php

/**
 * A recorded timed job asks the schedule first, and only a permitted tick becomes a recorded run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\BackgroundJob\RecordedTimedJob;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCA\OpenRegister\Service\Operations\JobScheduleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A skipped tick leaves no run; a permitted one runs inside the recorder.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\RecordedTimedJob
 * @uses   \OCA\OpenRegister\BackgroundJob\RecordedJobRuntime
 * @uses   \OCA\OpenRegister\Service\Operations\JobRunStart
 * @uses   \OCA\OpenRegister\Service\Operations\JobRunContext
 */
class RecordedTimedJobTest extends TestCase {

	/**
	 * The recorder double.
	 *
	 * @var JobRunRecorderInterface&MockObject
	 */
	private JobRunRecorderInterface&MockObject $recorder;

	/**
	 * The schedule double.
	 *
	 * @var JobScheduleServiceInterface&MockObject
	 */
	private JobScheduleServiceInterface&MockObject $schedule;

	/**
	 * The arguments the work saw.
	 *
	 * @var array<int, mixed>
	 */
	private array $ran = [];

	protected function setUp(): void {
		$this->recorder = $this->createMock(JobRunRecorderInterface::class);
		$this->schedule = $this->createMock(JobScheduleServiceInterface::class);
	}//end setUp()

	/**
	 * A job whose work notes its argument.
	 *
	 * @return RecordedTimedJob
	 */
	private function job(): RecordedTimedJob {
		$runtime = new RecordedJobRuntime(time: $this->createMock(ITimeFactory::class), recorder: $this->recorder);
		$ran     = &$this->ran;

		return new class($runtime, $this->schedule, $ran) extends RecordedTimedJob {

			/**
			 * The arguments the work saw.
			 *
			 * @var array<int, mixed>
			 */
			private array $ran;

			/**
			 * @param RecordedJobRuntime          $runtime  The clock and recorder.
			 * @param JobScheduleServiceInterface $schedule The schedule.
			 * @param array<int, mixed>           $ran      Where the work writes its argument.
			 */
			public function __construct(RecordedJobRuntime $runtime, JobScheduleServiceInterface $schedule, array &$ran) {
				parent::__construct(runtime: $runtime, schedule: $schedule);
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
	 * @param RecordedTimedJob $job      The job.
	 * @param mixed            $argument The argument.
	 *
	 * @return void
	 */
	private function runJob(RecordedTimedJob $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	public function testATickTheScheduleRefusesIsNotARun(): void {
		$job = $this->job();
		$this->schedule->expects(self::once())
			->method('mayRun')
			->with($job::class, self::isInstanceOf(DateTime::class))
			->willReturn(false);
		$this->recorder->expects(self::never())->method('around');

		$this->runJob($job, ['x' => 1]);

		self::assertSame([], $this->ran);
	}//end testATickTheScheduleRefusesIsNotARun()

	public function testAPermittedTickRunsTheWorkInsideTheRecorder(): void {
		$job = $this->job();
		$this->schedule->method('mayRun')->willReturn(true);
		$starts = [];
		$this->recorder->expects(self::once())
			->method('around')
			->willReturnCallback(
				static function (JobRunStart $start, callable $work) use (&$starts): mixed {
					$starts[] = $start;
					$work();
					return null;
				}
			);

		$this->runJob($job, ['x' => 1]);

		self::assertSame([['x' => 1]], $this->ran);
		self::assertSame($job::class, $starts[0]->jobClass);
		// A timed job is always a scheduled run, even when its argument names an actor.
		self::assertSame(JobRun::CAUSE_SCHEDULE, $starts[0]->context->cause);
		self::assertNull($starts[0]->context->actor);
		self::assertSame(['x' => 1], $starts[0]->context->argument);
	}//end testAPermittedTickRunsTheWorkInsideTheRecorder()

	public function testAnActorInTheArgumentDoesNotMakeATimedRunManual(): void {
		$job = $this->job();
		$this->schedule->method('mayRun')->willReturn(true);
		$starts = [];
		$this->recorder->method('around')->willReturnCallback(
			static function (JobRunStart $start, callable $work) use (&$starts): mixed {
				$starts[] = $start;
				$work();
				return null;
			}
		);

		$this->runJob($job, ['actor' => 'alice']);

		self::assertSame(JobRun::CAUSE_SCHEDULE, $starts[0]->context->cause);
		self::assertNull($starts[0]->context->actor);
		self::assertSame([['actor' => 'alice']], $this->ran);
	}//end testAnActorInTheArgumentDoesNotMakeATimedRunManual()

	public function testTheScheduleIsAskedForThisJobClassAtTheCurrentMoment(): void {
		$job    = $this->job();
		$before = new DateTime();
		$asked  = null;
		$this->schedule->method('mayRun')->willReturnCallback(
			static function (string $jobClass, DateTime $moment) use (&$asked): bool {
				$asked = [$jobClass, $moment];
				return false;
			}
		);

		$this->runJob($job, null);
		$after = new DateTime();

		self::assertSame($job::class, $asked[0]);
		self::assertGreaterThanOrEqual($before->getTimestamp(), $asked[1]->getTimestamp());
		self::assertLessThanOrEqual($after->getTimestamp(), $asked[1]->getTimestamp());
	}//end testTheScheduleIsAskedForThisJobClassAtTheCurrentMoment()
}//end class

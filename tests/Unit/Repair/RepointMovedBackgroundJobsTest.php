<?php

/**
 * A moved job's queued rows are re-added under the new class and the old rows removed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\AppInfo\ClassAliasMap;
use OCA\OpenRegister\BackgroundJob\LogCleanUpTask;
use OCA\OpenRegister\Repair\RepointMovedBackgroundJobs;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\RepointMovedBackgroundJobs
 * @uses   \OCA\OpenRegister\AppInfo\ClassAliasMap
 */
final class RepointMovedBackgroundJobsTest extends TestCase {

	private const OLD = 'OCA\\OpenRegister\\Old\\LogCleanUpTask';

	/**
	 * Each queued row is re-added with its own argument, then the old name is removed.
	 *
	 * @return void
	 */
	public function testEveryRowIsReaddedWithItsArgumentAndTheOldNameRemoved(): void {
		$calls   = [];
		$jobList = $this->jobListWith(rows: [['a' => 1], null], calls: $calls);

		$step = new RepointMovedBackgroundJobs(
			$jobList,
			new ClassAliasMap(entries: [self::OLD => ['class' => LogCleanUpTask::class, 'eager' => false]]),
			$this->createMock(LoggerInterface::class)
		);
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			expected: [
				['iterate', self::OLD],
				['add', LogCleanUpTask::class, ['a' => 1]],
				['add', LogCleanUpTask::class, null],
				['remove', self::OLD, null],
			],
			actual: $calls
		);
	}//end testEveryRowIsReaddedWithItsArgumentAndTheOldNameRemoved()

	/**
	 * An entry that is not a background job leaves the job list alone.
	 *
	 * @return void
	 */
	public function testANonJobEntryIsSkipped(): void {
		$calls   = [];
		$jobList = $this->jobListWith(rows: [], calls: $calls);

		$step = new RepointMovedBackgroundJobs(
			$jobList,
			new ClassAliasMap(entries: ['OCA\\OpenRegister\\Old\\Thing' => ['class' => self::class, 'eager' => false]]),
			$this->createMock(LoggerInterface::class)
		);
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [], actual: $calls);
	}//end testANonJobEntryIsSkipped()

	/**
	 * A failure on one entry is reported and does not stop the run.
	 *
	 * @return void
	 */
	public function testAFailureIsReportedAndDoesNotStopTheRun(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('getJobsIterator')->willThrowException(new RuntimeException('db down'));
		$jobList->expects($this->never())->method('remove');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('warning');

		$step = new RepointMovedBackgroundJobs(
			$jobList,
			new ClassAliasMap(
				entries: [
					self::OLD => ['class' => LogCleanUpTask::class, 'eager' => false],
					self::OLD.'2' => ['class' => LogCleanUpTask::class, 'eager' => false],
				]
			),
			$this->createMock(LoggerInterface::class)
		);
		$step->run($output);
	}//end testAFailureIsReportedAndDoesNotStopTheRun()

	/**
	 * The step has a name for occ.
	 *
	 * @return void
	 */
	public function testItHasAName(): void {
		$step = new RepointMovedBackgroundJobs(
			$this->createMock(IJobList::class),
			new ClassAliasMap(entries: []),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertStringContainsString('background jobs', $step->getName());
	}//end testItHasAName()

	/**
	 * A job list whose old rows carry the given arguments and that records every call.
	 *
	 * @param array<int, mixed> $rows  The arguments of the queued rows.
	 * @param array<int, mixed> $calls Receives the calls, in order.
	 *
	 * @return IJobList
	 */
	private function jobListWith(array $rows, array &$calls): IJobList {
		$jobs = [];
		foreach ($rows as $argument) {
			$job = $this->createMock(IJob::class);
			$job->method('getArgument')->willReturn($argument);
			$jobs[] = $job;
		}

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('getJobsIterator')->willReturnCallback(
			static function ($class) use (&$calls, $jobs): array {
				$calls[] = ['iterate', $class];
				return $jobs;
			}
		);
		$jobList->method('add')->willReturnCallback(
			static function ($class, $argument=null) use (&$calls): void {
				$calls[] = ['add', $class, $argument];
			}
		);
		$jobList->method('remove')->willReturnCallback(
			static function ($class, $argument=null) use (&$calls): void {
				$calls[] = ['remove', $class, $argument];
			}
		);

		return $jobList;
	}//end jobListWith()
}//end class

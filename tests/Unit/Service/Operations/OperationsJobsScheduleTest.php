<?php

/**
 * OperationsJobsService: a job's schedule, its alerts, and the refusals of a
 * job that is registered but cannot be built.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunFilter;
use OCA\OpenRegister\Db\JobRunMapperInterface;
use OCA\OpenRegister\Exception\JobRunRefusedException;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\JobScheduleChange;
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCA\OpenRegister\Service\Operations\OperationsJobsService;
use OCA\OpenRegister\Service\Operations\OperationsRunNowServices;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Operations\OperationsJobsService
 * @uses   \OCA\OpenRegister\Service\Operations\OperationsRunNowServices
 * @uses   \OCA\OpenRegister\Service\Operations\JobRunRecorder
 * @uses   \OCA\OpenRegister\Service\Operations\JobScheduleChange
 * @uses   \OCA\OpenRegister\Db\JobRunFilter
 * @uses   \OCA\OpenRegister\Db\JobRun
 * @uses   \OCA\OpenRegister\Exception\JobRunRefusedException
 */
final class OperationsJobsScheduleTest extends TestCase {

	private const JOB = 'OCA\\OpenRegister\\BackgroundJob\\SomeTimedJob';

	private JobRunMapperInterface&MockObject $runs;

	private JobScheduleService&MockObject $schedules;

	private JobAlertService&MockObject $alerts;

	private IJobList&MockObject $jobList;

	private ContainerInterface&MockObject $container;

	protected function setUp(): void {
		parent::setUp();

		$this->runs = $this->createMock(JobRunMapperInterface::class);
		$this->schedules = $this->createMock(JobScheduleService::class);
		$this->alerts = $this->createMock(JobAlertService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->container = $this->createMock(ContainerInterface::class);
	}

	private function service(): OperationsJobsService {
		$recorder = new JobRunRecorder($this->runs, $this->createMock(LoggerInterface::class), $this->alerts);

		return new OperationsJobsService(
			$this->runs,
			$this->schedules,
			$this->alerts,
			new OperationsRunNowServices($this->jobList, $this->container, $recorder)
		);
	}

	/**
	 * The schedule is described with the start of the job's last run.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function testTheScheduleCarriesTheLastRunsStart(): void {
		$run = new JobRun();
		$run->setStarted(new DateTime('@1767225600'));

		$this->runs->expects($this->once())->method('findRecent')->with(
			$this->callback(static fn (JobRunFilter $filter): bool => $filter->jobClass === self::JOB),
			1
		)->willReturn([$run]);
		$this->schedules->expects($this->once())->method('describe')->with(self::JOB, 1767225600)->willReturn(['interval' => 300]);

		$this->assertSame(['interval' => 300], $this->service()->schedule(self::JOB));
	}

	/**
	 * A job that never ran, or whose run has no start, is described with 0.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function testAJobThatNeverRanIsDescribedWithZero(): void {
		$this->runs->method('findRecent')->willReturnOnConsecutiveCalls([], [new JobRun()]);
		$this->schedules->expects($this->exactly(2))->method('describe')->with(self::JOB, 0)->willReturn([]);

		$this->service()->schedule(self::JOB);
		$this->service()->schedule(self::JOB);
	}

	/**
	 * Administering a schedule changes it, then answers the schedule now in force.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function testAdministeringAScheduleAnswersTheOneNowInForce(): void {
		$change = new JobScheduleChange(enabled: false, intervalSeconds: 600);
		$order = [];

		$this->runs->method('findRecent')->willReturn([]);
		$this->schedules->expects($this->once())->method('administer')->with(self::JOB, $this->identicalTo($change))->willReturnCallback(
			static function () use (&$order): array {
				$order[] = 'administer';
				return [];
			}
		);
		$this->schedules->expects($this->once())->method('describe')->with(self::JOB, 0)->willReturnCallback(
			static function () use (&$order): array {
				$order[] = 'describe';
				return ['enabled' => false, 'interval' => 600];
			}
		);

		$this->assertSame(['enabled' => false, 'interval' => 600], $this->service()->administerSchedule(self::JOB, $change));
		$this->assertSame(['administer', 'describe'], $order);
	}

	/**
	 * The alerts come with the threshold settings in force.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function testTheAlertsComeWithTheirSettings(): void {
		$this->alerts->method('settings')->willReturn(['threshold' => 3, 'periodMinutes' => 60]);
		$this->alerts->expects($this->once())->method('alerts')->with([self::JOB])->willReturn([['job' => self::JOB, 'failures' => 4]]);

		$this->assertSame(
			[
				'settings' => ['threshold' => 3, 'periodMinutes' => 60],
				'alerts' => [['job' => self::JOB, 'failures' => 4]],
			],
			$this->service()->alerts([self::JOB])
		);
	}

	/**
	 * When the job list cannot be asked, the job is unknown: nothing is started.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function testAJobListThatFailsMakesTheJobUnknown(): void {
		$this->jobList->method('has')->willThrowException(new RuntimeException('database gone'));
		$this->container->expects($this->never())->method('get');

		$refusal = $this->refusal(fn () => $this->service()->runNow(self::JOB, 'noor'));

		$this->assertSame('unknown-job', $refusal->getReason());
		$this->assertSame('There is no job called "'.self::JOB.'" on this instance.', $refusal->getMessage());
	}

	/**
	 * A registered job the container cannot build is refused as unresolvable.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function testAJobThatCannotBeBuiltIsRefused(): void {
		$this->jobList->method('has')->willReturn(true);
		$this->container->method('get')->willThrowException(new RuntimeException('missing dependency'));
		$this->runs->expects($this->never())->method('insert');

		$refusal = $this->refusal(fn () => $this->service()->runNow(self::JOB, 'noor'));

		$this->assertSame('unresolvable', $refusal->getReason());
		$this->assertSame('This job could not be built: missing dependency', $refusal->getMessage());
	}

	/**
	 * A registered class that is not a background job is never started.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function testAClassThatIsNotAJobIsRefused(): void {
		$this->jobList->method('has')->willReturn(true);
		$this->container->method('get')->willReturn(new stdClass());
		$this->runs->expects($this->never())->method('insert');

		$refusal = $this->refusal(fn () => $this->service()->runNow(self::JOB, 'noor'));

		$this->assertSame('not-a-job', $refusal->getReason());
		$this->assertSame('The class "'.self::JOB.'" is not a background job.', $refusal->getMessage());
	}

	/**
	 * The refusal a call throws.
	 *
	 * @param callable $call The call.
	 */
	private function refusal(callable $call): JobRunRefusedException {
		try {
			$call();
		} catch (JobRunRefusedException $refusal) {
			return $refusal;
		}

		$this->fail('The job was not refused.');
	}
}

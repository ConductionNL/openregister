<?php

/**
 * Unit tests for OperationsJobsService — run now, once, and the schedule.
 *
 * The one behaviour worth protecting hardest is the refusal: a console that
 * starts a second copy of a running job is how two termijn sweeps run at once,
 * and the damage is done before anybody reads a log. So the refusal is
 * asserted on three things at once — that it refuses, that the job is NOT
 * started, and that it names the run it collided with.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Exception\JobRunRefusedException;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCA\OpenRegister\Service\Operations\OperationsJobsService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class OperationsJobsServiceTest extends TestCase {

	/**
	 * The run log.
	 *
	 * @var JobRunMapper
	 */
	private JobRunMapper $runs;

	/**
	 * Nextcloud's registered jobs.
	 *
	 * @var IJobList
	 */
	private IJobList $jobList;

	/**
	 * The job the container hands back.
	 *
	 * @var IJob
	 */
	private IJob $job;

	/**
	 * How many times the job was started.
	 *
	 * @var integer
	 */
	private int $started = 0;

	/**
	 * The run currently holding the job, when one does.
	 *
	 * @var JobRun|null
	 */
	private ?JobRun $holding = null;

	/**
	 * The rows the recorder wrote.
	 *
	 * @var array<int, JobRun>
	 */
	private array $written = [];

	protected function setUp(): void {
		parent::setUp();

		$this->runs = $this->createMock(JobRunMapper::class);
		$this->runs->method('findRunning')->willReturnCallback(fn (): ?JobRun => $this->holding);
		$this->runs->method('findRecent')->willReturnCallback(fn (): array => $this->written);
		$this->runs->method('insert')->willReturnCallback(
			function (JobRun $run): JobRun {
				$this->written[] = $run;
				$run->setId(count($this->written));

				return $run;
			}
		);
		$this->runs->method('update')->willReturnArgument(0);

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('has')->willReturn(true);

		$this->job = $this->createMock(IJob::class);
		$this->job->method('start')->willReturnCallback(
			function (): void {
				$this->started++;
			}
		);
	}

	private function service(): OperationsJobsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->job);

		$recorder = new JobRunRecorder(
			$this->runs,
			$this->createMock(LoggerInterface::class),
			$this->createMock(JobAlertService::class)
		);

		return new OperationsJobsService(
			$this->runs,
			$this->createMock(JobScheduleService::class),
			$this->createMock(JobAlertService::class),
			$this->jobList,
			$container,
			$recorder
		);
	}

	/**
	 * Starting a job by hand runs it once and records who asked.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testRunNowStartsTheJobAndRecordsTheAdministratorAsItsCause(): void {
		$answer = $this->service()->runNow('Acme\\NightlyJob', 'noor');

		$this->assertTrue($answer['started']);
		$this->assertSame(1, $this->started);
		$this->assertSame('noor', $this->written[0]->getActor());
		$this->assertSame(JobRun::CAUSE_MANUAL, $this->written[0]->getCause());
	}

	/**
	 * A job already running is refused, the job is not started, and the
	 * refusal names the run that holds it.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testARunningJobIsNotStartedASecondTimeAndTheRefusalNamesTheRun(): void {
		$this->holding = new JobRun();
		$this->holding->setId(41);
		$this->holding->setJobClass('Acme\\NightlyJob');
		$this->holding->setOutcome(JobRun::OUTCOME_RUNNING);
		$this->holding->setCause(JobRun::CAUSE_SCHEDULE);
		$this->holding->setStarted(new DateTime('2026-09-18 03:00:00'));

		$refused = null;

		try {
			$this->service()->runNow('Acme\\NightlyJob', 'noor');
		} catch (JobRunRefusedException $refusal) {
			$refused = $refusal;
		}

		$this->assertNotNull($refused, 'A second copy of a running job was started.');
		$this->assertSame('already-running', $refused->getReason());
		$this->assertSame(41, $refused->getDetails()['runId']);
		$this->assertSame(0, $this->started, 'The job ran despite the refusal.');
	}

	/**
	 * A job this instance does not have is refused rather than built from a
	 * class name the browser supplied.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 *
	 * @return void
	 */
	public function testAnUnregisteredClassCannotBeStarted(): void {
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('has')->willReturn(false);

		$refused = null;

		try {
			$this->service()->runNow('Evil\\Payload', 'noor');
		} catch (JobRunRefusedException $refusal) {
			$refused = $refusal;
		}

		$this->assertNotNull($refused, 'An unregistered class was instantiated and started.');
		$this->assertSame('unknown-job', $refused->getReason());
		$this->assertSame(0, $this->started);
	}

	/**
	 * A maintenance action is startable by its own slug, and only by a slug
	 * this app ships.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-actions-run-as-observable-jobs-req-aoc-004
	 *
	 * @return void
	 */
	public function testAMaintenanceActionIsStartedByItsSlug(): void {
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('has')->willReturn(false);

		$answer = $this->service()->runNow('search-index-rebuild', 'noor');

		$this->assertSame(
			OperationsJobsService::MAINTENANCE_ACTIONS['search-index-rebuild'],
			$answer['job']
		);
		$this->assertSame(1, $this->started);
	}

	/**
	 * The run history carries the filters it was asked for, so a reader can
	 * tell a narrowed list from the whole one.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 *
	 * @return void
	 */
	public function testTheRunHistoryReportsTheFiltersItApplied(): void {
		$this->runs->method('countRecent')->willReturn(0);

		$answer = $this->service()->runs('Acme\\NightlyJob', JobRun::OUTCOME_FAILED, 24);

		$this->assertSame('Acme\\NightlyJob', $answer['filters']['job']);
		$this->assertSame(JobRun::OUTCOME_FAILED, $answer['filters']['outcome']);
		$this->assertSame(24, $answer['filters']['windowHours']);
	}
}

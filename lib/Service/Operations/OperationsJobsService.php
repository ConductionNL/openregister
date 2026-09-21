<?php

/**
 * OperationsJobsService: the run history, run now, and the schedule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use DateTime;
use OCA\OpenRegister\BackgroundJob\CacheClearAndWarmJob;
use OCA\OpenRegister\BackgroundJob\ConsistencyCheckJob;
use OCA\OpenRegister\BackgroundJob\SearchIndexRebuildJob;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Exception\JobRunRefusedException;
use OCP\BackgroundJob\IJobList;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * What the console does to jobs, as opposed to what it reads about them.
 *
 * Three acts live here: listing the run history with its filters, starting a
 * job by hand, and administering a recurring job's schedule. They are together
 * because they share one invariant, which is the interesting part of D-2: a
 * job already running is never started a second time, and the refusal names
 * the run that holds it, so the administrator learns "it is already going" and
 * not merely "no".
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
 */
class OperationsJobsService {

	/**
	 * The maintenance actions the console may start, by their own names.
	 *
	 * Run now takes a job class from the browser, and a class name from the
	 * browser that is instantiated is a remote code path. So the maintenance
	 * actions are named here, and anything else must already be registered on
	 * the instance's job list before it can be started.
	 *
	 * @var array<string, string>
	 */
	public const MAINTENANCE_ACTIONS = [
		'search-index-rebuild' => SearchIndexRebuildJob::class,
		'cache-clear-and-warm' => CacheClearAndWarmJob::class,
		'consistency-check' => ConsistencyCheckJob::class,
	];

	/**
	 * Constructor.
	 *
	 * @param JobRunMapper       $runs      The run log.
	 * @param JobScheduleService $schedules The administered schedules.
	 * @param JobAlertService    $alerts    The failure threshold.
	 * @param IJobList           $jobList   Nextcloud's registered jobs.
	 * @param ContainerInterface $container Resolves a job class to a job.
	 * @param JobRunRecorder     $recorder  Records the run-now, with its cause.
	 */
	public function __construct(
		private readonly JobRunMapper $runs,
		private readonly JobScheduleService $schedules,
		private readonly JobAlertService $alerts,
		private readonly IJobList $jobList,
		private readonly ContainerInterface $container,
		private readonly JobRunRecorder $recorder,
	) {
	}//end __construct()

	/**
	 * The run history, filtered by job, outcome and period.
	 *
	 * @param string|null $jobClass    Narrow to one job class.
	 * @param string|null $outcome     Narrow to one outcome.
	 * @param int|null    $windowHours How far back to look, null for all of it.
	 * @param int         $limit       How many rows to return.
	 * @param int         $offset      Where to start.
	 *
	 * @return array<string, mixed> The rows, and how many there are.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function runs(
		?string $jobClass = null,
		?string $outcome = null,
		?int $windowHours = null,
		int $limit = JobRunMapper::DEFAULT_LIMIT,
		int $offset = 0,
	): array {
		$since = null;

		if ($windowHours !== null && $windowHours > 0) {
			$since = new DateTime('-'.$windowHours.' hours');
		}

		$rows = $this->runs->findRecent(
			jobClass: $jobClass,
			outcome: $outcome,
			since: $since,
			limit: $limit,
			offset: $offset
		);

		return [
			'results' => array_map(static fn (JobRun $run): array => $run->jsonSerialize(), $rows),
			'total' => $this->runs->countRecent(jobClass: $jobClass, outcome: $outcome, since: $since),
			'filters' => [
				'job' => $jobClass,
				'outcome' => $outcome,
				'windowHours' => $windowHours,
			],
		];

	}//end runs()

	/**
	 * Start a job by hand, once.
	 *
	 * @param string $jobClass The job class, or a maintenance action slug.
	 * @param string $actor    The uid asking for it.
	 *
	 * @return array<string, mixed> The run that was started.
	 *
	 * @throws JobRunRefusedException When the job is unknown, or already running.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function runNow(string $jobClass, string $actor): array {
		$class = (self::MAINTENANCE_ACTIONS[$jobClass] ?? $jobClass);

		if ($this->mayBeStarted(class: $class) === false) {
			throw new JobRunRefusedException(
				message: 'There is no job called "'.$jobClass.'" on this instance.',
				reason: 'unknown-job'
			);
		}

		$holding = $this->runs->findRunning(jobClass: $class);

		if ($holding !== null) {
			// D-2: naming the run is the difference between a refusal an
			// administrator can act on and one they retry until it sticks.
			throw new JobRunRefusedException(
				message: 'This job is already running. It started at '
					.((string)$holding->getStarted()?->format(DateTime::ATOM)).'.',
				reason: 'already-running',
				details: [
					'runId' => $holding->getId(),
					'startedAt' => $holding->getStarted()?->format(DateTime::ATOM),
					'cause' => $holding->getCause(),
					'actor' => $holding->getActor(),
				]
			);
		}

		$job = $this->resolve(class: $class);

		$this->recorder->around(
			jobClass: $class,
			work: function () use ($job): void {
				$job->start($this->jobList);
			},
			cause: JobRun::CAUSE_MANUAL,
			actor: $actor
		);

		$started = $this->runs->findRecent(jobClass: $class, limit: 1);

		return [
			'job' => $class,
			'started' => true,
			'run' => ($started[0] ?? null)?->jsonSerialize(),
		];

	}//end runNow()

	/**
	 * One job's schedule, with its last run and its next due time.
	 *
	 * @param string $jobClass The job class.
	 *
	 * @return array<string, mixed> The schedule.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function schedule(string $jobClass): array {
		return $this->schedules->describe(
			jobClass: $jobClass,
			lastRun: $this->lastRunTimestamp(jobClass: $jobClass)
		);

	}//end schedule()

	/**
	 * Administer one job's schedule.
	 *
	 * @param string    $jobClass        The job class.
	 * @param bool|null $enabled         Whether it may run at all.
	 * @param int|null  $intervalSeconds How often it is due.
	 * @param int|null  $windowStartHour The first hour it may run in.
	 * @param int|null  $windowEndHour   The last hour it may run in.
	 *
	 * @return array<string, mixed> The schedule now in force.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function administerSchedule(
		string $jobClass,
		?bool $enabled = null,
		?int $intervalSeconds = null,
		?int $windowStartHour = null,
		?int $windowEndHour = null,
	): array {
		$this->schedules->administer(
			jobClass: $jobClass,
			enabled: $enabled,
			intervalSeconds: $intervalSeconds,
			windowStartHour: $windowStartHour,
			windowEndHour: $windowEndHour
		);

		return $this->schedule(jobClass: $jobClass);

	}//end administerSchedule()

	/**
	 * Every job currently over the failure threshold.
	 *
	 * @param array<int, string> $jobClasses The jobs to look at.
	 *
	 * @return array<string, mixed> The alerts, and the threshold in force.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function alerts(array $jobClasses): array {
		return [
			'settings' => $this->alerts->settings(),
			'alerts' => $this->alerts->alerts(jobClasses: $jobClasses),
		];

	}//end alerts()

	/**
	 * The unix time of a job's last run, 0 when it never ran.
	 *
	 * @param string $jobClass The job class.
	 *
	 * @return int The timestamp.
	 */
	private function lastRunTimestamp(string $jobClass): int {
		$rows = $this->runs->findRecent(jobClass: $jobClass, limit: 1);

		if ($rows === []) {
			return 0;
		}

		return (int)($rows[0]->getStarted()?->getTimestamp() ?? 0);

	}//end lastRunTimestamp()

	/**
	 * May this class be started from the console at all.
	 *
	 * @param string $class The job class.
	 *
	 * @return bool True when it is a maintenance action or a registered job.
	 */
	private function mayBeStarted(string $class): bool {
		if (in_array($class, self::MAINTENANCE_ACTIONS, true) === true) {
			return true;
		}

		try {
			// A registered recurring job carries no argument, which is the
			// case run now serves; a queued job with an argument was asked for
			// by something that already decided it should happen.
			return $this->jobList->has($class, null);
		} catch (Throwable $exception) {
			return false;
		}

	}//end mayBeStarted()

	/**
	 * Build the job.
	 *
	 * @param string $class The job class.
	 *
	 * @return \OCP\BackgroundJob\IJob The job.
	 *
	 * @throws JobRunRefusedException When it cannot be built.
	 */
	private function resolve(string $class): \OCP\BackgroundJob\IJob {
		try {
			$job = $this->container->get($class);
		} catch (Throwable $exception) {
			throw new JobRunRefusedException(
				message: 'This job could not be built: '.$exception->getMessage(),
				reason: 'unresolvable'
			);
		}

		if (($job instanceof \OCP\BackgroundJob\IJob) === false) {
			throw new JobRunRefusedException(
				message: 'The class "'.$class.'" is not a background job.',
				reason: 'not-a-job'
			);
		}

		return $job;

	}//end resolve()
}//end class

<?php

/**
 * The run history, run now and the schedule of the app's jobs, as the operations console reads and changes them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Db\JobRunMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Exception\JobRunRefusedException;

/**
 * The run history, run now and the schedule of the app's jobs, as the operations console reads and changes them.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see OperationsJobsService} in ContractBindings.
 */
interface OperationsJobsServiceInterface {

	/**
	 * The run history, filtered by job, outcome and period.
	 *
	 * @param JobRunFilter $filter The job, outcome and period to narrow to.
	 * @param ListQuery    $page   How many rows to return, and where to start.
	 *
	 * @return array<string, mixed> The rows, and how many there are.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function runs(
		JobRunFilter $filter = new JobRunFilter(),
		ListQuery $page = new ListQuery(limit: JobRunMapperInterface::DEFAULT_LIMIT, offset: 0),
	): array;

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
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function runNow(string $jobClass, string $actor): array;

	/**
	 * One job's schedule, with its last run and its next due time.
	 *
	 * @param string $jobClass The job class.
	 *
	 * @return array<string, mixed> The schedule.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function schedule(string $jobClass): array;

	/**
	 * Administer one job's schedule.
	 *
	 * @param string            $jobClass The job class.
	 * @param JobScheduleChange $change   What to change; a null part is left as it is.
	 *
	 * @return array<string, mixed> The schedule now in force.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function administerSchedule(string $jobClass, JobScheduleChange $change): array;

	/**
	 * Every job currently over the failure threshold.
	 *
	 * @param array<int, string> $jobClasses The jobs to look at.
	 *
	 * @return array<string, mixed> The alerts, and the threshold in force.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function alerts(array $jobClasses): array;
}//end interface

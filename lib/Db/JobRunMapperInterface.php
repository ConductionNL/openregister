<?php

/**
 * JobRunMapperInterface: what callers outside the Db component use of JobRunMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of JobRunMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to JobRunMapper.
 */
interface JobRunMapperInterface {

	/**
	 * How many rows one run-log read returns unless the caller asks for fewer.
	 *
	 * @var integer
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * How many runs match, under the same narrowing.
	 *
	 * @param string|null   $jobClass Narrow to one job class.
	 * @param string|null   $outcome  Narrow to one JobRun OUTCOME_ constant.
	 * @param DateTime|null $since    Only runs started at or after this moment.
	 * @param DateTime|null $until    Only runs started at or before this moment.
	 *
	 * @return int The number of matching rows.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function countRecent(
		?string $jobClass = null,
		?string $outcome = null,
		?DateTime $since = null,
		?DateTime $until = null,
	): int;

	/**
	 * The failed runs of one job inside a period, oldest first.
	 *
	 * The alert names the FIRST failure in the period (REQ-AOC-003), so the
	 * order is ascending here on purpose: a descending read would have to be
	 * reversed by the caller, and a caller that forgets names the newest.
	 *
	 * @param string   $jobClass The job class.
	 * @param DateTime $since    The start of the period.
	 *
	 * @return array<int, JobRun> The failures, oldest first.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function failuresSince(string $jobClass, DateTime $since): array;

	/**
	 * The most recent runs, narrowed by job, outcome and period.
	 *
	 * @param string|null   $jobClass Narrow to one job class.
	 * @param string|null   $outcome  Narrow to one JobRun OUTCOME_ constant.
	 * @param DateTime|null $since    Only runs started at or after this moment.
	 * @param DateTime|null $until    Only runs started at or before this moment.
	 * @param int           $limit    How many rows to return, capped at MAX_LIMIT.
	 * @param int           $offset   Where to start.
	 *
	 * @return array<int, JobRun> The rows, newest first.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function findRecent(
		?string $jobClass = null,
		?string $outcome = null,
		?DateTime $since = null,
		?DateTime $until = null,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
	): array;

	/**
	 * The run currently holding a job, when one holds it.
	 *
	 * A second run of a job already running is what D-2 refuses, and it can
	 * only be refused by naming the run that holds it, which is this read.
	 *
	 * @param string $jobClass The job class.
	 *
	 * @return JobRun|null The holding run, or null when the job is free.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function findRunning(string $jobClass): ?JobRun;

	/**
	 * Inherited from QBMapper::insert().
	 *
	 * @param JobRun $entity The entity.
	 *
	 * @return JobRun
	 *
	 * @spec exclude Inherited persistence primitive; no behaviour of its own.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Point the run history of a moved job at its new class name.
	 *
	 * @param string $from The class name the runs were recorded under.
	 * @param string $to   The class name the job now has.
	 *
	 * @return int How many rows were updated.
	 *
	 * @spec exclude Upgrade plumbing for moved job classes; the run log itself is unchanged.
	 */
	public function renameJobClass(string $from, string $to): int;

	/**
	 * Inherited from QBMapper::update().
	 *
	 * @param JobRun $entity The entity.
	 *
	 * @return JobRun
	 *
	 * @spec exclude Inherited persistence primitive; no behaviour of its own.
	 */
	public function update(Entity $entity): Entity;
}//end interface

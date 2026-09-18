<?php

/**
 * JobRunMapper: reads and writes the background job run log.
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
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class JobRunMapper.
 *
 * Every read here is narrowed on `started`, `job_class` or `outcome`, which
 * are the three columns the console filters on and the three the migration
 * indexes (ADR-009). The run log grows by one row per job per cron tick, so a
 * read that scans it is a read that gets slower every day.
 *
 * @method JobRun insert(Entity $entity)
 * @method JobRun update(Entity $entity)
 * @method JobRun delete(Entity $entity)
 *
 * @template-extends QBMapper<JobRun>
 *
 * @psalm-suppress PossiblyUnusedMethod
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
class JobRunMapper extends QBMapper {

	/**
	 * How many rows one run-log read returns unless the caller asks for fewer.
	 *
	 * @var integer
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * The most rows one run-log read will ever return.
	 *
	 * @var integer
	 */
	public const MAX_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_job_runs',
			entityClass: JobRun::class
		);

	}//end __construct()

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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function findRecent(
		?string $jobClass = null,
		?string $outcome = null,
		?DateTime $since = null,
		?DateTime $until = null,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('started', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min($limit, self::MAX_LIMIT)))
			->setFirstResult(max(0, $offset));

		$this->narrow(qb: $qb, jobClass: $jobClass, outcome: $outcome, since: $since, until: $until);

		return $this->findEntities(query: $qb);

	}//end findRecent()

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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function countRecent(
		?string $jobClass = null,
		?string $outcome = null,
		?DateTime $since = null,
		?DateTime $until = null,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName());

		$this->narrow(qb: $qb, jobClass: $jobClass, outcome: $outcome, since: $since, until: $until);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (is_array($row) === false) {
			return 0;
		}

		return (int)($row['total'] ?? 0);

	}//end countRecent()

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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function findRunning(string $jobClass): ?JobRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('job_class', $qb->createNamedParameter($jobClass)))
			->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter(JobRun::OUTCOME_RUNNING)))
			->orderBy('started', 'DESC')
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);

		if ($rows === []) {
			return null;
		}

		return $rows[0];

	}//end findRunning()

	/**
	 * The last run of every job that has one, keyed by job class.
	 *
	 * The console needs "when did each job last run and how did it come out"
	 * for a page of jobs at once; asking per job is a query per row.
	 *
	 * @param int $limit How many jobs to answer for.
	 *
	 * @return array<string, JobRun> The newest run per job class.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function lastRunPerJob(int $limit = self::MAX_LIMIT): array {
		$newest = [];

		foreach ($this->findRecent(limit: max(1, min($limit, self::MAX_LIMIT))) as $run) {
			$class = (string)$run->getJobClass();

			if (array_key_exists($class, $newest) === true) {
				continue;
			}

			$newest[$class] = $run;
		}

		return $newest;

	}//end lastRunPerJob()

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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function failuresSince(string $jobClass, DateTime $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('job_class', $qb->createNamedParameter($jobClass)))
			->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter(JobRun::OUTCOME_FAILED)))
			->andWhere(
				$qb->expr()->gte('started', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			)
			->orderBy('started', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(self::MAX_LIMIT);

		return $this->findEntities(query: $qb);

	}//end failuresSince()

	/**
	 * Delete runs that started before a moment.
	 *
	 * @param DateTime $before The cut-off.
	 *
	 * @return int How many rows were deleted.
	 */
	public function pruneBefore(DateTime $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->lt('started', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);

		return (int)$qb->executeStatement();

	}//end pruneBefore()

	/**
	 * Apply the three filters the console offers.
	 *
	 * @param IQueryBuilder $qb       The query being built.
	 * @param string|null   $jobClass Narrow to one job class.
	 * @param string|null   $outcome  Narrow to one outcome.
	 * @param DateTime|null $since    Lower bound on `started`.
	 * @param DateTime|null $until    Upper bound on `started`.
	 *
	 * @return void
	 */
	private function narrow(
		IQueryBuilder $qb,
		?string $jobClass,
		?string $outcome,
		?DateTime $since,
		?DateTime $until,
	): void {
		if ($jobClass !== null && $jobClass !== '') {
			$qb->andWhere($qb->expr()->eq('job_class', $qb->createNamedParameter($jobClass)));
		}

		if ($outcome !== null && $outcome !== '') {
			$qb->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter($outcome)));
		}

		if ($since !== null) {
			$qb->andWhere(
				$qb->expr()->gte('started', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);
		}

		if ($until !== null) {
			$qb->andWhere(
				$qb->expr()->lte('started', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);
		}

	}//end narrow()
}//end class

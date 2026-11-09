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
 * @spec openspec/specs/operations-console/spec.md
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
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
class JobRunMapper extends QBMapper implements JobRunMapperInterface {

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
	 * @param JobRunFilter|null $filter The job, outcome and period to narrow to; none narrows nothing.
	 * @param int               $limit  How many rows to return, capped at MAX_LIMIT.
	 * @param int               $offset Where to start.
	 *
	 * @return array<int, JobRun> The rows, newest first.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function findRecent(
		?JobRunFilter $filter = null,
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

		$this->narrow(qb: $qb, filter: $filter ?? new JobRunFilter());

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
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function countRecent(
		string|null $jobClass = null,
		string|null $outcome = null,
		DateTime|null $since = null,
		DateTime|null $until = null,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($this->getTableName());

		$filter = new JobRunFilter(jobClass: $jobClass, outcome: $outcome, since: $since, until: $until);
		$this->narrow(qb: $qb, filter: $filter);

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
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
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
	 * Point the run history of a moved job at its new class name.
	 *
	 * @param string $from The class name the runs were recorded under.
	 * @param string $to   The class name the job now has.
	 *
	 * @return int How many rows were updated.
	 *
	 * @spec exclude Upgrade plumbing for moved job classes; the run log itself is unchanged.
	 */
	public function renameJobClass(string $from, string $to): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('job_class', $qb->createNamedParameter($to))
			->where($qb->expr()->eq('job_class', $qb->createNamedParameter($from)));

		return (int)$qb->executeStatement();

	}//end renameJobClass()

	/**
	 * Apply the filters the console offers.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param JobRunFilter  $filter The job class, outcome and period to narrow to.
	 *
	 * @return void
	 */
	private function narrow(IQueryBuilder $qb, JobRunFilter $filter): void {
		$this->narrowToEquals(qb: $qb, filter: $filter);
		$this->narrowToPeriod(qb: $qb, filter: $filter);

	}//end narrow()

	/**
	 * Narrow to the job class and outcome, when given and not empty.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param JobRunFilter  $filter The filter.
	 *
	 * @return void
	 */
	private function narrowToEquals(IQueryBuilder $qb, JobRunFilter $filter): void {
		$equals = ['job_class' => $filter->jobClass, 'outcome' => $filter->outcome];

		foreach ($equals as $column => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		}

	}//end narrowToEquals()

	/**
	 * Narrow `started` to the period, when bounds are given.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param JobRunFilter  $filter The filter.
	 *
	 * @return void
	 */
	private function narrowToPeriod(IQueryBuilder $qb, JobRunFilter $filter): void {
		if ($filter->since !== null) {
			$qb->andWhere(
				$qb->expr()->gte('started', $qb->createNamedParameter($filter->since, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);
		}

		if ($filter->until !== null) {
			$qb->andWhere(
				$qb->expr()->lte('started', $qb->createNamedParameter($filter->until, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);
		}

	}//end narrowToPeriod()
}//end class

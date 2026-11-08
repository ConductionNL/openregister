<?php

/**
 * RuleRunMapper: reads and writes the rule evaluation log.
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class RuleRunMapper.
 *
 * @method RuleRun insert(Entity $entity)
 * @method RuleRun update(Entity $entity)
 * @method RuleRun delete(Entity $entity)
 *
 * @template-extends QBMapper<RuleRun>
 *
 * @psalm-suppress PossiblyUnusedMethod
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class RuleRunMapper extends QBMapper implements RuleRunMapperInterface {

	// DEFAULT_LIMIT is declared on RuleRunMapperInterface.

	/**
	 * The most rows one run-log read will ever return.
	 *
	 * A rule that fires on every save has an unbounded history, so the page
	 * size is capped here rather than trusted from the query string.
	 *
	 * @var integer
	 */
	public const MAX_LIMIT = 500;

	/**
	 * How many rows one prune statement deletes.
	 *
	 * The prune runs daily over a log that grows with every save, so it deletes
	 * in batches: one unbounded DELETE over months of rows is the statement
	 * that locks the table and gets killed half way, leaving the operator with
	 * neither the rows nor the space.
	 *
	 * @var integer
	 */
	public const PRUNE_BATCH = 2000;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_rule_runs',
			entityClass: RuleRun::class
		);

	}//end __construct()

	/**
	 * One rule's runs, newest first, optionally narrowed.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string|null $verdict Narrow to one verdict.
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param DateTime|null $until Only runs at or before this moment.
	 * @param int $limit How many rows to return, capped at MAX_LIMIT.
	 * @param int $offset Where to start.
	 *
	 * @return array<int, RuleRun> The rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findByRule(
		string $ruleId,
		?string $verdict = null,
		?DateTime $since = null,
		?DateTime $until = null,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)));
		$this->newestFirstPage(qb: $qb, limit: $limit, offset: $offset);
		$this->applyRunFilters(qb: $qb, verdict: $verdict, range: [$since, $until]);

		return $this->findEntities(query: $qb);

	}//end findByRule()

	/**
	 * The most recent runs across every rule.
	 *
	 * The per-rule listing answers "how is this rule doing"; an operations
	 * console asks the other question, "what has the engine been doing", and
	 * cannot ask it by walking the rules one at a time. The order and the
	 * narrowing are the same as {@see findByRule()}, minus the rule.
	 *
	 * Index-backed on `created` (`or_rulerun_created_idx`), which is the
	 * column the ordering and the window both use, so the console never
	 * scans the run log (ADR-009).
	 *
	 * @param string|null   $verdict Narrow to one verdict.
	 * @param DateTime|null $since   Only runs at or after this moment.
	 * @param int           $limit   How many rows to return, capped at MAX_LIMIT.
	 * @param int           $offset  Where to start.
	 *
	 * @return array<int, RuleRun> The rows, newest first.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function findRecent(
		?string $verdict = null,
		?DateTime $since = null,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$this->newestFirstPage(qb: $qb, limit: $limit, offset: $offset);
		$this->applyRunFilters(qb: $qb, verdict: $verdict, range: [$since, null]);

		return $this->findEntities(query: $qb);

	}//end findRecent()

	/**
	 * How many runs one rule has in the log, under the same filters.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string|null $verdict Narrow to one verdict.
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param DateTime|null $until Only runs at or before this moment.
	 *
	 * @return int The number of matching rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function countByRule(
		string $ruleId,
		?string $verdict = null,
		?DateTime $since = null,
		?DateTime $until = null,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'run_count')->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)));
		$this->applyRunFilters(qb: $qb, verdict: $verdict, range: [$since, $until]);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['run_count'] ?? 0);

	}//end countByRule()

	/**
	 * Order newest first (id breaks ties) and take one page, the limit clamped to 1..MAX_LIMIT.
	 *
	 * @param IQueryBuilder $qb     The query.
	 * @param int           $limit  The page size.
	 * @param int           $offset The offset, at least 0.
	 *
	 * @return void
	 */
	private function newestFirstPage(IQueryBuilder $qb, int $limit, int $offset): void {
		$qb->orderBy('created', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min($limit, self::MAX_LIMIT)))
			->setFirstResult(max(0, $offset));
	}//end newestFirstPage()

	/**
	 * Narrow to one verdict (none for null or empty) and to a created range (a null bound is open).
	 *
	 * @param IQueryBuilder                     $qb      The query.
	 * @param string|null                       $verdict The verdict, or null/empty for any.
	 * @param array{0: ?DateTime, 1: ?DateTime} $range   The since and until moments.
	 *
	 * @return void
	 */
	private function applyRunFilters(IQueryBuilder $qb, ?string $verdict, array $range): void {
		[$since, $until] = $range;
		$this->applyVerdict(qb: $qb, verdict: $verdict);
		if ($since !== null) {
			$qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}

		if ($until !== null) {
			$qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}
	}//end applyRunFilters()

	/**
	 * Narrow to one verdict; null or empty means any.
	 *
	 * @param IQueryBuilder $qb      The query.
	 * @param string|null   $verdict The verdict.
	 *
	 * @return void
	 */
	private function applyVerdict(IQueryBuilder $qb, ?string $verdict): void {
		if ($verdict !== null && $verdict !== '') {
			$qb->andWhere($qb->expr()->eq('verdict', $qb->createNamedParameter($verdict)));
		}
	}//end applyVerdict()

	/**
	 * Delete detail rows older than a cut-off, in bounded batches.
	 *
	 * The summary rows are untouched, which is the point: the inventory keeps
	 * answering "last run" and "last error" after the detail is gone.
	 *
	 * @param DateTime $before Rows created strictly before this moment go.
	 * @param int $batches How many batches to delete in one pass.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function pruneBefore(DateTime $before, int $batches = 25): int {
		$deleted = 0;
		for ($pass = 0; $pass < max(1, $batches); $pass++) {
			$ids = $this->idsBefore(before: $before, limit: self::PRUNE_BATCH);
			if ($ids === []) {
				break;
			}

			$del = $this->db->getQueryBuilder();
			$del->delete($this->getTableName())
				->where(
					$del->expr()->in('id', $del->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))
				);

			$deleted += (int)$del->executeStatement();
			if (count($ids) < self::PRUNE_BATCH) {
				break;
			}
		}

		return $deleted;

	}//end pruneBefore()

	/**
	 * The ids of the oldest rows past the cut-off, at most one batch of them.
	 *
	 * The prune selects ids and then deletes by id rather than issuing one
	 * DELETE with a LIMIT, because a LIMIT on a DELETE is not portable across
	 * the three databases this app supports and is silently dropped on one of
	 * them, which would turn a bounded batch back into the unbounded statement
	 * the batching exists to avoid.
	 *
	 * @param DateTime $before Rows created strictly before this moment.
	 * @param int $limit How many ids to take.
	 *
	 * @return array<int, int> The row ids.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function idsBefore(DateTime $before, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where(
				$qb->expr()->lt('created', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			)
			->orderBy('created', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}

		$result->closeCursor();

		return $ids;

	}//end idsBefore()
}//end class

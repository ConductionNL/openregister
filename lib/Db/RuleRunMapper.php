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
use OCP\DB\IResult;
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
		$selection = new RuleRunSelection(ruleId: $ruleId, filter: new RuleRunFilter(verdict: $verdict, since: $since, until: $until));
		return $this->findRuns(selection: $selection, page: new ListQuery(limit: $limit, offset: $offset));

	}//end findByRule()

	/**
	 * One rule's runs, newest first, narrowed by the selection's filter.
	 *
	 * @param RuleRunSelection $selection The rule and its filter.
	 * @param ListQuery        $page      The limit (capped at MAX_LIMIT) and offset; other fields are ignored.
	 *
	 * @return array<int, RuleRun> The rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findRuns(RuleRunSelection $selection, ListQuery $page): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($selection->ruleId)));
		$this->newestFirstPage(qb: $qb, page: self::clampedPage(limit: $page->limit, offset: $page->offset ?? 0));
		$selection->filter->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);

	}//end findRuns()

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
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param int           $limit How many rows to return, capped at MAX_LIMIT.
	 *
	 * @return array<int, RuleRun> The rows, newest first.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function findRecent(?DateTime $since = null, int $limit = self::DEFAULT_LIMIT): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$this->newestFirstPage(qb: $qb, page: self::clampedPage(limit: $limit, offset: 0));
		(new RuleRunFilter(since: $since))->applyTo(qb: $qb);

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
		$filter = new RuleRunFilter(verdict: $verdict, since: $since, until: $until);
		return $this->countRuns(selection: new RuleRunSelection(ruleId: $ruleId, filter: $filter));

	}//end countByRule()

	/**
	 * How many runs one rule has in the log, under the selection's filter.
	 *
	 * @param RuleRunSelection $selection The rule and its filter.
	 *
	 * @return int The number of matching rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function countRuns(RuleRunSelection $selection): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'run_count')->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($selection->ruleId)));
		$selection->filter->applyTo(qb: $qb);

		return $this->fetchRunCount(qb: $qb);

	}//end countRuns()

	/**
	 * Run a count query and read its `run_count` column; no row counts as zero.
	 *
	 * @param IQueryBuilder $qb The count query.
	 *
	 * @return int The count.
	 */
	private function fetchRunCount(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['run_count'] ?? 0);

	}//end fetchRunCount()

	/**
	 * One page with the limit clamped to 1..MAX_LIMIT and the offset to at least 0.
	 *
	 * @param int $limit  The page size asked for.
	 * @param int $offset The offset asked for.
	 *
	 * @return QueryPage
	 */
	private static function clampedPage(int $limit, int $offset): QueryPage {
		return new QueryPage(limit: max(1, min($limit, self::MAX_LIMIT)), offset: max(0, $offset));
	}//end clampedPage()

	/**
	 * Order newest first (id breaks ties) and take one page.
	 *
	 * @param IQueryBuilder $qb   The query.
	 * @param QueryPage     $page The page, already clamped.
	 *
	 * @return void
	 */
	private function newestFirstPage(IQueryBuilder $qb, QueryPage $page): void {
		$qb->orderBy('created', 'DESC')
			->addOrderBy('id', 'DESC');
		$page->applyTo(qb: $qb);
	}//end newestFirstPage()

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

			$deleted += $this->deleteIds(ids: $ids);
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

		return $this->intIdsOf(result: $qb->executeQuery());

	}//end idsBefore()

	/**
	 * The ids a result holds, as integers; the result is closed after.
	 *
	 * @param IResult $result The result.
	 *
	 * @return array<int, int> The ids.
	 */
	private function intIdsOf(IResult $result): array {
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}

		$result->closeCursor();

		return $ids;
	}//end intIdsOf()

	/**
	 * Delete runs by id.
	 *
	 * @param array<int, int> $ids The run ids.
	 *
	 * @return int How many rows were deleted.
	 */
	private function deleteIds(array $ids): int {
		$del = $this->db->getQueryBuilder();
		$del->delete($this->getTableName())
			->where(
				$del->expr()->in('id', $del->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))
			);

		return (int)$del->executeStatement();
	}//end deleteIds()
}//end class

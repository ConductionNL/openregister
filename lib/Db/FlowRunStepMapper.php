<?php

/**
 * Reads and writes flow-run steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use DateTimeInterface;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes flow-run steps.
 *
 * @template-extends QBMapper<FlowRunStep>
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
class FlowRunStepMapper extends QBMapper implements FlowRunStepMapperInterface {

	use SelectByColumnTrait;
	use StoredMomentTrait;

	/**
	 * The table that holds flow runs, read to bound a step aggregate to a window.
	 *
	 * @var string
	 */
	private const RUNS_TABLE = 'openregister_flow_runs';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_steps', entityClass: FlowRunStep::class);

	}//end __construct()

	/**
	 * Every step of one run, in walk order.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return array<int, FlowRunStep> The steps.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function findByRun(string $runUuid): array {
		$qb = $this->selectWhere(column: new LinkColumn(name: 'run_uuid', value: $runUuid));
		// CANONICAL order: by stream ordinal, then position within the
		// stream. A function of the path taken, never of which branch
		// returned first, so two runs of one flow read identically. Rows
		// written before streams existed carry the root path (back-filled)
		// and reproduce their old sequence order exactly.
		$qb->orderBy('ordinal_path', 'ASC')
			->addOrderBy('sequence', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByRun()

	/**
	 * The highest sequence recorded for a run so far.
	 *
	 * A resumed run must APPEND, so the walk continues numbering from here
	 * rather than restarting at zero — otherwise a suspended-then-resumed run
	 * reads as two overlapping histories and `ORDER BY sequence` interleaves
	 * them.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return integer The highest recorded sequence, or -1 when the run has no steps.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function highestSequence(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->max('sequence'), 'top')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		$top = ($row['top'] ?? null);
		if ($top === null) {
			return -1;
		}

		return (int)$top;
	}//end highestSequence()

	/**
	 * Delete the steps of one run.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return integer The number of rows removed.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function deleteByRun(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		return (int)$qb->executeStatement();
	}//end deleteByRun()

	/**
	 * The most recent steps of one node type, optionally of one status: "which runs failed at this node".
	 *
	 * @param string      $nodeType The namespaced node type, e.g. `openregister.set-fields`.
	 * @param string|null $status   Only steps with this status (null: any).
	 * @param int         $limit    The most rows to return.
	 *
	 * @return array<int, FlowRunStep> The steps, newest first.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function findByNodeType(string $nodeType, ?string $status = null, int $limit = 100): array {
		$qb = $this->selectWhere(column: new LinkColumn(name: 'node_type', value: $nodeType));
		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$qb->orderBy('id', 'DESC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities(query: $qb);
	}//end findByNodeType()

	/**
	 * Delete every step recorded before a moment, whatever run it belongs to.
	 *
	 * The retention sweep removes a run's steps with its run; this catches
	 * steps whose run row is already gone.
	 *
	 * @param DateTimeInterface $before Steps created before this moment are removed.
	 *
	 * @return integer The number of rows removed.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function deleteOlderThan(DateTimeInterface $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)));

		return (int)$qb->executeStatement();
	}//end deleteOlderThan()

	/**
	 * Per node of one flow: executions, failures and the average duration of
	 * the successful ones, over the runs the window counts.
	 *
	 * One grouped query on (flow_id, ...) with the window's runs as a subquery,
	 * whatever the number of runs.
	 *
	 * @param string   $flowId       The flow.
	 * @param string   $organisation Count only this organisation's runs.
	 * @param DateTime $from         Runs created from this moment.
	 * @param DateTime $to           Runs created up to this moment.
	 *
	 * @return array<string, array{executions: int, failed: int, averageMs: float|null}> Keyed by node id.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 */
	public function aggregatesByNode(string $flowId, string $organisation, DateTime $from, DateTime $to): array {

		$qb = $this->db->getQueryBuilder();
		$status = $this->quoted(identifier: 'status');
		$failed = $qb->createNamedParameter(FlowRunStep::STATUS_FAILED);
		$succeeded = $qb->createNamedParameter(FlowRunStep::STATUS_OK);
		$failures = sprintf('SUM(CASE WHEN %s = %s THEN 1 ELSE 0 END)', $status, $failed);
		$average = sprintf('AVG(CASE WHEN %s = %s THEN %s END)', $status, $succeeded, $this->quoted(identifier: 'duration_ms'));
		$qb->select('node_id')
			->selectAlias($qb->func()->count('id'), 'executions')
			->selectAlias($qb->createFunction($failures), 'failed')
			->selectAlias($qb->createFunction($average), 'average_ms')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)))
			->andWhere(
				$qb->expr()->in(
					'run_uuid',
					$qb->createFunction($this->windowRunsSql(qb: $qb, scope: [$flowId, $organisation], between: [$from, $to], status: null))
				)
			)
			->groupBy('node_id');

		$aggregates = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$average = null;
			if (($row['average_ms'] ?? null) !== null) {
				$average = (float)$row['average_ms'];
			}

			$aggregates[(string)$row['node_id']] = [
				'executions' => (int)$row['executions'],
				'failed' => (int)($row['failed'] ?? 0),
				'averageMs' => $average,
			];
		}

		$result->closeCursor();

		return $aggregates;
	}//end aggregatesByNode()

	/**
	 * The last moment a step of each completed run of the window finished.
	 *
	 * A run whose steps retention already pruned is absent, so the caller
	 * counts it as duration unknown rather than averaging it as zero.
	 *
	 * @param string   $flowId       The flow.
	 * @param string   $organisation Count only this organisation's runs.
	 * @param DateTime $from         Runs created from this moment.
	 * @param DateTime $to           Runs created up to this moment.
	 *
	 * @return array<string, DateTime> Last finish keyed by run uuid.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 */
	public function lastFinishedByRun(string $flowId, string $organisation, DateTime $from, DateTime $to): array {

		$qb = $this->db->getQueryBuilder();
		$qb->select('run_uuid')
			->selectAlias($qb->func()->max('finished'), 'last_finished')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)))
			->andWhere(
				$qb->expr()->in(
					'run_uuid',
					$qb->createFunction(
						$this->windowRunsSql(qb: $qb, scope: [$flowId, $organisation], between: [$from, $to], status: FlowRun::STATUS_COMPLETED)
					)
				)
			)
			->groupBy('run_uuid');

		$finished = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$moment = $this->storedMoment(value: $row['last_finished'] ?? null);
			if ($moment !== null) {
				$finished[(string)$row['run_uuid']] = $moment;
			}
		}

		$result->closeCursor();

		return $finished;
	}//end lastFinishedByRun()

	/**
	 * The uuids of the window's runs, as subquery SQL bound on the outer query.
	 *
	 * @param IQueryBuilder                   $qb      The outer query, which carries the parameters.
	 * @param array{0: string, 1: string}     $scope   The flow and the organisation.
	 * @param array{0: DateTime, 1: DateTime} $between The window's first and last moment.
	 * @param string|null                     $status  Only runs in this status, or null for every run.
	 *
	 * @return string The subquery.
	 */
	private function windowRunsSql(IQueryBuilder $qb, array $scope, array $between, ?string $status): string {
		$runs = $this->db->getQueryBuilder();
		$runs->select('progressrun.uuid')
			->from(self::RUNS_TABLE, 'progressrun')
			->where($runs->expr()->eq('progressrun.flow_id', $qb->createNamedParameter($scope[0])))
			->andWhere($runs->expr()->eq('progressrun.organisation', $qb->createNamedParameter($scope[1])))
			->andWhere($runs->expr()->gte('progressrun.created', $qb->createNamedParameter($between[0], IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere($runs->expr()->lte('progressrun.created', $qb->createNamedParameter($between[1], IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		if ($status !== null) {
			$runs->andWhere($runs->expr()->eq('progressrun.status', $qb->createNamedParameter($status)));
		}

		return $runs->getSQL();
	}//end windowRunsSql()

	/**
	 * A column name quoted for the connected platform, for use inside a raw function.
	 *
	 * @param string $identifier The column.
	 *
	 * @return string The quoted column.
	 */
	private function quoted(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteIdentifier($identifier);
	}//end quoted()
}//end class

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
}//end class

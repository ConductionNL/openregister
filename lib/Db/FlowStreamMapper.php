<?php

/**
 * Mapper for run streams.
 *
 * `allocateNextSequence()` reuses the conditional-UPDATE shape of
 * SequenceMapper::incrementScope(): the increment IS the reservation, so two
 * writers can never be handed the same position within one stream.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Run streams.
 *
 * @template-extends QBMapper<FlowStream>
 */
class FlowStreamMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_streams', entityClass: FlowStream::class);
	}//end __construct()

	/**
	 * Every stream of a run, in ordinal order.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return array<int, FlowStream> The streams.
	 */
	public function findByRun(string $runUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->orderBy('ordinal_path', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByRun()

	/**
	 * One stream of a run.
	 *
	 * @param string $runUuid The run.
	 * @param string $streamId The stream.
	 *
	 * @return FlowStream|null The stream, or null when absent.
	 */
	public function findByRunAndStream(string $runUuid, string $streamId): ?FlowStream {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->andWhere($qb->expr()->eq('stream_id', $qb->createNamedParameter($streamId)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findByRunAndStream()

	/**
	 * Reserve the next step position within a stream.
	 *
	 * The increment is the reservation (SequenceMapper::incrementScope()'s
	 * shape): the row's `next_sequence` is bumped, and the value BEFORE the
	 * bump is the position handed out. Called inside FlowRunCommit's
	 * transaction, so the position and the step row that uses it commit
	 * together.
	 *
	 * @param string $runUuid The run.
	 * @param string $streamId The stream.
	 *
	 * @return int The reserved position, or 0 when the stream row does not exist.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	public function allocateNextSequence(string $runUuid, string $streamId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('next_sequence', $qb->createFunction('next_sequence + 1'))
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->andWhere($qb->expr()->eq('stream_id', $qb->createNamedParameter($streamId)));

		if ($qb->executeStatement() === 0) {
			return 0;
		}

		$row = $this->findByRunAndStream(runUuid: $runUuid, streamId: $streamId);
		$next = (int)($row?->getNextSequence() ?? 1);

		// `next_sequence` now points at the NEXT hand-out; the one reserved is
		// one below it.
		return max(1, ($next - 1));
	}//end allocateNextSequence()

	/**
	 * Drop every stream of a run — with the run's own deletion.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteByRun(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		return $qb->executeStatement();
	}//end deleteByRun()

	/**
	 * Drop every stream row whose run no longer exists — the retention pass
	 * prunes runs by age, and their streams go with them.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteOrphans(): int {
		$runs = $this->db->getQueryBuilder();
		$runs->select('uuid')->from('openregister_flow_runs');

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->createFunction('run_uuid NOT IN (' . $runs->getSQL() . ')'));

		return $qb->executeStatement();
	}//end deleteOrphans()
}//end class

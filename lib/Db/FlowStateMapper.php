<?php

/**
 * OpenRegister FlowStateMapper.
 *
 * Reads and writes the state a flow keeps between runs. See OR#2216.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Persistence for per-flow state.
 *
 * @template-extends QBMapper<FlowState>
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class FlowStateMapper extends QBMapper implements FlowStateMapperInterface {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_state', entityClass: FlowState::class);

	}//end __construct()

	/**
	 * The state of one flow, or null when it has none yet.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return FlowState|null The stored state, or null.
	 */
	public function findByFlow(string $flowId): ?FlowState {
		if (trim($flowId) === '') {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)))
			->setMaxResults(1);

		return $this->findOneOrNull(query: $qb);

	}//end findByFlow()

	/**
	 * The one state row a query selects, or null when it selects none.
	 *
	 * @param IQueryBuilder $query The query.
	 *
	 * @return FlowState|null The row, or null.
	 */
	private function findOneOrNull(IQueryBuilder $query): ?FlowState {
		try {
			return $this->findEntity(query: $query);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findOneOrNull()

	/**
	 * Write a flow's state, creating the row on first use.
	 *
	 * The insert is attempted FIRST and its unique-constraint violation is
	 * caught, rather than checking for an existing row and then writing. That
	 * ordering is deliberate: check-then-write is a lost update waiting for two
	 * concurrent writers, which is exactly what OR#2212 documented at the object
	 * store. Here the `flow_id` unique index decides, so a loser learns it lost
	 * and retries as an update rather than silently overwriting.
	 *
	 * Caught by REASON rather than by emitting dialect-specific upsert SQL, so
	 * it behaves the same on MySQL and PostgreSQL — the pattern
	 * `NotificationDedupeStateMapper` already uses in this codebase.
	 *
	 * @param string $flowId The flow's uuid.
	 * @param array $state The state to store.
	 *
	 * @return FlowState The stored state.
	 *
	 * @throws DbException When the write fails for any reason other than a
	 *                     concurrent insert of the same flow.
	 */
	public function put(string $flowId, array $state): FlowState {
		$entity = new FlowState();
		$entity->setFlowId($flowId);
		$entity->setState($state);
		$entity->setUpdated(new DateTime());

		try {
			return $this->insert(entity: $entity);
		} catch (DbException $e) {
			return $this->updateAfterLostInsert(lost: $e, entity: $entity);
		}

	}//end put()

	/**
	 * Recover from an insert that failed, by updating the row that won.
	 *
	 * Only a unique-constraint violation is recoverable: another writer
	 * created this flow's state between our insert and theirs. If that row
	 * vanished between the violation and the re-read — the only way this
	 * happens is a concurrent delete — the failure is rethrown rather than a
	 * row invented.
	 *
	 * @param DbException $lost   Why the insert failed.
	 * @param FlowState   $entity The state that failed to insert.
	 *
	 * @return FlowState The updated state.
	 *
	 * @throws DbException When the failure is not a concurrent insert, or the
	 *                     winning row vanished before it could be re-read.
	 */
	private function updateAfterLostInsert(DbException $lost, FlowState $entity): FlowState {
		if ($lost->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			throw $lost;
		}

		$existing = $this->findByFlow(flowId: $entity->getFlowId());
		if ($existing === null) {
			throw $lost;
		}

		$existing->setState($entity->getState());
		$existing->setUpdated(new DateTime());

		return $this->update(entity: $existing);
	}//end updateAfterLostInsert()

	/**
	 * Drop a flow's state.
	 *
	 * Used when a flow is deleted, so its bookkeeping does not outlive it and
	 * get inherited by a future flow that reuses the uuid.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return void
	 */
	public function deleteByFlow(string $flowId): void {
		$existing = $this->findByFlow(flowId: $flowId);
		if ($existing === null) {
			return;
		}

		$this->delete(entity: $existing);

	}//end deleteByFlow()
}//end class

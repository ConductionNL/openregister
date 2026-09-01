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
class FlowStateMapper extends QBMapper {
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

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findByFlow()

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
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			// Another writer created this flow's state between our insert and
			// theirs. Update the row that won.
			$existing = $this->findByFlow(flowId: $flowId);
			if ($existing === null) {
				// Vanished between the violation and the re-read — the only way
				// this happens is a concurrent delete, so rethrow rather than
				// invent a row.
				throw $e;
			}

			$existing->setState($state);
			$existing->setUpdated(new DateTime());

			return $this->update(entity: $existing);
		}//end try

	}//end put()

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

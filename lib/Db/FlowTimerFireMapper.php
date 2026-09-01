<?php

/**
 * The rung dedup ledger: insert and read ONLY.
 *
 * `claim()` inserts `(timer_uuid, rung_key)` and reports whether the insert
 * won; the unique index `or_flowtimfire_uq` decides, so two concurrent
 * sweeps cannot both conclude a rung is unfired (design D-7). There is no
 * update and no delete path: the ledger is the evidence that a rung fired.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use LogicException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Mapper for `openregister_flow_timer_fires`.
 *
 * @template-extends QBMapper<FlowTimerFire>
 */
class FlowTimerFireMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_timer_fires', entityClass: FlowTimerFire::class);

	}//end __construct()

	/**
	 * Insert, stamping `created`.
	 *
	 * @param Entity $entity The fire row.
	 *
	 * @return FlowTimerFire The inserted row.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function insert(Entity $entity): FlowTimerFire {
		if ($entity instanceof FlowTimerFire === false) {
			throw new InvalidArgumentException('FlowTimerFireMapper persists FlowTimerFire entities only.');
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Updates are refused: the ledger is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return FlowTimerFire Never returns.
	 *
	 * @throws \LogicException Always.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function update(Entity $entity): FlowTimerFire {
		throw new LogicException('The rung-fire ledger is append-only; row ' . (string)$entity->getId() . ' cannot be updated.');
	}//end update()

	/**
	 * Deletes are refused: the ledger is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return FlowTimerFire Never returns.
	 *
	 * @throws \LogicException Always.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function delete(Entity $entity): FlowTimerFire {
		throw new LogicException('The rung-fire ledger is append-only; row ' . (string)$entity->getId() . ' cannot be deleted.');
	}//end delete()

	/**
	 * CLAIM a rung: insert its ledger row, or report that another pass owns it.
	 *
	 * @param FlowTimerFire $fire The row to insert; its (timer_uuid, rung_key) is the claim.
	 *
	 * @return FlowTimerFire|null The inserted row, or null when the unique index refused it.
	 *
	 * @throws DbException On any failure other than the unique-constraint violation.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function claim(FlowTimerFire $fire): ?FlowTimerFire {
		try {
			return $this->insert(entity: $fire);
		} catch (DbException $failure) {
			if ($failure->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Another pass owns this rung. Not an error: at-most-once is the contract.
				return null;
			}

			throw $failure;
		}
	}//end claim()

	/**
	 * Every fire row of a timer, oldest first.
	 *
	 * @param string $timerUuid The timer uuid.
	 *
	 * @return array<int, FlowTimerFire> The fire rows.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function findByTimer(string $timerUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('timer_uuid', $qb->createNamedParameter($timerUuid)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByTimer()
}//end class

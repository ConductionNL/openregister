<?php

/**
 * The timer history: insert and read ONLY.
 *
 * No update and no delete path exists, and a timer's cancellation does not
 * cascade here — the suspension or extension of a legal term is a decision
 * that has to stay evidenced with actor, moment, reason and basis.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for `openregister_flow_timer_events`.
 *
 * @template-extends QBMapper<FlowTimerEvent>
 */
class FlowTimerEventMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_timer_events', entityClass: FlowTimerEvent::class);

	}//end __construct()

	/**
	 * Insert, stamping `created`.
	 *
	 * @param Entity $entity The event row.
	 *
	 * @return FlowTimerEvent The inserted row.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function insert(Entity $entity): FlowTimerEvent {
		if ($entity instanceof FlowTimerEvent === false) {
			throw new InvalidArgumentException('FlowTimerEventMapper persists FlowTimerEvent entities only.');
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Updates are refused: the history is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return FlowTimerEvent Never returns.
	 *
	 * @throws \LogicException Always.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function update(Entity $entity): FlowTimerEvent {
		throw new \LogicException('The timer history is append-only; event ' . (string)$entity->getId() . ' cannot be updated.');
	}//end update()

	/**
	 * Deletes are refused: the history is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return FlowTimerEvent Never returns.
	 *
	 * @throws \LogicException Always.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function delete(Entity $entity): FlowTimerEvent {
		throw new \LogicException('The timer history is append-only; event ' . (string)$entity->getId() . ' cannot be deleted.');
	}//end delete()

	/**
	 * The history of a timer, oldest first.
	 *
	 * @param string $timerUuid The timer uuid.
	 *
	 * @return array<int, FlowTimerEvent> The events.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function findByTimer(string $timerUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('timer_uuid', $qb->createNamedParameter($timerUuid)))
			->orderBy('created', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByTimer()
}//end class

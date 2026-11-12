<?php

/**
 * Mapper for QueuedNotification entities.
 *
 * Backs the durable "hold this notification, then flush it later" queue
 * used by both the quiet-hours delivery-window gate and the fixed-time
 * digest schedule (see
 * openspec/changes/notification-delivery-windows/design.md, "One durable
 * queue, two reasons to be in it"). `findAll()` returns every row because
 * `NotificationQueueFlushJob` re-evaluates each row's condition LIVE on
 * every tick rather than trusting a precomputed `due_at_hint` (avoids DST
 * bugs — see design.md "Live re-evaluation at flush time").
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
 * @spec openspec/changes/notification-delivery-windows/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class QueuedNotificationMapper.
 *
 * @method QueuedNotification insert(Entity $entity)
 * @method QueuedNotification update(Entity $entity)
 * @method QueuedNotification delete(Entity $entity)
 *
 * @template-extends QBMapper<QueuedNotification>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class QueuedNotificationMapper extends QBMapper implements QueuedNotificationMapperInterface {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_notification_queue',
			entityClass: QueuedNotification::class
		);

	}//end __construct()

	/**
	 * Every currently-queued row, oldest first.
	 *
	 * The flush job re-evaluates each row's holding condition live rather
	 * than filtering "due" rows in SQL — see class docblock.
	 *
	 * @return array<int, QueuedNotification>
	 */
	public function findAll(): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->orderBy('created_at', 'ASC');

			return $this->findEntities(query: $qb);
		} catch (\Throwable $e) {
			// A read failure must not break the flush job's tick — treat
			// it as "nothing queued this pass" and let the next tick retry.
			return [];
		}

	}//end findAll()

	/**
	 * Look up a single row by id (used by tests / operator tooling).
	 *
	 * @param int $id Row id.
	 *
	 * @return QueuedNotification|null
	 */
	public function findById(int $id): ?QueuedNotification {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($this->idIs(qb: $qb, id: $id))
				->setMaxResults(1);

			return $this->findEntity(query: $qb);
		} catch (\Throwable $e) {
			// A missing row and a failed read both answer "not found".
			return null;
		}

	}//end findById()

	/**
	 * Delete a queued row by id (called once its contents have been
	 * flushed into a delivered notification).
	 *
	 * @param int $id Row id.
	 *
	 * @return void
	 */
	public function deleteById(int $id): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($this->idIs(qb: $qb, id: $id));
			$qb->executeStatement();
		} catch (\Exception $e) {
			// Best-effort: worst case a stale row survives until the next
			// sweep re-attempts the delete after a redundant re-flush.
		}

	}//end deleteById()

	/**
	 * Condition matching the row with the given id.
	 *
	 * @param IQueryBuilder $qb The query being built.
	 * @param int           $id Row id.
	 *
	 * @return string
	 */
	private function idIs(IQueryBuilder $qb, int $id): string {
		return $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT));

	}//end idIs()
}//end class

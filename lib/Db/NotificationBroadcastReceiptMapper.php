<?php

/**
 * NotificationBroadcastReceiptMapper.
 *
 * Records that one person has seen one broadcast, once.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 *
 * @template-extends QBMapper<NotificationBroadcastReceipt>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class NotificationBroadcastReceiptMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_notif_bc_receipt',
			entityClass: NotificationBroadcastReceipt::class
		);

	}//end __construct()

	/**
	 * Record that a user has seen a broadcast. Idempotent.
	 *
	 * Idempotent because the unique index says so, not because the caller is
	 * careful: a double-click races, and the loser of that race must not turn
	 * into an error the user sees or a second row claiming two sightings.
	 *
	 * @param string $broadcastUuid The broadcast.
	 * @param string $userId The reader.
	 * @param DateTime|null $seenAt The moment, defaulting to now.
	 *
	 * @return NotificationBroadcastReceipt The row, existing or new.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function acknowledge(
		string $broadcastUuid,
		string $userId,
		?DateTime $seenAt = null,
	): NotificationBroadcastReceipt {
		$existing = $this->find(broadcastUuid: $broadcastUuid, userId: $userId);
		if ($existing !== null) {
			return $existing;
		}

		$entity = new NotificationBroadcastReceipt();
		$entity->setBroadcastUuid($broadcastUuid);
		$entity->setUserId($userId);
		$entity->setSeenAt(($seenAt ?? new DateTime()));

		try {
			return $this->insert(entity: $entity);
		} catch (\Throwable $e) {
			// Lost the race against a concurrent acknowledge; the winner's row
			// is the answer, and it says exactly what this one would have.
			$existing = $this->find(broadcastUuid: $broadcastUuid, userId: $userId);
			if ($existing !== null) {
				return $existing;
			}

			throw $e;
		}

	}//end acknowledge()

	/**
	 * Find one receipt.
	 *
	 * @param string $broadcastUuid The broadcast.
	 * @param string $userId The reader.
	 *
	 * @return NotificationBroadcastReceipt|null The row, or null when there is none.
	 */
	public function find(string $broadcastUuid, string $userId): ?NotificationBroadcastReceipt {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('broadcast_uuid', $qb->createNamedParameter($broadcastUuid)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end find()

	/**
	 * How many people have seen one broadcast.
	 *
	 * @param string $broadcastUuid The broadcast.
	 *
	 * @return int The count.
	 */
	public function countFor(string $broadcastUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('broadcast_uuid', $qb->createNamedParameter($broadcastUuid)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;

	}//end countFor()
}//end class

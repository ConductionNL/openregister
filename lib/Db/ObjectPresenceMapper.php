<?php

/**
 * Who has an object open, and for how much longer we believe it.
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
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTimeInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Presence rows.
 *
 * @template-extends QBMapper<ObjectPresence>
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */
class ObjectPresenceMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_presence',
			entityClass: ObjectPresence::class
		);
	}//end __construct()

	/**
	 * The row one reader has on one object, or null.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object.
	 *
	 * @return ObjectPresence|null The row.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function findOne(string $userId, string $objectUuid): ?ObjectPresence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findOne()

	/**
	 * The readers still present on one object.
	 *
	 * 🔴 THE CUTOFF IS APPLIED IN SQL, NOT IN THE CALLER. A list that returned
	 * the stale rows for somebody else to filter would be one more place the
	 * expiry window is written down, and the two would drift the first time one
	 * of them was tuned.
	 *
	 * @param string            $objectUuid The object.
	 * @param DateTimeInterface $notBefore  The oldest heartbeat still believed.
	 *
	 * @return array<int, ObjectPresence> The rows, oldest arrival first.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function findPresent(string $objectUuid, DateTimeInterface $notBefore): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere(
				$qb->expr()->gte(
					'last_seen',
					$qb->createNamedParameter($notBefore, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->orderBy('arrived_at', 'ASC');

		return $this->findEntities($qb);
	}//end findPresent()

	/**
	 * Remove one reader from one object.
	 *
	 * @param string $userId     The reader.
	 * @param string $objectUuid The object.
	 *
	 * @return boolean True when a row was removed.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function removeOne(string $userId, string $objectUuid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return ($qb->executeStatement() > 0);
	}//end removeOne()

	/**
	 * The readers whose last heartbeat is older than the window, about to go.
	 *
	 * Read BEFORE they are pruned, because a departure has to be pushed and a
	 * row already deleted cannot say who to push about.
	 *
	 * @param DateTimeInterface $before The cutoff.
	 * @param int               $limit  How many to collect.
	 *
	 * @return array<int, ObjectPresence> The stale rows.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function findStale(DateTimeInterface $before, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->lt(
					'last_seen',
					$qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}//end findStale()

	/**
	 * Delete every row whose last heartbeat is older than the window.
	 *
	 * @param DateTimeInterface $before The cutoff.
	 *
	 * @return int How many rows went.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function pruneStale(DateTimeInterface $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->lt(
					'last_seen',
					$qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			);

		return $qb->executeStatement();
	}//end pruneStale()
}//end class

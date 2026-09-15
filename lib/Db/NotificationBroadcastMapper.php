<?php

/**
 * NotificationBroadcastMapper.
 *
 * Reads and writes the administered broadcasts, and answers the one question
 * every page asks: what is showing for this person right now, that they have
 * not already seen.
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
 * @template-extends QBMapper<NotificationBroadcast>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class NotificationBroadcastMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_notif_broadcast',
			entityClass: NotificationBroadcast::class
		);

	}//end __construct()

	/**
	 * Record one broadcast.
	 *
	 * @param string $uuid Stable identifier.
	 * @param string $subject The one-line message.
	 * @param string|null $body The longer text, when there is one.
	 * @param string $sender The uid of whoever sent it.
	 * @param DateTime $startsAt When it starts showing.
	 * @param DateTime $endsAt When it stops showing.
	 *
	 * @return NotificationBroadcast The persisted row.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One row's columns, passed as they are stored.
	 */
	public function record(
		string $uuid,
		string $subject,
		?string $body,
		string $sender,
		DateTime $startsAt,
		DateTime $endsAt,
	): NotificationBroadcast {
		$entity = new NotificationBroadcast();
		$entity->setUuid($uuid);
		$entity->setSubject($subject);
		$entity->setBody($body);
		$entity->setSender($sender);
		$entity->setStartsAt($startsAt);
		$entity->setEndsAt($endsAt);
		$entity->setCreated(new DateTime());

		return $this->insert(entity: $entity);

	}//end record()

	/**
	 * Find one broadcast by its uuid.
	 *
	 * @param string $uuid The broadcast's uuid.
	 *
	 * @return NotificationBroadcast|null The row, or null when there is none.
	 */
	public function findByUuid(string $uuid): ?NotificationBroadcast {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findByUuid()

	/**
	 * List broadcasts, newest first.
	 *
	 * @param int|null $limit Result limit.
	 * @param int|null $offset Result offset.
	 *
	 * @return array<int, NotificationBroadcast> The rows.
	 */
	public function findAllBroadcasts(?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);

	}//end findAllBroadcasts()

	/**
	 * The broadcasts showing at a moment that this user has not yet seen.
	 *
	 * The receipt exclusion is a correlated `NOT EXISTS` rather than a filter
	 * in PHP, so "once" holds however many broadcasts are on the instance and
	 * the page never pulls rows it will discard.
	 *
	 * @param string $userId The reader.
	 * @param DateTime|null $asOf The moment to judge the period against, defaulting to now.
	 *
	 * @return array<int, NotificationBroadcast> The rows, oldest first.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function findUnseenFor(string $userId, ?DateTime $asOf = null): array {
		$moment = ($asOf ?? new DateTime());

		$qb = $this->db->getQueryBuilder();
		$qb->select('b.*')
			->from($this->getTableName(), 'b')
			->where($qb->expr()->lte('b.starts_at', $qb->createNamedParameter($moment, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->gte('b.ends_at', $qb->createNamedParameter($moment, IQueryBuilder::PARAM_DATE)))
			->andWhere(
				$qb->createFunction(
					'NOT EXISTS (SELECT 1 FROM `*PREFIX*openregister_notif_bc_receipt` r '
					. 'WHERE r.`broadcast_uuid` = b.`uuid` AND r.`user_id` = '
					. $qb->createNamedParameter($userId) . ')'
				)
			)
			->orderBy('b.starts_at', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findUnseenFor()

	/**
	 * Delete one broadcast by uuid.
	 *
	 * @param string $uuid The broadcast's uuid.
	 *
	 * @return boolean True when a row was removed.
	 */
	public function deleteByUuid(string $uuid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return ($qb->executeStatement() > 0);

	}//end deleteByUuid()
}//end class

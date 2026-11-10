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
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use OCA\OpenRegister\Db\ListQuery;
use DateTime;
use OCA\OpenRegister\Notifications\Service\Notification\OutgoingBroadcast;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class NotificationBroadcastMapper.
 *
 * @method NotificationBroadcast insert(Entity $entity)
 * @method NotificationBroadcast update(Entity $entity)
 * @method NotificationBroadcast delete(Entity $entity)
 *
 * @template-extends QBMapper<NotificationBroadcast>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class NotificationBroadcastMapper extends QBMapper {
	use NotificationQueryTrait;

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
	 * @param string            $uuid     Stable identifier.
	 * @param OutgoingBroadcast $outgoing The message, whoever sent it and when it shows.
	 *
	 * @return NotificationBroadcast The persisted row.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function record(string $uuid, OutgoingBroadcast $outgoing): NotificationBroadcast {
		$entity = new NotificationBroadcast();
		$entity->setUuid($uuid);
		$entity->setSubject($outgoing->message->subject);
		$entity->setBody($outgoing->message->body);
		$entity->setSender($outgoing->sender);
		$entity->setStartsAt($outgoing->window->startsAt);
		$entity->setEndsAt($outgoing->window->endsAt);
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
		$found = $this->findFirstOrNull(qb: $this->selectWhereEquals(columns: ['uuid' => $uuid]));
		if ($found instanceof NotificationBroadcast) {
			return $found;
		}

		return null;
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
		$this->page(qb: $qb, query: new ListQuery(limit: ($limit ?? ListQuery::UNBOUNDED), offset: $offset));

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function findUnseenFor(string $userId, ?DateTime $asOf = null): array {
		$moment = ($asOf ?? new DateTime());

		$qb = $this->db->getQueryBuilder();
		$qb->select('b.*')
			->from($this->getTableName(), 'b')
			->where($qb->expr()->lte('b.starts_at', $qb->createNamedParameter($moment, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere($qb->expr()->gte('b.ends_at', $qb->createNamedParameter($moment, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
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
		return ($this->deleteWhereEquals(columns: ['uuid' => $uuid])->executeStatement() > 0);
	}//end deleteByUuid()
}//end class

<?php

/**
 * Mapper for Watcher rows.
 *
 * Four read shapes, and each one exists because a different caller asks a
 * different question:
 *
 *  - `findOne()`      — is THIS user watching THIS object (the marker, the
 *                       idempotent subscribe, the self-unsubscribe).
 *  - `findByObject()` — who watches this object (the watcher list, and the
 *                       notification dispatcher's recipient resolution).
 *  - `notifyMapForUser()` — what does this user follow, and with which
 *                       notification setting (the `@self.watching` and
 *                       `@self.watchNotify` markers, loaded once per request
 *                       rather than once per rendered row). The `_watching`
 *                       lens does not read it: it is an `EXISTS` on this table.
 *  - `countsByObject()` — how many watchers per object, for `@self.watcherCount`.
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
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;
use OCA\OpenRegister\Db\DeleteByObjectTrait;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class WatcherMapper.
 *
 * @method Watcher insert(Entity $entity)
 * @method Watcher update(Entity $entity)
 * @method Watcher delete(Entity $entity)
 *
 * @template-extends QBMapper<Watcher>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class WatcherMapper extends QBMapper implements WatcherMapperInterface {
	use NotificationQueryTrait;
	use DeleteByObjectTrait;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: self::TABLE,
			entityClass: Watcher::class
		);

	}//end __construct()

	/**
	 * The subscription of one user to one object, when it exists.
	 *
	 * @param string $userId The subscribing user's uid.
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return Watcher|null The row, or null when the user does not watch the object.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function findOne(string $userId, string $objectUuid): ?Watcher {
		$found = $this->findFirstOrNull(qb: $this->selectWhereEquals(columns: ['user_id' => $userId, 'object_uuid' => $objectUuid]));
		if ($found instanceof Watcher) {
			return $found;
		}

		return null;
	}//end findOne()

	/**
	 * Every subscription on one object, oldest first.
	 *
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return array<int, Watcher> The rows.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function findByObject(string $objectUuid): array {
		$qb = $this->selectWhereEquals(columns: ['object_uuid' => $objectUuid]);
		$qb->orderBy('created', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByObject()

	/**
	 * The subscriptions on one object that notify, oldest first.
	 *
	 * The notification dispatcher's question: who hears about a change. A
	 * quiet follow (`notify` false) still follows and is still listed to
	 * editors, but is not told. A null `notify`, a row from before the column
	 * existed, counts as on.
	 *
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return array<int, Watcher> The rows.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	public function findNotifyingByObject(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('notify', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
					$qb->expr()->isNull('notify')
				)
			)
			->orderBy('created', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findNotifyingByObject()

	/**
	 * What one user follows, with each follow's notification setting.
	 *
	 * @param string $userId The following user's uid.
	 *
	 * @return array<string, bool> Object uuid to whether that follow notifies.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function notifyMapForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid', 'notify')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		// Null is a row from before the column: on. Anything else is the
		// database's boolean spelling (1, '1', true, 't'), read strictly.
		return $this->mapByObject(
			qb: $qb,
			value: static fn (array $row): bool => (($row['notify'] ?? null) === null || in_array($row['notify'], [true, 1, '1', 't', 'true'], true) === true)
		);
	}//end notifyMapForUser()

	/**
	 * Watcher counts per object, in one grouped pass.
	 *
	 * @param int $limit How many object rows to load at most.
	 *
	 * @return array<string, int> Object uuid to watcher count.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function countsByObject(int $limit = self::COUNT_MAP_LIMIT): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->selectAlias($qb->createFunction('COUNT(*)'), 'watcher_count')
			->from($this->getTableName())
			->groupBy('object_uuid')
			->setMaxResults($limit);

		return $this->mapByObject(qb: $qb, value: static fn (array $row): int => (int)($row['watcher_count'] ?? 0));
	}//end countsByObject()


	/**
	 * Run a query and key one value per row by its object, skipping rows without one.
	 *
	 * @param IQueryBuilder                          $qb    The query; it selects `object_uuid`.
	 * @param callable(array<string, mixed>): mixed $value Reads the value from a row.
	 *
	 * @return array<string, mixed> Object uuid => value.
	 */
	private function mapByObject(IQueryBuilder $qb, callable $value): array {
		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = (string)($row['object_uuid'] ?? '');
			if ($uuid !== '') {
				$map[$uuid] = $value($row);
			}
		}

		$result->closeCursor();

		return $map;

	}//end mapByObject()

	/**
	 * How many users watch one object.
	 *
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return integer The number of watchers.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function countForObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'watcher_count')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['watcher_count'] ?? 0);

	}//end countForObject()

	/**
	 * Subscribe a user to an object, idempotently, and set its notify switch.
	 *
	 * The unique index on (user_id, object_uuid) is the authority: a second
	 * subscribe returns the row that is already there rather than writing a
	 * duplicate. `$notify` null means "leave it": a new row notifies, an
	 * existing row keeps its setting. A bool sets it either way.
	 *
	 * @param Watcher $watcher The unsaved follow: the subscribing user's uid, the watched
	 *                         object's uuid, and its register and schema as the caller
	 *                         addressed it. It is inserted only when no row exists yet.
	 * @param bool|null $notify The notification switch, or null to keep it.
	 *
	 * @return Watcher The stored row.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function subscribe(Watcher $watcher, ?bool $notify = null): Watcher {
		$existing = $this->findOne(userId: $watcher->getUserId(), objectUuid: $watcher->getObjectUuid());
		if ($existing !== null) {
			return $this->applyNotify(watcher: $existing, notify: $notify);
		}

		$watcher->setNotify($notify ?? true);
		$watcher->setCreated(new DateTime());

		return $this->insertOrReadWinner(watcher: $watcher, notify: $notify);
	}//end subscribe()


	/**
	 * Insert the watcher, or settle on the row a concurrent subscribe wrote.
	 *
	 * A concurrent subscribe lost the race on the unique index. The caller
	 * asked for "this user watches this object", which is now true, so the
	 * winner is read back rather than failing the request.
	 *
	 * @param Watcher   $watcher The watcher to insert.
	 * @param bool|null $notify  The notify flag asked for, or null to leave it.
	 *
	 * @return Watcher The stored watcher.
	 *
	 * @throws \Throwable When the insert fails and no winning row exists.
	 */
	private function insertOrReadWinner(Watcher $watcher, ?bool $notify): Watcher {
		try {
			return $this->insert(entity: $watcher);
		} catch (\Throwable $e) {
			$winner = $this->findOne(userId: (string)$watcher->getUserId(), objectUuid: (string)$watcher->getObjectUuid());
			if ($winner !== null) {
				return $this->applyNotify(watcher: $winner, notify: $notify);
			}

			throw $e;
		}

	}//end insertOrReadWinner()

	/**
	 * Set an existing row's notify switch when the caller named one.
	 *
	 * @param Watcher $watcher The stored row.
	 * @param bool|null $notify The asked-for setting, or null to keep it.
	 *
	 * @return Watcher The row as it now stands.
	 */
	private function applyNotify(Watcher $watcher, ?bool $notify): Watcher {
		if ($notify === null || $watcher->notifies() === $notify) {
			return $watcher;
		}

		$watcher->setNotify($notify);

		return $this->update(entity: $watcher);

	}//end subscribe()

	/**
	 * Remove one user's subscription to one object.
	 *
	 * @param string $userId The subscribing user's uid.
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return boolean True when a row was removed, false when there was none.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function unsubscribe(string $userId, string $objectUuid): bool {
		return ($this->deleteWhereEquals(columns: ['user_id' => $userId, 'object_uuid' => $objectUuid])->executeStatement() > 0);
	}//end unsubscribe()
}//end class

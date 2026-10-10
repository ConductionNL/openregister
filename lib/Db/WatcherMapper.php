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

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
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
class WatcherMapper extends QBMapper {

	/**
	 * The follow table, named once for the query lenses that join on it.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_watchers';

	/**
	 * How many rows the grouped watcher-count query will load in one pass.
	 *
	 * `@self.watcherCount` is rendered per row, so a count query per rendered
	 * object would be an N+1. Instead the counts are loaded once per request,
	 * grouped. The cap keeps that one query bounded: past it the caller falls
	 * back to counting a single object at a time, which is slower but never
	 * unbounded in memory.
	 *
	 * @var integer
	 */
	public const COUNT_MAP_LIMIT = 5000;

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
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

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
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('created', 'ASC');

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
	 * @spec openspec/changes/merge-follow-and-favourites/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
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
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function notifyMapForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid', 'notify')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = (string)($row['object_uuid'] ?? '');
			if ($uuid === '') {
				continue;
			}

			// Null is a row from before the column: on. Anything else is the
			// database's boolean spelling (1, '1', true, 't'), read strictly.
			$notify = $row['notify'] ?? null;
			$map[$uuid] = ($notify === null || in_array($notify, [true, 1, '1', 't', 'true'], true) === true);
		}

		$result->closeCursor();

		return $map;

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

		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = (string)($row['object_uuid'] ?? '');
			if ($uuid !== '') {
				$counts[$uuid] = (int)($row['watcher_count'] ?? 0);
			}
		}

		$result->closeCursor();

		return $counts;

	}//end countsByObject()

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
	 * @param string $userId The subscribing user's uid.
	 * @param string $objectUuid The watched object's uuid.
	 * @param string|null $register The object's register, as the caller addressed it.
	 * @param string|null $schema The object's schema, as the caller addressed it.
	 * @param bool|null $notify The notification switch, or null to keep it.
	 *
	 * @return Watcher The stored row.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function subscribe(
		string $userId,
		string $objectUuid,
		?string $register = null,
		?string $schema = null,
		?bool $notify = null,
	): Watcher {
		$existing = $this->findOne(userId: $userId, objectUuid: $objectUuid);
		if ($existing !== null) {
			return $this->applyNotify(watcher: $existing, notify: $notify);
		}

		$watcher = new Watcher();
		$watcher->setUserId($userId);
		$watcher->setObjectUuid($objectUuid);
		$watcher->setRegister($register);
		$watcher->setSchema($schema);
		$watcher->setNotify($notify ?? true);
		$watcher->setCreated(new DateTime());

		try {
			return $this->insert(entity: $watcher);
		} catch (\Throwable $e) {
			// A concurrent subscribe lost the race on the unique index. The
			// caller asked for "this user watches this object", which is now
			// true, so read the winner back rather than failing the request.
			$winner = $this->findOne(userId: $userId, objectUuid: $objectUuid);
			if ($winner !== null) {
				return $this->applyNotify(watcher: $winner, notify: $notify);
			}

			throw $e;
		}

	}//end subscribe()

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
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return ($qb->executeStatement() > 0);

	}//end unsubscribe()

	/**
	 * Remove every subscription on one object.
	 *
	 * Called when the object is deleted, so a re-created uuid never inherits
	 * somebody else's audience.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();

	}//end deleteByObject()
}//end class

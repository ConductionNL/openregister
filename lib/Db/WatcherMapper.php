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
 *  - `uuidsForUser()` — what does this user watch (the `_watching=true` lens
 *                       and the `@self.watching` marker, loaded once per
 *                       request rather than once per rendered row).
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
 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
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
			tableName: 'openregister_watchers',
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
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
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
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
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
	 * The uuids one user watches, optionally narrowed to a register and schema.
	 *
	 * @param string $userId The subscribing user's uid.
	 * @param string|null $register Narrow to this register, as stored on the row.
	 * @param string|null $schema Narrow to this schema, as stored on the row.
	 *
	 * @return array<int, string> The watched object uuids.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
	 */
	public function uuidsForUser(string $userId, ?string $register = null, ?string $schema = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		if ($register !== null && $register !== '') {
			$qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($register)));
		}

		if ($schema !== null && $schema !== '') {
			$qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schema)));
		}

		$result = $qb->executeQuery();
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = (string)($row['object_uuid'] ?? '');
			if ($uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		$result->closeCursor();

		return array_values(array_unique($uuids));

	}//end uuidsForUser()

	/**
	 * Watcher counts per object, in one grouped pass.
	 *
	 * @param int $limit How many object rows to load at most.
	 *
	 * @return array<string, int> Object uuid to watcher count.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
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
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
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
	 * Subscribe a user to an object, idempotently.
	 *
	 * The unique index on (user_id, object_uuid) is the authority: a second
	 * subscribe returns the row that is already there rather than writing a
	 * duplicate.
	 *
	 * @param string $userId The subscribing user's uid.
	 * @param string $objectUuid The watched object's uuid.
	 * @param string|null $register The object's register, as the caller addressed it.
	 * @param string|null $schema The object's schema, as the caller addressed it.
	 *
	 * @return Watcher The stored row.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
	 */
	public function subscribe(string $userId, string $objectUuid, ?string $register = null, ?string $schema = null): Watcher {
		$existing = $this->findOne(userId: $userId, objectUuid: $objectUuid);
		if ($existing !== null) {
			return $existing;
		}

		$watcher = new Watcher();
		$watcher->setUserId($userId);
		$watcher->setObjectUuid($objectUuid);
		$watcher->setRegister($register);
		$watcher->setSchema($schema);
		$watcher->setCreated(new DateTime());

		try {
			return $this->insert(entity: $watcher);
		} catch (\Throwable $e) {
			// A concurrent subscribe lost the race on the unique index. The
			// caller asked for "this user watches this object", which is now
			// true, so read the winner back rather than failing the request.
			$winner = $this->findOne(userId: $userId, objectUuid: $objectUuid);
			if ($winner !== null) {
				return $winner;
			}

			throw $e;
		}

	}//end subscribe()

	/**
	 * Remove one user's subscription to one object.
	 *
	 * @param string $userId The subscribing user's uid.
	 * @param string $objectUuid The watched object's uuid.
	 *
	 * @return boolean True when a row was removed, false when there was none.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
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
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-deleting-an-object-removes-its-watchers
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();

	}//end deleteByObject()
}//end class

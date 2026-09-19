<?php

/**
 * Mapper for ObjectReadState rows.
 *
 * Six read and write shapes, one per question a caller actually asks:
 *
 *  - `findOne()`        — has THIS user seen THIS object (the marker, the
 *                         sub-resource seen map, the manual mark-unread).
 *  - `uuidsForUser()`   — what has this user seen, loaded once per request so
 *                         `@self.unread` on a page of rows costs one query.
 *  - `markSeen()`       — the idempotent upsert the open path writes.
 *  - `markUnread()`     — remove this user's row, which IS unread.
 *  - `invalidate()`     — a substantive change: remove everyone's row except
 *                         the actor's, whose is refreshed instead.
 *  - `deleteByObject()` — the object is gone, so nothing about it is unread.
 *
 * WHY UNREAD IS AN ABSENT ROW. The alternative is a timestamp on the row
 * compared against the object's last substantive change, which would need a
 * column on the per-schema object table and a comparison the filter cannot push
 * into SQL cheaply. Deleting on the write instead makes the filter a single
 * correlated `NOT EXISTS`, which is why paging and counts can be correct.
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
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
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
 * Class ObjectReadStateMapper.
 *
 * @method ObjectReadState insert(Entity $entity)
 * @method ObjectReadState update(Entity $entity)
 * @method ObjectReadState delete(Entity $entity)
 *
 * @template-extends QBMapper<ObjectReadState>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class ObjectReadStateMapper extends QBMapper {

	/**
	 * The table this mapper owns, named once so the query lens can reference it.
	 *
	 * `SearchQueryHandler` never touches this table; `MagicSearchHandler` builds
	 * a correlated subquery against it and needs the name without taking a
	 * dependency on the mapper's construction.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_object_read_state';

	/**
	 * How many seen uuids the per-request memo will load at most.
	 *
	 * `@self.unread` is rendered on every row of every list, so the seen set is
	 * loaded once and answered from memory. The cap keeps that one query
	 * bounded; past it the caller falls back to asking per object, which is
	 * slower but never unbounded in memory.
	 *
	 * @var integer
	 */
	public const SEEN_SET_LIMIT = 20000;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: self::TABLE,
			entityClass: ObjectReadState::class
		);

	}//end __construct()

	/**
	 * One user's read state for one object, when it exists.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return ObjectReadState|null The row, or null when the user has not seen the object.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function findOne(string $userId, string $objectUuid): ?ObjectReadState {
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
	 * The uuids one user has already seen, optionally narrowed to a scope.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string|null $register Narrow to this register, as stored on the row.
	 * @param string|null $schema Narrow to this schema, as stored on the row.
	 * @param int $limit How many uuids to load at most.
	 *
	 * @return array<int, string> The seen object uuids.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function uuidsForUser(
		string $userId,
		?string $register = null,
		?string $schema = null,
		int $limit = self::SEEN_SET_LIMIT
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults($limit);

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
	 * Record that a user has now seen an object, idempotently.
	 *
	 * The unique index on (user_id, object_uuid) is the authority: opening an
	 * object twice refreshes one row rather than writing a second.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string $objectUuid The object's uuid.
	 * @param string|null $register The object's register, as the caller addressed it.
	 * @param string|null $schema The object's schema, as the caller addressed it.
	 * @param array<string, string>|null $subSeen Sub-resource seen moments to merge in.
	 * @param DateTime|null $seenAt The moment to record, defaulting to now.
	 *
	 * @return ObjectReadState The stored row.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markSeen(
		string $userId,
		string $objectUuid,
		?string $register = null,
		?string $schema = null,
		?array $subSeen = null,
		?DateTime $seenAt = null
	): ObjectReadState {
		$now = ($seenAt ?? new DateTime());
		$existing = $this->findOne(userId: $userId, objectUuid: $objectUuid);

		if ($existing !== null) {
			$existing->setLastSeenAt($now);
			if ($register !== null && $register !== '') {
				$existing->setRegister($register);
			}

			if ($schema !== null && $schema !== '') {
				$existing->setSchema($schema);
			}

			if ($subSeen !== null) {
				// Merge rather than replace: opening the files tab says nothing
				// about when the messages tab was last read.
				$existing->setSubSeen(array_merge(($existing->getSubSeen() ?? []), $subSeen));
			}

			return $this->update(entity: $existing);
		}//end if

		$row = new ObjectReadState();
		$row->setUserId($userId);
		$row->setObjectUuid($objectUuid);
		$row->setRegister($register);
		$row->setSchema($schema);
		$row->setLastSeenAt($now);
		$row->setSubSeen(($subSeen ?? []));
		$row->setCreated($now);

		try {
			return $this->insert(entity: $row);
		} catch (\Throwable $e) {
			// A concurrent open lost the race on the unique index. The caller
			// asked for "this user has seen this object", which is now true, so
			// read the winner back rather than failing the request.
			$winner = $this->findOne(userId: $userId, objectUuid: $objectUuid);
			if ($winner !== null) {
				return $winner;
			}

			throw $e;
		}//end try

	}//end markSeen()

	/**
	 * Put one object back to unread for one user.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when a row was removed, false when there was none.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markUnread(string $userId, string $objectUuid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return ($qb->executeStatement() > 0);

	}//end markUnread()

	/**
	 * A substantive change: everyone but the actor has something new to see.
	 *
	 * The actor is excluded rather than deleted and re-inserted, so the write
	 * is one statement and the person who made the change never sees their own
	 * edit as unread.
	 *
	 * @param string $objectUuid The changed object's uuid.
	 * @param string|null $exceptUserId The actor, whose row survives. Null for a
	 *                                  system write, where every row goes.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function invalidate(string $objectUuid, ?string $exceptUserId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		if ($exceptUserId !== null && $exceptUserId !== '') {
			$qb->andWhere($qb->expr()->neq('user_id', $qb->createNamedParameter($exceptUserId)));
		}

		return $qb->executeStatement();

	}//end invalidate()

	/**
	 * Remove every read state for one object.
	 *
	 * Called when the object is purged, so a re-created uuid never inherits
	 * somebody else's idea of what they have already seen.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();

	}//end deleteByObject()

	/**
	 * How many of a given uuid set one user has NOT seen.
	 *
	 * One grouped query, so a caller holding a page of uuids never asks per row.
	 *
	 * @param string $userId The reading user's uid.
	 * @param array<int, string> $objectUuids The uuids to check.
	 *
	 * @return array<int, string> The uuids that are unread for this user.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function unreadAmong(string $userId, array $objectUuids): array {
		if ($objectUuids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere(
				$qb->expr()->in(
					'object_uuid',
					$qb->createNamedParameter($objectUuids, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		$result = $qb->executeQuery();
		$seen = [];
		while (($row = $result->fetch()) !== false) {
			$seen[(string)($row['object_uuid'] ?? '')] = true;
		}

		$result->closeCursor();

		return array_values(array_filter($objectUuids, static fn (string $uuid): bool => isset($seen[$uuid]) === false));

	}//end unreadAmong()
}//end class

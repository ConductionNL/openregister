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
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\IResult;
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
class ObjectReadStateMapper extends QBMapper implements ObjectReadStateMapperInterface {
	use DeleteByObjectTrait;

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
	 * @spec openspec/specs/object-read-state/spec.md
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
	 * @spec openspec/specs/object-read-state/spec.md
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

		$this->narrowToScope(qb: $qb, register: $register, schema: $schema);

		$result = $qb->executeQuery();
		$uuids = $this->seenUuids(result: $result);
		$result->closeCursor();

		return array_values(array_unique($uuids));

	}//end uuidsForUser()

	/**
	 * Narrow a seen-set read to a register and/or a schema, as stored on the row.
	 *
	 * @param IQueryBuilder $qb       The query being built.
	 * @param string|null   $register The register; null or empty means no restriction.
	 * @param string|null   $schema   The schema; null or empty means no restriction.
	 *
	 * @return void
	 */
	private function narrowToScope(IQueryBuilder $qb, string|null $register, string|null $schema): void {
		if ($register !== null && $register !== '') {
			$qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($register)));
		}

		if ($schema !== null && $schema !== '') {
			$qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schema)));
		}

	}//end narrowToScope()

	/**
	 * The non-empty object uuids of a seen-set result, in row order.
	 *
	 * @param IResult $result The executed query.
	 *
	 * @return array<int, string> The uuids, duplicates included.
	 */
	private function seenUuids(IResult $result): array {
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = (string)($row['object_uuid'] ?? '');
			if ($uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		return $uuids;

	}//end seenUuids()

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
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
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

		// The row this sighting writes when the user has no row yet. Building
		// it touches no storage; it is only inserted when findOne() finds none.
		$row = new ObjectReadState();
		$row->setUserId($userId);
		$row->setObjectUuid($objectUuid);
		$row->setRegister($register);
		$row->setSchema($schema);
		$row->setLastSeenAt($now);
		$row->setSubSeen(($subSeen ?? []));
		$row->setCreated($now);

		$existing = $this->findOne(userId: $userId, objectUuid: $objectUuid);

		return $this->refreshSeen(existing: $existing, sighting: $row, subSeen: $subSeen)
			?? $this->insertSeen(row: $row);

	}//end markSeen()

	/**
	 * Refresh an existing row from a sighting; null when there is no row.
	 *
	 * The register and schema are only overwritten by a non-empty value, and
	 * sub-resource moments are merged rather than replaced: opening the files
	 * tab says nothing about when the messages tab was last read.
	 *
	 * @param ObjectReadState|null       $existing The stored row, if any.
	 * @param ObjectReadState            $sighting The row the sighting would insert.
	 * @param array<string, string>|null $subSeen  Sub-resource seen moments to merge in.
	 *
	 * @return ObjectReadState|null The updated row, or null when there was none.
	 */
	private function refreshSeen(ObjectReadState|null $existing, ObjectReadState $sighting, array|null $subSeen): ObjectReadState|null {
		if ($existing === null) {
			return null;
		}

		$existing->setLastSeenAt($sighting->getLastSeenAt());
		$this->refreshScope(existing: $existing, sighting: $sighting);

		if ($subSeen !== null) {
			$existing->setSubSeen(array_merge(($existing->getSubSeen() ?? []), $subSeen));
		}

		return $this->update(entity: $existing);

	}//end refreshSeen()

	/**
	 * Overwrite a stored row's register and schema with the sighting's non-empty ones.
	 *
	 * @param ObjectReadState $existing The stored row.
	 * @param ObjectReadState $sighting The row the sighting would insert.
	 *
	 * @return void
	 */
	private function refreshScope(ObjectReadState $existing, ObjectReadState $sighting): void {
		$register = $sighting->getRegister();
		if ($register !== null && $register !== '') {
			$existing->setRegister($register);
		}

		$schema = $sighting->getSchema();
		if ($schema !== null && $schema !== '') {
			$existing->setSchema($schema);
		}

	}//end refreshScope()

	/**
	 * Insert a new row, reading the winner back when a concurrent open won the race.
	 *
	 * The caller asked for "this user has seen this object", which is true once
	 * either insert landed, so a lost race is answered with the winner's row
	 * rather than failing the request. Without a winner the failure is rethrown.
	 *
	 * @param ObjectReadState $row The row to insert.
	 *
	 * @return ObjectReadState The stored row.
	 *
	 * @throws \Throwable The insert failure, when no winner row exists.
	 */
	private function insertSeen(ObjectReadState $row): ObjectReadState {
		try {
			return $this->insert(entity: $row);
		} catch (\Throwable $e) {
			$winner = $this->findOne(userId: $row->getUserId(), objectUuid: $row->getObjectUuid());
			if ($winner !== null) {
				return $winner;
			}

			throw $e;
		}//end try

	}//end insertSeen()

	/**
	 * Put one object back to unread for one user.
	 *
	 * @param string $userId The reading user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when a row was removed, false when there was none.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
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
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
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
	 * How many of a given uuid set one user has NOT seen.
	 *
	 * One grouped query, so a caller holding a page of uuids never asks per row.
	 *
	 * @param string $userId The reading user's uid.
	 * @param array<int, string> $objectUuids The uuids to check.
	 *
	 * @return array<int, string> The uuids that are unread for this user.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
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

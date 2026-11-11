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
use OCA\OpenRegister\Db\ObjectReadState\ReadStateScope;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
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
	 * @param string         $userId The reading user's uid.
	 * @param ListQuery|null $query  How many uuids to load at most (the limit) and the `register` and `schema`
	 *                               filters to narrow to, as stored on the row; null for SEEN_SET_LIMIT unnarrowed.
	 *
	 * @return array<int, string> The seen object uuids.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function uuidsForUser(string $userId, ?ListQuery $query = null): array {
		$query ??= new ListQuery(limit: self::SEEN_SET_LIMIT);
		$qb      = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults($query->limit);
		(new ReadStateScope(register: ($query->filters['register'] ?? null), schema: ($query->filters['schema'] ?? null)))->applyTo(qb: $qb);

		return $this->distinctSeenUuids(qb: $qb);

	}//end uuidsForUser()

	/**
	 * Run a seen-set read and return its distinct uuids, in first-seen order.
	 *
	 * @param IQueryBuilder $qb The seen-set query.
	 *
	 * @return array<int, string> The seen object uuids.
	 */
	private function distinctSeenUuids(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$uuids = $this->seenUuids(result: $result);
		$result->closeCursor();

		return array_values(array_unique($uuids));

	}//end distinctSeenUuids()

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
	 * The sighting is the row this write inserts when the user has no row yet:
	 * its user, object, the register and schema as the caller addressed them,
	 * the sub-resource seen moments to merge in (null for none) and the moment
	 * seen (null for now). Building it touches no storage; it is only inserted
	 * when findOne() finds no row.
	 *
	 * @param ObjectReadState $sighting The sighting, as described above.
	 *
	 * @return ObjectReadState The stored row.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function markSeen(ObjectReadState $sighting): ObjectReadState {
		$subSeen = $sighting->getSubSeen();
		$now = ($sighting->getLastSeenAt() ?? new DateTime());

		$sighting->setLastSeenAt($now);

		$existing = $this->findOne(userId: (string)$sighting->getUserId(), objectUuid: (string)$sighting->getObjectUuid());
		if ($existing !== null) {
			return $this->refreshSeen(existing: $existing, sighting: $sighting);
		}

		$sighting->setSubSeen(($subSeen ?? []));
		$sighting->setCreated($now);
		return $this->insertSeen(row: $sighting);

	}//end markSeen()

	/**
	 * Refresh an existing row from a sighting.
	 *
	 * The register and schema are only overwritten by a non-empty value, and
	 * sub-resource moments are merged rather than replaced: opening the files
	 * tab says nothing about when the messages tab was last read. The
	 * sighting's sub-resource moments (null for none) are merged in.
	 *
	 * @param ObjectReadState $existing The stored row.
	 * @param ObjectReadState $sighting The row the sighting would insert.
	 *
	 * @return ObjectReadState The updated row.
	 */
	private function refreshSeen(ObjectReadState $existing, ObjectReadState $sighting): ObjectReadState {
		$existing->setLastSeenAt($sighting->getLastSeenAt());
		$this->refreshScope(existing: $existing, sighting: $sighting);

		$subSeen = $sighting->getSubSeen();
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

		$seen = $this->seenAmong(userId: $userId, objectUuids: $objectUuids);

		return array_values(array_filter($objectUuids, static fn (string $uuid): bool => isset($seen[$uuid]) === false));

	}//end unreadAmong()

	/**
	 * The objects among some that a user has a read row for, as a set keyed by uuid.
	 *
	 * @param string             $userId      The user.
	 * @param array<int, string> $objectUuids The objects.
	 *
	 * @return array<string, true> The seen uuids.
	 */
	private function seenAmong(string $userId, array $objectUuids): array {
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

		return $this->seenUuidsOf(result: $qb->executeQuery());
	}//end seenAmong()

	/**
	 * The uuids a read-state result holds, as a set; the result is closed after.
	 *
	 * @param IResult $result The result.
	 *
	 * @return array<string, true> The seen uuids.
	 */
	private function seenUuidsOf(IResult $result): array {
		$seen = [];
		while (($row = $result->fetch()) !== false) {
			$seen[(string)($row['object_uuid'] ?? '')] = true;
		}

		$result->closeCursor();

		return $seen;
	}//end seenUuidsOf()
}//end class

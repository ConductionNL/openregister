<?php

/**
 * ObjectFavouriteMapper: the rows behind a star.
 *
 * Three questions and nothing else: has this user starred this object, what
 * has this user starred, and remove every star on an object that is gone.
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
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class ObjectFavouriteMapper.
 *
 * @method ObjectFavourite insert(Entity $entity)
 * @method ObjectFavourite update(Entity $entity)
 * @method ObjectFavourite delete(Entity $entity)
 *
 * @template-extends QBMapper<ObjectFavourite>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class ObjectFavouriteMapper extends QBMapper {

	/**
	 * The table this mapper owns, named once so the query lens can reference it.
	 *
	 * `MagicSearchHandler` builds a correlated subquery against this table and
	 * needs the name without taking a dependency on the mapper's construction,
	 * which is the same reason `ObjectReadStateMapper` publishes its own.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_favourites';

	/**
	 * How many starred uuids the per-request memo will load at most.
	 *
	 * `@self.favourite` is rendered on every row of every list, so the starred
	 * set is loaded once and answered from memory. The cap keeps that one query
	 * bounded.
	 *
	 * @var integer
	 */
	public const STARRED_SET_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: self::TABLE,
			entityClass: ObjectFavourite::class
		);

	}//end __construct()

	/**
	 * One user's star on one object, when it exists.
	 *
	 * @param string $userId The starring user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return ObjectFavourite|null The row, or null when the object is not starred.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function findOne(string $userId, string $objectUuid): ?ObjectFavourite {
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
	 * Star an object for a user, idempotently.
	 *
	 * The unique index on (user_id, object_uuid) is the authority: starring
	 * twice keeps one row rather than writing a second.
	 *
	 * @param string $userId The starring user's uid.
	 * @param string $objectUuid The object's uuid.
	 * @param string|null $register The object's register, as the caller addressed it.
	 * @param string|null $schema The object's schema, as the caller addressed it.
	 * @param DateTime|null $moment The moment to record, defaulting to now.
	 *
	 * @return ObjectFavourite The stored row.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function star(
		string $userId,
		string $objectUuid,
		?string $register = null,
		?string $schema = null,
		?DateTime $moment = null
	): ObjectFavourite {
		$existing = $this->findOne(userId: $userId, objectUuid: $objectUuid);
		if ($existing !== null) {
			return $existing;
		}

		$row = new ObjectFavourite();
		$row->setUserId($userId);
		$row->setObjectUuid($objectUuid);
		$row->setRegister($register);
		$row->setSchema($schema);
		$row->setCreated(($moment ?? new DateTime()));

		try {
			return $this->insert(entity: $row);
		} catch (\Throwable $e) {
			// A concurrent star lost the race on the unique index. The caller
			// asked for "this object is starred by this user", which is now
			// true, so read the winner back rather than failing the request.
			$winner = $this->findOne(userId: $userId, objectUuid: $objectUuid);
			if ($winner !== null) {
				return $winner;
			}

			throw $e;
		}//end try

	}//end star()

	/**
	 * Remove one user's star from one object.
	 *
	 * @param string $userId The starring user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return boolean True when a row was removed, false when there was none.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function unstar(string $userId, string $objectUuid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement() > 0;

	}//end unstar()

	/**
	 * The uuids one user has starred, optionally narrowed to a scope.
	 *
	 * @param string $userId The starring user's uid.
	 * @param string|null $register Narrow to this register, as stored on the row.
	 * @param string|null $schema Narrow to this schema, as stored on the row.
	 * @param int $limit How many uuids to load at most.
	 *
	 * @return array<int, string> The starred object uuids.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
	 */
	public function uuidsForUser(
		string $userId,
		?string $register = null,
		?string $schema = null,
		int $limit = self::STARRED_SET_LIMIT
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
	 * Remove every star on one object.
	 *
	 * Called when the object itself is gone: a star pointing at nothing is not
	 * a favourite, it is a row that will never be looked at again.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return integer How many stars were removed.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();

	}//end deleteByObject()
}//end class

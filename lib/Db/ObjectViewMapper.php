<?php

/**
 * ObjectViewMapper: the rows behind "recently opened".
 *
 * One row per (user, object), refreshed rather than appended, and trimmed to
 * the most recent hundred per user. The trim runs on the write that would make
 * the hundred-and-first row, so the table stays bounded without a cron job to
 * forget to schedule.
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
 * Class ObjectViewMapper.
 *
 * @method ObjectView insert(Entity $entity)
 * @method ObjectView update(Entity $entity)
 * @method ObjectView delete(Entity $entity)
 *
 * @template-extends QBMapper<ObjectView>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class ObjectViewMapper extends QBMapper {

	/**
	 * The table this mapper owns, named once so the query lens can reference it.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_object_views';

	/**
	 * How many objects one user's view history keeps.
	 *
	 * A hundred DISTINCT objects, because the unique index means a row is an
	 * object and not an opening. Past it the oldest are dropped.
	 *
	 * @var integer
	 */
	public const HISTORY_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, ObjectView::class);

	}//end __construct()

	/**
	 * One user's view of one object, when it exists.
	 *
	 * @param string $userId The viewing user's uid.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return ObjectView|null The row, or null when the user has never opened it.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	public function findOne(string $userId, string $objectUuid): ?ObjectView {
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
	 * Write or refresh the row that says this user opened this object.
	 *
	 * The THROTTLE is the caller's decision, not this method's: pass the row
	 * only when it should be written. What lives here is the uniqueness and the
	 * trim, both of which are properties of the table rather than of a policy.
	 *
	 * @param string $userId The viewing user's uid.
	 * @param string $objectUuid The object's uuid.
	 * @param string|null $register The object's register, as the caller addressed it.
	 * @param string|null $schema The object's schema, as the caller addressed it.
	 * @param DateTime|null $at The moment to record, defaulting to now.
	 *
	 * @return ObjectView The stored row.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	public function record(
		string $userId,
		string $objectUuid,
		?string $register = null,
		?string $schema = null,
		?DateTime $at = null
	): ObjectView {
		$now = ($at ?? new DateTime());
		$existing = $this->findOne(userId: $userId, objectUuid: $objectUuid);

		if ($existing !== null) {
			$existing->setViewedAt($now);
			if ($register !== null && $register !== '') {
				$existing->setRegister($register);
			}

			if ($schema !== null && $schema !== '') {
				$existing->setSchema($schema);
			}

			return $this->update(entity: $existing);
		}

		$row = new ObjectView();
		$row->setUserId($userId);
		$row->setObjectUuid($objectUuid);
		$row->setRegister($register);
		$row->setSchema($schema);
		$row->setViewedAt($now);
		$row->setCreated($now);

		try {
			$stored = $this->insert(entity: $row);
		} catch (\Throwable $e) {
			// A concurrent open lost the race on the unique index.
			$winner = $this->findOne(userId: $userId, objectUuid: $objectUuid);
			if ($winner === null) {
				throw $e;
			}

			$stored = $winner;
		}//end try

		// Only a NEW object can push this user past the cap, so the trim runs
		// on this path and not on the refresh above.
		$this->trim(userId: $userId);

		return $stored;

	}//end record()

	/**
	 * The uuids one user has opened, most recently first.
	 *
	 * @param string $userId The viewing user's uid.
	 * @param int $limit How many uuids to load at most.
	 *
	 * @return array<int, string> The viewed object uuids, newest first.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
	 */
	public function uuidsForUser(string $userId, int $limit = self::HISTORY_LIMIT): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('viewed_at', 'DESC')
			->setMaxResults($limit);

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
	 * Drop everything past the most recent hundred for one user.
	 *
	 * Expressed as "delete what is older than the hundredth newest" rather than
	 * a `DELETE ... ORDER BY LIMIT`, which MariaDB accepts and Postgres does
	 * not. Reading the cut-off first costs one small query and works on both.
	 *
	 * Rows sharing the cut-off moment go together, so a burst of opens in the
	 * same second can leave slightly fewer than `$keep`. That is deliberate:
	 * the cap is a bound on the history, not a promise of its exact length,
	 * and the alternative is a tie-break column the ordering does not need.
	 *
	 * @param string $userId The viewing user's uid.
	 * @param int $keep How many rows to keep.
	 *
	 * @return integer How many rows were dropped.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	public function trim(string $userId, int $keep = self::HISTORY_LIMIT): int {
		$cutoff = $this->db->getQueryBuilder();
		$cutoff->select('viewed_at')
			->from($this->getTableName())
			->where($cutoff->expr()->eq('user_id', $cutoff->createNamedParameter($userId)))
			->orderBy('viewed_at', 'DESC')
			->setFirstResult($keep)
			->setMaxResults(1);

		$result = $cutoff->executeQuery();
		$oldest = $result->fetchOne();
		$result->closeCursor();

		if ($oldest === false || $oldest === null) {
			// Fewer than `$keep` rows, so there is nothing past the cut-off.
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('viewed_at', $qb->createNamedParameter($oldest)));

		return $qb->executeStatement();

	}//end trim()

	/**
	 * Remove every view of one object.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return integer How many views were removed.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();

	}//end deleteByObject()
}//end class

<?php

/**
 * SchemaFollowerMapper — who follows a schema's breaking changes.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Follow, unfollow and list followers; one row per schema and user.
 *
 * @template-extends QBMapper<SchemaFollower>
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */
class SchemaFollowerMapper extends QBMapper {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_schema_followers';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: SchemaFollower::class);
	}//end __construct()

	/**
	 * Whether a user follows a schema.
	 *
	 * @param int    $schemaId The schema.
	 * @param string $uid      The user.
	 *
	 * @return bool True when they follow it.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	public function isFollowing(int $schemaId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$found = $result->fetchOne();
		$result->closeCursor();

		return $found !== false && $found !== null;
	}//end isFollowing()

	/**
	 * Follow a schema; following it again changes nothing.
	 *
	 * @param int    $schemaId The schema.
	 * @param string $uid      The user.
	 *
	 * @return bool True when the user was not following it before.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	public function follow(int $schemaId, string $uid): bool {
		if ($this->isFollowing(schemaId: $schemaId, uid: $uid) === true) {
			return false;
		}

		$follower = new SchemaFollower();
		$follower->setSchemaId($schemaId);
		$follower->setUid($uid);
		$follower->setCreated(new DateTime());
		$this->insert(entity: $follower);

		return true;
	}//end follow()

	/**
	 * Stop following a schema.
	 *
	 * @param int    $schemaId The schema.
	 * @param string $uid      The user.
	 *
	 * @return bool True when the user was following it.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	public function unfollow(int $schemaId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		return $qb->executeStatement() > 0;
	}//end unfollow()

	/**
	 * The users following a schema, oldest first.
	 *
	 * @param int $schemaId The schema.
	 * @param int $limit    The most users to return.
	 *
	 * @return array<int, string> The user ids.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public function findUids(int $schemaId, int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')
			->from(self::TABLE)
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$uids = [];
		while (($row = $result->fetch()) !== false) {
			$uids[] = (string)$row['uid'];
		}

		$result->closeCursor();

		return $uids;
	}//end findUids()

	/**
	 * How many users follow a schema.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function countFollowers(int $schemaId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from(self::TABLE)
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}//end countFollowers()
}//end class

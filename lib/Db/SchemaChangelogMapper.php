<?php

/**
 * Mapper for SchemaChangelog entities.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class SchemaChangelogMapper
 *
 * @template-extends QBMapper<SchemaChangelog>
 */
class SchemaChangelogMapper extends QBMapper implements SchemaChangelogMapperInterface {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_schema_changelog', entityClass: SchemaChangelog::class);

	}//end __construct()

	/**
	 * Find a changelog entry by id.
	 *
	 * @param int $id The entry id.
	 *
	 * @return SchemaChangelog The entry.
	 *
	 * @throws DoesNotExistException When not found.
	 */
	public function find(int $id): SchemaChangelog {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find changelog entries for a schema, newest first.
	 *
	 * @param int $schemaId The schema id.
	 * @param ListQuery $query The page: its size and offset, unbounded for every row.
	 *
	 * @return SchemaChangelog[] The entries.
	 */
	public function findBySchema(int $schemaId, ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');

		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findBySchema()

	/**
	 * Create a changelog entry from an array of values.
	 *
	 * @param array<string, mixed> $data The entry values.
	 *
	 * @return SchemaChangelog The persisted entry.
	 */
	public function createFromArray(array $data): SchemaChangelog {
		$entry = new SchemaChangelog();
		$entry->hydrate($data);

		if ($entry->getCreated() === null) {
			$entry->setCreated(new DateTime());
		}

		return $this->insert(entity: $entry);
	}//end createFromArray()

	/**
	 * The newest breaking entry of a schema created since a moment, or null.
	 *
	 * @param int      $schemaId The schema.
	 * @param DateTime $since    The earliest creation.
	 *
	 * @return SchemaChangelog|null The entry.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-clients-see-a-breaking-change-on-the-schemas-responses
	 */
	public function findLatestBreakingSince(int $schemaId, DateTime $since): ?SchemaChangelog {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('classification', $qb->createNamedParameter('breaking')))
			->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		$entries = $this->findEntities(query: $qb);
		if ($entries === []) {
			return null;
		}

		return $entries[0];
	}//end findLatestBreakingSince()

	/**
	 * Record on an entry how many the breaking-change notice told, and when.
	 *
	 * @param SchemaChangelog $entry The entry.
	 * @param int             $count How many were told.
	 *
	 * @return SchemaChangelog The updated entry.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public function recordNotice(SchemaChangelog $entry, int $count): SchemaChangelog {
		$entry->setNoticeSentTo($count);
		$entry->setNoticeSentAt(new DateTime());

		return $this->update(entity: $entry);
	}//end recordNotice()
}//end class

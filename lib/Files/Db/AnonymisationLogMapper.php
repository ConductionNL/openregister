<?php

/**
 * OpenRegister AnonymisationLog Mapper
 *
 * Mapper for {@see AnonymisationLog} entities.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\QueryPage;

/**
 * AnonymisationLogMapper handles database operations for AnonymisationLog entities.
 *
 * @method AnonymisationLog insert(Entity $entity)
 * @method AnonymisationLog update(Entity $entity)
 * @method AnonymisationLog delete(Entity $entity)
 * @method AnonymisationLog find(int $id)
 * @method AnonymisationLog findEntity(IQueryBuilder $query)
 * @method list<AnonymisationLog> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<AnonymisationLog>
 */
class AnonymisationLogMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_anonymisation_log', entityClass: AnonymisationLog::class);
	}//end __construct()

	/**
	 * Find a log row by id.
	 *
	 * @param int $id Log entry id
	 *
	 * @return AnonymisationLog
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): AnonymisationLog {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find log rows by Nextcloud file id.
	 *
	 * @param int $fileId The NC file id
	 * @param ListQuery|null $query The page (limit and offset); null reads every row
	 *
	 * @return AnonymisationLog[]
	 *
	 * @psalm-return list<AnonymisationLog>
	 */
	public function findByFileId(int $fileId, ?ListQuery $query = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created', 'DESC');

		(new QueryPage(limit: $query?->limitOrNull(), offset: $query?->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findByFileId()

	/**
	 * Find log rows by source object UUID.
	 *
	 * @param string $objectUuid Object UUID
	 * @param ListQuery|null $query The page (limit and offset); null reads every row
	 *
	 * @return AnonymisationLog[]
	 *
	 * @psalm-return list<AnonymisationLog>
	 */
	public function findByObjectUuid(string $objectUuid, ?ListQuery $query = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('created', 'DESC');

		(new QueryPage(limit: $query?->limitOrNull(), offset: $query?->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Insert a new anonymisation log row.
	 *
	 * @param Entity $entity The AnonymisationLog entity
	 *
	 * @return AnonymisationLog
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function insert(Entity $entity): Entity {
		if ($entity instanceof AnonymisationLog) {
			$entity->setCreated(new DateTime());
		}

		return parent::insert(entity: $entity);
	}//end insert()
}//end class

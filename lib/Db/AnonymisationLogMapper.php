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
 * @spec openspec/changes/office-document-sanitization/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

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
class AnonymisationLogMapper extends QBMapper
{

    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
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
    public function find(int $id): AnonymisationLog
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find log rows by Nextcloud file id.
     *
     * @param int      $fileId The NC file id
     * @param int|null $limit  Result limit
     * @param int|null $offset Result offset
     *
     * @return AnonymisationLog[]
     *
     * @psalm-return list<AnonymisationLog>
     */
    public function findByFileId(int $fileId, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findByFileId()

    /**
     * Find log rows by source object UUID.
     *
     * @param string   $objectUuid Object UUID
     * @param int|null $limit      Result limit
     * @param int|null $offset     Result offset
     *
     * @return AnonymisationLog[]
     *
     * @psalm-return list<AnonymisationLog>
     */
    public function findByObjectUuid(string $objectUuid, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Get the most recent successful anonymisation log row for a file.
     *
     * @param int $fileId The NC file id
     *
     * @return AnonymisationLog|null The latest success row or null when absent.
     */
    public function findLatestSuccessForFile(int $fileId): ?AnonymisationLog
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(AnonymisationLog::STATUS_SUCCESS)))
            ->orderBy('created', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }//end findLatestSuccessForFile()

    /**
     * Insert a new anonymisation log row.
     *
     * @param Entity $entity The AnonymisationLog entity
     *
     * @return AnonymisationLog
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof AnonymisationLog) {
            $entity->setCreated(new DateTime());
        }

        return parent::insert(entity: $entity);
    }//end insert()
}//end class

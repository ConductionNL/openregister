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
class SchemaChangelogMapper extends QBMapper
{


    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
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
    public function find(int $id): SchemaChangelog
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);

    }//end find()


    /**
     * Find changelog entries for a schema, newest first.
     *
     * @param int      $schemaId The schema id.
     * @param int|null $limit    Optional page size.
     * @param int|null $offset   Optional page offset.
     *
     * @return SchemaChangelog[] The entries.
     */
    public function findBySchema(int $schemaId, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);

    }//end findBySchema()


    /**
     * Create a changelog entry from an array of values.
     *
     * @param array<string, mixed> $data The entry values.
     *
     * @return SchemaChangelog The persisted entry.
     */
    public function createFromArray(array $data): SchemaChangelog
    {
        $entry = new SchemaChangelog();
        $entry->hydrate($data);

        if ($entry->getCreated() === null) {
            $entry->setCreated(new DateTime());
        }

        return $this->insert(entity: $entry);

    }//end createFromArray()


}//end class

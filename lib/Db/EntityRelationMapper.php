<?php

/**
 * Mapper for entity relations.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class EntityRelationMapper
 *
 * @method EntityRelation insert(Entity $entity)
 * @method EntityRelation update(Entity $entity)
 * @method EntityRelation insertOrUpdate(Entity $entity)
 * @method EntityRelation delete(Entity $entity)
 * @method EntityRelation find(int|string $id)
 * @method EntityRelation findEntity(IQueryBuilder $query)
 * @method EntityRelation[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<EntityRelation> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<EntityRelation>
 */
class EntityRelationMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_entity_relations', entityClass: EntityRelation::class);
    }//end __construct()

    /**
     * Find entity relations by file ID.
     *
     * @param int $fileId The file ID.
     *
     * @return EntityRelation[] Array of entity relations.
     */
    public function findByFileId(int $fileId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities(query: $qb);
    }//end findByFileId()

    /**
     * Find entity relations by entity ID.
     *
     * @param int $entityId The entity ID.
     *
     * @return EntityRelation[] Array of entity relations.
     */
    public function findByEntityId(int $entityId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities(query: $qb);
    }//end findByEntityId()

    /**
     * Find entity relations with entity details by file ID.
     *
     * Returns entity relations joined with entity data for anonymization.
     *
     * @param int $fileId The file ID.
     *
     * @return array Array of entity data with type, value, and relation info.
     */
    public function findEntitiesForFile(int $fileId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'r.id as relation_id',
            'r.entity_id',
            'r.position_start',
            'r.position_end',
            'r.confidence',
            'e.type as entity_type',
            'e.value as entity_value',
            'e.category'
        )
            ->from($this->getTableName(), 'r')
            ->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
            ->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('r.position_start', 'ASC');

        $result   = $qb->executeQuery();
        $entities = $result->fetchAll();
        $result->closeCursor();

        return $entities;
    }//end findEntitiesForFile()

    /**
     * Mark entity relations as anonymized.
     *
     * @param int    $fileId          The file ID.
     * @param string $anonymizedValue The placeholder value used.
     *
     * @return int Number of relations updated.
     */
    public function markAsAnonymized(int $fileId, string $anonymizedValue): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('anonymized', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->set('anonymized_value', $qb->createNamedParameter($anonymizedValue))
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }//end markAsAnonymized()
}//end class

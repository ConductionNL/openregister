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

use DateTime;
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

    /**
     * Probe whether a relation already exists at a specific position
     * for a (file, entity) pair.
     *
     * Used by `ManualEntityService` to make manual-entity adds
     * idempotent: re-calling the endpoint for the same value on the
     * same file does NOT create duplicate relation rows. The dedup key
     * is the full (fileId, entityId, chunkId, positionStart, positionEnd)
     * tuple — same positions across different entities (e.g. position
     * 142 has both `"Jan Jansen"` PERSON and `"Jansen"` PERSON entities)
     * are legitimately distinct rows.
     *
     * @param int $fileId        Nextcloud file id.
     * @param int $entityId      Catalogue entity id.
     * @param int $chunkId       Chunk row id the position is relative to.
     * @param int $positionStart Position of the first byte of the match within the chunk.
     * @param int $positionEnd   Position one past the last byte of the match within the chunk.
     *
     * @return bool True when a row with exactly these five values exists.
     */
    public function existsForFileAtPosition(
        int $fileId,
        int $entityId,
        int $chunkId,
        int $positionStart,
        int $positionEnd
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('chunk_id', $qb->createNamedParameter($chunkId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq(
                        'position_start',
                        $qb->createNamedParameter($positionStart, IQueryBuilder::PARAM_INT)
                    ),
                    $qb->expr()->eq(
                        'position_end',
                        $qb->createNamedParameter($positionEnd, IQueryBuilder::PARAM_INT)
                    )
                )
            )
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return ($row !== false);

    }//end existsForFileAtPosition()

    /**
     * Insert multiple relation rows in a single pass.
     *
     * The caller is expected to manage the surrounding transaction
     * (`IDBConnection::beginTransaction()` / `commit()` / `rollBack()`).
     * `ManualEntityService` does this so the entity-create + batch
     * relation insert + audit-trail write all commit atomically.
     *
     * Each input row is an associative array carrying the fields the
     * caller wants to persist. Recognised keys (all optional unless
     * marked):
     *
     *   - `entityId`          (required, int)
     *   - `fileId`            (int|null)
     *   - `chunkId`           (int|null)
     *   - `objectId`          (int|null)
     *   - `emailId`           (int|null)
     *   - `positionStart`     (int)
     *   - `positionEnd`       (int)
     *   - `confidence`        (float)
     *   - `detectionMethod`   (string)
     *   - `context`           (string|null)
     *   - `role`              (string|null)
     *   - `anonymized`        (bool)
     *   - `skipAnonymization` (bool)
     *   - `bases`             (array|null)
     *   - `createdAt`         (DateTime, defaults to now)
     *
     * @param array<int, array<string, mixed>> $rows Rows to insert.
     *
     * @return EntityRelation[] The inserted entities with their generated ids,
     *                          in the same order as the input.
     *
     * @throws \Throwable Any DB error is re-thrown verbatim so the caller's
     *                   transaction can roll back.
     */
    public function insertBatch(array $rows): array
    {
        $inserted = [];
        foreach ($rows as $row) {
            $relation   = $this->buildRelationFromRow(row: $row);
            $inserted[] = $this->insert(entity: $relation);
        }

        return $inserted;

    }//end insertBatch()

    /**
     * Materialise an EntityRelation entity from a raw row array.
     *
     * Pulled out of `insertBatch` so the field-by-field setter mapping
     * lives in one place. Unknown keys are silently ignored; missing
     * required key (`entityId`) trigger an exception via the setter
     * type-hint. The `openregister_entity_relations` table has no uuid
     * column — relations are identified by their auto-increment `id`.
     *
     * @param array<string, mixed> $row Field values keyed by camelCase setter name.
     *
     * @return EntityRelation Populated, ready to insert.
     */
    private function buildRelationFromRow(array $row): EntityRelation
    {
        $relation = new EntityRelation();

        $relation->setEntityId((int) $row['entityId']);

        if (array_key_exists('fileId', $row) === true && $row['fileId'] !== null) {
            $relation->setFileId((int) $row['fileId']);
        }

        if (array_key_exists('chunkId', $row) === true && $row['chunkId'] !== null) {
            $relation->setChunkId((int) $row['chunkId']);
        }

        if (array_key_exists('objectId', $row) === true && $row['objectId'] !== null) {
            $relation->setObjectId((int) $row['objectId']);
        }

        if (array_key_exists('emailId', $row) === true && $row['emailId'] !== null) {
            $relation->setEmailId((int) $row['emailId']);
        }

        if (array_key_exists('positionStart', $row) === true) {
            $relation->setPositionStart((int) $row['positionStart']);
        }

        if (array_key_exists('positionEnd', $row) === true) {
            $relation->setPositionEnd((int) $row['positionEnd']);
        }

        if (array_key_exists('confidence', $row) === true) {
            $relation->setConfidence((float) $row['confidence']);
        }

        if (array_key_exists('detectionMethod', $row) === true && $row['detectionMethod'] !== null) {
            $relation->setDetectionMethod((string) $row['detectionMethod']);
        }

        if (array_key_exists('context', $row) === true) {
            $relation->setContext($row['context'] !== null ? (string) $row['context'] : null);
        }

        if (array_key_exists('role', $row) === true) {
            $relation->setRole($row['role'] !== null ? (string) $row['role'] : null);
        }

        if (array_key_exists('anonymized', $row) === true) {
            $relation->setAnonymized((bool) $row['anonymized']);
        }

        if (array_key_exists('skipAnonymization', $row) === true) {
            $relation->setSkipAnonymization((bool) $row['skipAnonymization']);
        }

        if (array_key_exists('bases', $row) === true) {
            $relation->setBases($row['bases']);
        }

        if (array_key_exists('createdAt', $row) === true && $row['createdAt'] !== null) {
            $relation->setCreatedAt($row['createdAt']);
        } else {
            $relation->setCreatedAt(new DateTime());
        }

        return $relation;

    }//end buildRelationFromRow()
}//end class

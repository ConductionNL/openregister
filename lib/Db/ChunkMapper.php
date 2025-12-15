<?php

/**
 * Mapper for chunk entities.
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
 * Class ChunkMapper
 *
 * @method Chunk insert(Entity $entity)
 * @method Chunk update(Entity $entity)
 * @method Chunk insertOrUpdate(Entity $entity)
 * @method Chunk delete(Entity $entity)
 * @method Chunk find(int|string $id)
 * @method Chunk findEntity(IQueryBuilder $query)
 * @method Chunk[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Chunk> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Chunk>
 */
class ChunkMapper extends QBMapper
{


    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_chunks', Chunk::class);

    }//end __construct()


    /**
     * Find chunks by source reference.
     *
     * @param string $sourceType Source type identifier.
     * @param int    $sourceId   Source identifier.
     *
     * @phpstan-param non-empty-string $sourceType
     *
     * @psalm-param non-empty-string   $sourceType
     *
     * @return Chunk[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Chunk>
     */
    public function findBySource(string $sourceType, int $sourceId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT))
                )
            )
            ->orderBy('chunk_index', 'ASC');

        return $this->findEntities($qb);

    }//end findBySource()


    /**
     * Delete chunks by source reference.
     *
     * @param string $sourceType Source type identifier.
     * @param int    $sourceId   Source identifier.
     *
     * @phpstan-param non-empty-string $sourceType
     * @psalm-param   non-empty-string   $sourceType
     *
     * @return void
     */
    public function deleteBySource(string $sourceType, int $sourceId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT))
                )
            )
            ->executeStatement();

    }//end deleteBySource()


    /**
     * Get the latest updated timestamp for a source's chunks.
     *
     * @param string $sourceType Source type identifier.
     * @param int    $sourceId   Source identifier.
     *
     * @phpstan-param non-empty-string $sourceType
     * @psalm-param   non-empty-string   $sourceType
     *
     * @return int|null Unix timestamp of the latest update or null when unavailable.
     */
    public function getLatestUpdatedTimestamp(string $sourceType, int $sourceId): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('MAX(updated_at)'), 'max_updated_at')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT))
                )
            );

        $result = $qb->executeQuery();
        $value  = $result->fetchOne();
        $result->closeCursor();

        if ($value === false || $value === null) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return null;
        }

        return $timestamp;

    }//end getLatestUpdatedTimestamp()


    /**
     * Count all chunks in the database.
     *
     * @return int Total chunk count
     */
    public function countAll(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName());

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countAll()


    /**
     * Count indexed chunks.
     *
     * Chunks are considered indexed if they have been processed by the search engine.
     *
     * @return int Indexed chunk count
     */
    public function countIndexed(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('indexed_at'));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countIndexed()


    /**
     * Count unindexed chunks.
     *
     * Chunks that have been extracted but not yet indexed in the search engine.
     *
     * @return int Unindexed chunk count
     */
    public function countUnindexed(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->isNull('indexed_at'));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countUnindexed()


    /**
     * Count vectorized chunks.
     *
     * Chunks that have been converted to vector embeddings.
     *
     * @return int Vectorized chunk count
     */
    public function countVectorized(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('vectorized_at'));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countVectorized()


    /**
     * Find unindexed chunks.
     *
     * Retrieves chunks that need to be indexed.
     *
     * @param int|null $limit  Maximum number of chunks to return
     * @param int|null $offset Offset for pagination
     *
     * @return Chunk[] Array of unindexed chunks
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Chunk>
     */
    public function findUnindexed(?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->isNull('indexed_at'))
            ->orderBy('created_at', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);

    }//end findUnindexed()


}//end class

<?php

/**
 * OpenRegister ApprovalChainMapper
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper for ApprovalChain entities.
 *
 * @extends QBMapper<ApprovalChain>
 */
class ApprovalChainMapper extends QBMapper
{
    /**
     * Constructor for ApprovalChainMapper.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_approval_chains',
            entityClass: ApprovalChain::class
        );
    }//end __construct()

    /**
     * Find an approval chain by ID.
     *
     * @param int $id Chain ID
     *
     * @return ApprovalChain
     */
    public function find(int $id): ApprovalChain
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter(value: $id, type: IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find all approval chains.
     *
     * @param int|null $limit  Maximum results
     * @param int|null $offset Offset for pagination
     *
     * @return array<int, ApprovalChain>
     */
    public function findAll(?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('name', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find approval chains by schema ID.
     *
     * @param int $schemaId Schema ID
     *
     * @return array<int, ApprovalChain>
     */
    public function findBySchema(int $schemaId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'schema_id',
                    $qb->createNamedParameter(value: $schemaId, type: IQueryBuilder::PARAM_INT)
                )
            )
            ->orderBy('name', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findBySchema()

    /**
     * Create an approval chain from an array.
     *
     * @param array<string, mixed> $data Chain data
     *
     * @return ApprovalChain
     */
    public function createFromArray(array $data): ApprovalChain
    {
        $chain = new ApprovalChain();
        $chain->hydrate($data);

        if ($chain->getUuid() === null) {
            $chain->setUuid(Uuid::v4()->toRfc4122());
        }

        $now = new DateTime();
        $chain->setCreated($now);
        $chain->setUpdated($now);

        return $this->insert(entity: $chain);
    }//end createFromArray()

    /**
     * Update an approval chain from an array.
     *
     * @param int                  $id   Chain ID
     * @param array<string, mixed> $data Updated data
     *
     * @return ApprovalChain
     */
    public function updateFromArray(int $id, array $data): ApprovalChain
    {
        $chain = $this->find(id: $id);
        $chain->hydrate($data);
        $chain->setUpdated(new DateTime());

        return $this->update(entity: $chain);
    }//end updateFromArray()
}//end class

<?php

/**
 * OpenRegister ApprovalStepMapper
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
 * Mapper for ApprovalStep entities.
 *
 * @extends QBMapper<ApprovalStep>
 */
class ApprovalStepMapper extends QBMapper
{
    /**
     * Constructor for ApprovalStepMapper.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_approval_steps',
            entityClass: ApprovalStep::class
        );
    }//end __construct()

    /**
     * Find an approval step by ID.
     *
     * @param int $id Step ID
     *
     * @return ApprovalStep
     */
    public function find(int $id): ApprovalStep
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
     * Find all steps for a chain and object combination.
     *
     * @param int    $chainId    Chain ID
     * @param string $objectUuid Object UUID
     *
     * @return array<int, ApprovalStep>
     */
    public function findByChainAndObject(int $chainId, string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'chain_id',
                    $qb->createNamedParameter(value: $chainId, type: IQueryBuilder::PARAM_INT)
                )
            )
            ->andWhere(
                $qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid))
            )
            ->orderBy('step_order', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByChainAndObject()

    /**
     * Find all pending steps matching a given role.
     *
     * @param string $role Role (Nextcloud group ID)
     *
     * @return array<int, ApprovalStep>
     */
    public function findPendingByRole(string $role): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter('pending'))
            )
            ->andWhere(
                $qb->expr()->eq('role', $qb->createNamedParameter($role))
            )
            ->orderBy('created', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findPendingByRole()

    /**
     * Find all approval steps for a given object UUID.
     *
     * @param string $objectUuid Object UUID
     *
     * @return array<int, ApprovalStep>
     */
    public function findByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid))
            )
            ->orderBy('step_order', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Find all steps with optional filters.
     *
     * @param array<string, mixed> $filters Filter parameters
     * @param int|null             $limit   Maximum results
     * @param int|null             $offset  Pagination offset
     *
     * @return array<int, ApprovalStep>
     */
    public function findAllFiltered(array $filters=[], ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created', 'ASC');

        if (isset($filters['status']) === true) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
        }

        if (isset($filters['role']) === true) {
            $qb->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($filters['role'])));
        }

        if (isset($filters['chainId']) === true) {
            $qb->andWhere(
                $qb->expr()->eq(
                    'chain_id',
                    $qb->createNamedParameter(value: (int) $filters['chainId'], type: IQueryBuilder::PARAM_INT)
                )
            );
        }

        if (isset($filters['objectUuid']) === true) {
            $qb->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($filters['objectUuid'])));
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findAllFiltered()

    /**
     * Find distinct object UUIDs in a chain with their step progress.
     *
     * @param int $chainId Chain ID
     *
     * @return array<int, ApprovalStep>
     */
    public function findByChain(int $chainId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'chain_id',
                    $qb->createNamedParameter(value: $chainId, type: IQueryBuilder::PARAM_INT)
                )
            )
            ->orderBy('object_uuid', 'ASC')
            ->addOrderBy('step_order', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByChain()

    /**
     * Create an approval step from an array.
     *
     * @param array<string, mixed> $data Step data
     *
     * @return ApprovalStep
     */
    public function createFromArray(array $data): ApprovalStep
    {
        $step = new ApprovalStep();
        $step->hydrate($data);

        if ($step->getUuid() === null) {
            $step->setUuid(Uuid::v4()->toRfc4122());
        }

        if ($step->getCreated() === null) {
            $step->setCreated(new DateTime());
        }

        return $this->insert(entity: $step);
    }//end createFromArray()
}//end class

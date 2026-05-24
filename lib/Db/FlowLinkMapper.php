<?php

/**
 * Mapper for flow link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class FlowLinkMapper
 *
 * @template-extends QBMapper<FlowLink>
 */
class FlowLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_flow_links', entityClass: FlowLink::class);
    }//end __construct()

    /**
     * Find flow links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return FlowLink[] Array of flow links.
     */
    public function findByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Find flow links by operation id.
     *
     * @param int $operationId The operation id.
     *
     * @return FlowLink[] Array of flow links.
     */
    public function findByOperationId(int $operationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('operation_id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByOperationId()

    /**
     * Find a specific flow link by object UUID and operation id.
     *
     * @param string $objectUuid  The object UUID.
     * @param int    $operationId The operation id.
     *
     * @return FlowLink|null The link or null if not found.
     */
    public function findByObjectAndOperation(string $objectUuid, int $operationId): ?FlowLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('operation_id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndOperation()

    /**
     * Delete all flow links for an object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        return $qb->executeStatement();
    }//end deleteByObjectUuid()

    /**
     * Delete a flow link by object UUID + operation id (Tier-2 unlink path).
     *
     * Returns the number of rows actually deleted so callers can
     * distinguish "no such link" (0) from "ok" (>=1).
     *
     * @param string $objectUuid  The object UUID.
     * @param int    $operationId The operation id.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectAndOperation(string $objectUuid, int $operationId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('operation_id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }//end deleteByObjectAndOperation()
}//end class

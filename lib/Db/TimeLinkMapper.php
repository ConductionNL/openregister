<?php

/**
 * Mapper for time link entities.
 *
 * Provides CRUD access to the openregister_time_links table and a method
 * for computing the per-object total used for reconciliation.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class TimeLinkMapper
 *
 * @template-extends QBMapper<TimeLink>
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
 */
class TimeLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_time_links', entityClass: TimeLink::class);
    }//end __construct()

    /**
     * Find all time links for a given object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return TimeLink[]
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function findByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('entry_date', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Find a specific time link by object UUID and backend entry ID.
     *
     * @param string $objectUuid     The object UUID.
     * @param string $backendEntryId The backend entry ID.
     *
     * @return TimeLink|null
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function findByObjectAndBackendEntry(string $objectUuid, string $backendEntryId): ?TimeLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('backend_entry_id', $qb->createNamedParameter($backendEntryId)));

        try {
            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndBackendEntry()

    /**
     * Sum all duration_minutes entries for a given object UUID.
     *
     * Used by the reconcile command and by the service after writes.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Total minutes logged against this object.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function sumDurationByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COALESCE(SUM(duration_minutes), 0)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        $result = $qb->executeQuery()->fetchOne();
        return (int) $result;
    }//end sumDurationByObjectUuid()

    /**
     * Update the denormalized total_minutes for all entries belonging to an object.
     *
     * @param string $objectUuid   The object UUID.
     * @param int    $totalMinutes The freshly calculated total.
     *
     * @return int Number of updated rows.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function updateTotalForObject(string $objectUuid, int $totalMinutes): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('total_minutes', $qb->createNamedParameter($totalMinutes, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        return $qb->executeStatement();
    }//end updateTotalForObject()

    /**
     * Retrieve distinct object UUIDs that have at least one time entry.
     *
     * Used by the reconcile command to iterate all tracked objects.
     *
     * @return string[]
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-5
     */
    public function findDistinctObjectUuids(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('object_uuid')
            ->from($this->getTableName())
            ->orderBy('object_uuid', 'ASC');

        $result = $qb->executeQuery();
        $uuids  = [];
        while (($row = $result->fetch()) !== false) {
            $uuids[] = $row['object_uuid'];
        }

        $result->closeCursor();
        return $uuids;
    }//end findDistinctObjectUuids()

    /**
     * Delete all time links for a given object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted rows.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function deleteByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        return $qb->executeStatement();
    }//end deleteByObjectUuid()
}//end class

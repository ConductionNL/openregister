<?php

/**
 * OpenRegister Sync Record Mapper
 *
 * Persistence for SyncRecord entities — the per-record tracking table used
 * by the harvest pipeline. Provides helpers for creating tracking rows,
 * transitioning status, and querying counts/records for an execution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper for SyncRecord entities.
 *
 * @method SyncRecord insert(Entity $entity)
 * @method SyncRecord update(Entity $entity)
 * @method SyncRecord find(int|string $id)
 * @method SyncRecord findEntity(IQueryBuilder $query)
 * @method list<SyncRecord> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<SyncRecord>
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
class SyncRecordMapper extends QBMapper
{

    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_sync_records', SyncRecord::class);
    }//end __construct()

    /**
     * Create a pending tracking row for a gathered record.
     *
     * @param int         $sourceId     Owning source id
     * @param string      $executionId  Sync execution id
     * @param string      $externalId   External record identifier
     * @param string|null $organisation Owning organisation UUID
     *
     * @return SyncRecord The persisted record
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function createPending(
        int $sourceId,
        string $executionId,
        string $externalId,
        ?string $organisation=null
    ): SyncRecord {
        $record = new SyncRecord();
        $record->setUuid((string) Uuid::v4());
        $record->setSourceId($sourceId);
        $record->setExecutionId($executionId);
        $record->setExternalId($externalId);
        $record->setStatus(SyncRecordStatus::PENDING);
        $record->setAttempts(0);
        $record->setOrganisation($organisation);
        $record->setCreated(new DateTime());
        $record->setUpdated(new DateTime());

        return $this->insert($record);
    }//end createPending()

    /**
     * Transition a record to a new status, enforcing the state machine.
     *
     * @param SyncRecord  $record       The record to transition
     * @param string      $newStatus    The target status
     * @param string|null $errorMessage Optional error detail
     *
     * @return SyncRecord The updated record
     *
     * @throws \InvalidArgumentException When the transition is not allowed
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function transitionStatus(SyncRecord $record, string $newStatus, ?string $errorMessage=null): SyncRecord
    {
        $current = (string) $record->getStatus();
        if (SyncRecordStatus::canTransition(from: $current, to: $newStatus) === false) {
            throw new \InvalidArgumentException(
                sprintf('Illegal sync-record transition from "%s" to "%s".', $current, $newStatus)
            );
        }

        $record->setStatus($newStatus);
        if ($errorMessage !== null) {
            $record->setErrorMessage($errorMessage);
        }

        $record->setUpdated(new DateTime());

        return $this->update($record);
    }//end transitionStatus()

    /**
     * Find all records for a given execution.
     *
     * @param string $executionId The execution id
     *
     * @return SyncRecord[] The records
     *
     * @psalm-return list<\OCA\OpenRegister\Db\SyncRecord>
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function findByExecution(string $executionId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('execution_id', $qb->createNamedParameter($executionId)));

        return $this->findEntities($qb);
    }//end findByExecution()

    /**
     * Find records for a source/execution still awaiting fetch (resume support).
     *
     * @param int    $sourceId    The source id
     * @param string $executionId The execution id
     *
     * @return SyncRecord[] The pending records
     *
     * @psalm-return list<\OCA\OpenRegister\Db\SyncRecord>
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function findPending(int $sourceId, string $executionId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('execution_id', $qb->createNamedParameter($executionId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SyncRecordStatus::PENDING)));

        return $this->findEntities($qb);
    }//end findPending()

    /**
     * Find a record by source + external id (latest by id).
     *
     * @param int    $sourceId   The source id
     * @param string $externalId The external record id
     *
     * @return SyncRecord|null The most recent matching record, or null
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function findByExternalId(int $sourceId, string $externalId): ?SyncRecord
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('external_id', $qb->createNamedParameter($externalId)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $records = $this->findEntities($qb);

        return ($records[0] ?? null);
    }//end findByExternalId()
}//end class

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
use InvalidArgumentException;
use OCA\OpenRegister\Service\Sync\SyncRecordStatusInterface;
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
class SyncRecordMapper extends QBMapper implements SyncRecordMapperInterface {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_sync_records', entityClass: SyncRecord::class);
	}//end __construct()

	/**
	 * Create a pending tracking row for a gathered record.
	 *
	 * @param SyncExecution $execution  The source and execution the record belongs to
	 * @param string        $externalId External record identifier
	 *
	 * @return SyncRecord The persisted record
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function createPending(SyncExecution $execution, string $externalId): SyncRecord {
		$record = new SyncRecord();
		$record->setUuid((string)Uuid::v4());
		$record->setSourceId((int)$execution->source->getId());
		$record->setExecutionId($execution->executionId);
		$record->setExternalId($externalId);
		$record->setStatus(SyncRecordStatusInterface::PENDING);
		$record->setAttempts(0);
		$record->setOrganisation($execution->source->getOrganisation());
		$record->setCreated(new DateTime());
		$record->setUpdated(new DateTime());

		return $this->insert(entity: $record);
	}//end createPending()

	/**
	 * Transition a record to a new status, enforcing the state machine.
	 *
	 * @param SyncRecord $record    The record to transition
	 * @param string     $newStatus The target status
	 *
	 * @return SyncRecord The updated record
	 *
	 * @throws \InvalidArgumentException When the transition is not allowed
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function transitionStatus(SyncRecord $record, string $newStatus): SyncRecord {
		$this->moveTo(record: $record, status: $newStatus);

		return $this->touch(record: $record);
	}//end transitionStatus()

	/**
	 * Transition a record to an error status and keep the error detail.
	 *
	 * @param SyncRecord  $record  The record to transition
	 * @param SyncFailure $failure The error status and detail
	 *
	 * @return SyncRecord The updated record
	 *
	 * @throws \InvalidArgumentException When the transition is not allowed
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function recordFailure(SyncRecord $record, SyncFailure $failure): SyncRecord {
		$this->moveTo(record: $record, status: $failure->status);
		$record->setErrorMessage($failure->message);

		return $this->touch(record: $record);
	}//end recordFailure()

	/**
	 * Set a record's status when the state machine allows the step.
	 *
	 * @param SyncRecord $record The record
	 * @param string     $status The target status
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the transition is not allowed
	 */
	private function moveTo(SyncRecord $record, string $status): void {
		$current = (string)$record->getStatus();
		$allowed = (SyncRecordStatusInterface::TRANSITIONS[$current] ?? []);
		if (in_array($status, $allowed, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Illegal sync-record transition from "%s" to "%s".', $current, $status)
			);
		}

		$record->setStatus($status);
	}//end moveTo()

	/**
	 * Stamp the update time and persist a record.
	 *
	 * @param SyncRecord $record The record
	 *
	 * @return SyncRecord The updated record
	 */
	private function touch(SyncRecord $record): SyncRecord {
		$record->setUpdated(new DateTime());

		return $this->update(entity: $record);
	}//end touch()

	/**
	 * Find records for a source/execution still awaiting fetch (resume support).
	 *
	 * @param int $sourceId The source id
	 * @param string $executionId The execution id
	 *
	 * @return SyncRecord[] The pending records
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\SyncRecord>
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function findPending(int $sourceId, string $executionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('execution_id', $qb->createNamedParameter($executionId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SyncRecordStatusInterface::PENDING)));

		return $this->findEntities(query: $qb);
	}//end findPending()

	/**
	 * Find a record by source + external id (latest by id).
	 *
	 * @param int $sourceId The source id
	 * @param string $externalId The external record id
	 *
	 * @return SyncRecord|null The most recent matching record, or null
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function findByExternalId(int $sourceId, string $externalId): ?SyncRecord {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('external_id', $qb->createNamedParameter($externalId)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		$records = $this->findEntities(query: $qb);

		return ($records[0] ?? null);
	}//end findByExternalId()
}//end class

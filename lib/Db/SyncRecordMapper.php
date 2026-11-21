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

	/**
	 * Tombstone every external id of a source a complete run did not see, and clear the tombstone of those it did.
	 *
	 * The latest record of an unseen id gets `tombstoned` and `tombstonedAt`;
	 * every tombstoned record of a seen id has both cleared.
	 *
	 * @param int                 $sourceId The source.
	 * @param array<string, true> $seenIds  External ids the run saw, as keys.
	 * @param DateTime            $at       The tombstone time.
	 *
	 * @return int How many external ids were newly tombstoned.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function markTombstones(int $sourceId, array $seenIds, DateTime $at): int {
		$latest = $this->latestPerExternalId(sourceId: $sourceId);

		$toTombstone = [];
		$toClear     = [];
		foreach ($latest as $externalId => $row) {
			$isTombstoned = ((bool)$row['tombstoned'] === true);
			if (isset($seenIds[$externalId]) === false && $isTombstoned === false) {
				$toTombstone[] = (int)$row['id'];
			}

			if (isset($seenIds[$externalId]) === true) {
				$toClear[] = (string)$externalId;
			}
		}

		foreach (array_chunk($toTombstone, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('tombstoned', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set('tombstoned_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		foreach (array_chunk($toClear, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('tombstoned', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->set('tombstoned_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('tombstoned', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
				->andWhere($qb->expr()->in('external_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}

		return count($toTombstone);
	}//end markTombstones()

	/**
	 * The latest record per external id of a source, optionally only those in one status.
	 *
	 * @param int         $sourceId The source.
	 * @param string|null $status   The status to keep, or null for every status.
	 *
	 * @return list<SyncRecord> The records, newest external id last.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function findLatestBySource(int $sourceId, ?string $status=null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('external_id'))
			->orderBy('id', 'ASC');

		$latest = [];
		foreach ($this->findEntities(query: $qb) as $record) {
			// Ascending ids: the last record seen per external id is its latest.
			$latest[(string)$record->getExternalId()] = $record;
		}

		if ($status === null) {
			return array_values($latest);
		}

		return array_values(array_filter($latest, static fn (SyncRecord $record): bool => $record->getStatus() === $status));
	}//end findLatestBySource()

	/**
	 * One record of a source, or null when the source has no record with that id.
	 *
	 * @param int $sourceId The source.
	 * @param int $recordId The record.
	 *
	 * @return SyncRecord|null
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function findOfSource(int $sourceId, int $recordId): ?SyncRecord {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));

		$found = $this->findEntities(query: $qb);

		return ($found[0] ?? null);
	}//end findOfSource()

	/**
	 * Persist a record's fields without changing its status.
	 *
	 * @param SyncRecord $record The record.
	 *
	 * @return SyncRecord
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function save(SyncRecord $record): SyncRecord {
		return $this->touch(record: $record);
	}//end save()

	/**
	 * The object uuids of a source's records tombstoned at one moment.
	 *
	 * @param int      $sourceId The source.
	 * @param DateTime $at       The tombstone time the pass wrote.
	 *
	 * @return list<string> The linked object uuids, without duplicates.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function objectUuidsTombstonedAt(int $sourceId, DateTime $at): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tombstoned', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('tombstoned_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->isNotNull('object_uuid'));

		$uuids  = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			if ((string)$row['object_uuid'] !== '') {
				$uuids[] = (string)$row['object_uuid'];
			}
		}

		$result->closeCursor();

		return $uuids;
	}//end objectUuidsTombstonedAt()

	/**
	 * The latest record (id and tombstone flag) per external id of a source.
	 *
	 * @param int $sourceId The source.
	 *
	 * @return array<string, array{id: int|string, tombstoned: mixed}> Row per external id.
	 */
	private function latestPerExternalId(int $sourceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'external_id', 'tombstoned')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('external_id'))
			->orderBy('id', 'ASC');

		$latest = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			// Ascending ids: the last row seen per external id is its latest record.
			$latest[(string)$row['external_id']] = ['id' => $row['id'], 'tombstoned' => $row['tombstoned']];
		}

		$result->closeCursor();

		return $latest;
	}//end latestPerExternalId()
}//end class

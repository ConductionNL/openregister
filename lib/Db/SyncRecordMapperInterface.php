<?php

/**
 * SyncRecordMapperInterface: what callers outside the Db component use of SyncRecordMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The methods of SyncRecordMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to SyncRecordMapper.
 */
interface SyncRecordMapperInterface {

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
	public function createPending(SyncExecution $execution, string $externalId): SyncRecord;

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
	public function findByExternalId(int $sourceId, string $externalId): ?SyncRecord;

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
	public function recordFailure(SyncRecord $record, SyncFailure $failure): SyncRecord;

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
	public function transitionStatus(SyncRecord $record, string $newStatus): SyncRecord;

	/**
	 * Tombstone every external id of a source a complete run did not see, and clear the tombstone of those it did.
	 *
	 * @param int                 $sourceId The source.
	 * @param array<string, true> $seenIds  External ids the run saw, as keys.
	 * @param \DateTime           $at       The tombstone time.
	 *
	 * @return int How many external ids were newly tombstoned.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function markTombstones(int $sourceId, array $seenIds, \DateTime $at): int;

	/**
	 * The object uuids of a source's records tombstoned at one moment.
	 *
	 * @param int       $sourceId The source.
	 * @param \DateTime $at       The tombstone time the pass wrote.
	 *
	 * @return list<string> The linked object uuids, without duplicates.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function objectUuidsTombstonedAt(int $sourceId, \DateTime $at): array;

	/**
	 * The latest record per external id of a source, optionally only those in one status.
	 *
	 * @param int         $sourceId The source.
	 * @param string|null $status   The status to keep, or null for every status.
	 *
	 * @return list<SyncRecord> The records.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function findLatestBySource(int $sourceId, ?string $status=null): array;

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
	public function findOfSource(int $sourceId, int $recordId): ?SyncRecord;

	/**
	 * Persist a record's fields without changing its status.
	 *
	 * @param SyncRecord $record The record.
	 *
	 * @return SyncRecord
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function save(SyncRecord $record): SyncRecord;
}//end interface

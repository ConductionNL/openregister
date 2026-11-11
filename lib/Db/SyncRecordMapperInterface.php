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
}//end interface

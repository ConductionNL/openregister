<?php

/**
 * ProcessingLogMapperInterface: what callers outside the Db component use of ProcessingLogMapper.
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

use DateTime;
use OCA\OpenRegister\Db\ProcessingLog\ProcessingLogSubject;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * The methods of ProcessingLogMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ProcessingLogMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ProcessingLogMapperInterface {

	/**
	 * Append a batch of entries in one transaction.
	 *
	 * Used by the deferred emission flush so a request's entries land as
	 * one batched write rather than a per-row synchronous insert.
	 *
	 * @param array<int, ProcessingLogEntry> $entries Entries to append.
	 *
	 * @return int Number of entries persisted.
	 */
	public function insertBatch(array $entries): int;

	/**
	 * Find one page of entries by an arbitrary filter set (FG inquiry / VNG API), newest first.
	 *
	 * A subject filter covers single reads and list reads. An entry stays whole
	 * here: unlike the per-subject extract, it goes to the FG or the auditor.
	 *
	 * @param ListQuery              $query The filters (register_id, schema_id, activity_id, actor, action,
	 *                                      subject_id_type, subject_id_value), the limit and the offset.
	 * @param ProcessingLogReadScope $scope Period, tenant scope and confidentiality; the defaults when omitted.
	 *
	 * @return ProcessingLogEntry[]
	 */
	public function findListed(ListQuery $query, ProcessingLogReadScope $scope=new ProcessingLogReadScope()): array;

	/**
	 * Find entries by data-subject identifier within a read scope.
	 *
	 * Covers single reads (`subject_id_*`) and list reads (`subject_ids`); a
	 * list entry comes back reduced to this subject.
	 *
	 * @param ProcessingLogSubject   $subject The subject, type and value both given (e.g. `BSN`, `111`).
	 * @param ProcessingLogReadScope $scope   Period, tenant scope and confidentiality; the defaults when omitted.
	 *
	 * @return ProcessingLogEntry[]
	 */
	public function findForSubject(ProcessingLogSubject $subject, ProcessingLogReadScope $scope=new ProcessingLogReadScope()): array;

	/**
	 * Count entries attributed to each activity, optionally per register.
	 *
	 * Used by the compliance surface to expose the flagged-fallback gap.
	 *
	 * @param string|null $organisationId Tenant scope.
	 * @param string|null $registerId Optional register slice.
	 *
	 * @return array<string, int> Map of activity uuid => entry count.
	 */
	public function countByActivity(?string $organisationId = null, ?string $registerId = null): array;

	/**
	 * Hard-delete entries older than the cutoff (retention prune).
	 *
	 * @param DateTime $cutoff Entries with `created` strictly before this are deleted.
	 *
	 * @return int Number of rows deleted.
	 */
	public function deleteCreatedBefore(DateTime $cutoff): int;
}//end interface

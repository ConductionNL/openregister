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
	 * Find entries by an arbitrary filter set (FG inquiry / VNG API).
	 *
	 * A subject filter covers single reads and list reads. An entry stays whole
	 * here: unlike the per-subject extract, it goes to the FG or the auditor.
	 *
	 * @param array<string, mixed> $filters register_id, schema_id, activity_id, actor, action, subject_id_type, subject_id_value.
	 * @param DateTime|null $from Inclusive lower bound.
	 * @param DateTime|null $to Inclusive upper bound.
	 * @param string|null $organisationId Tenant scope (null = all, admin only).
	 * @param bool $includeConfidential Whether to include confidential entries (FG only).
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return ProcessingLogEntry[]
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function findFiltered(
		array $filters = [],
		?DateTime $from = null,
		?DateTime $to = null,
		?string $organisationId = null,
		bool $includeConfidential = false,
		int $limit = 100,
		int $offset = 0,
	): array;

	/**
	 * Find entries by data-subject identifier within a period.
	 *
	 * Covers single reads (`subject_id_*`) and list reads (`subject_ids`); a
	 * list entry comes back reduced to this subject.
	 *
	 * @param string $idType Subject identifier type (e.g. `BSN`).
	 * @param string $idValue Subject identifier value.
	 * @param DateTime|null $from Inclusive lower bound.
	 * @param DateTime|null $to Inclusive upper bound.
	 * @param string|null $organisationId Tenant scope (null = all, admin only).
	 * @param bool $includeConfidential Whether to include confidential entries (FG only).
	 *
	 * @return ProcessingLogEntry[]
	 */
	public function findBySubject(
		string $idType,
		string $idValue,
		?DateTime $from = null,
		?DateTime $to = null,
		?string $organisationId = null,
		bool $includeConfidential = false,
	): array;

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

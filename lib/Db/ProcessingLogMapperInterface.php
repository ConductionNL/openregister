<?php

/**
 * ProcessingLogMapperInterface: the part of ProcessingLogMapper that the processing log controller uses.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The methods of ProcessingLogMapper that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ProcessingLogMapperInterface {

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
}//end interface

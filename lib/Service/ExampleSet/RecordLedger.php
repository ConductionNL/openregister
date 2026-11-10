<?php

/**
 * OpenRegister Record Ledger
 *
 * Where an install of a fragment's rows writes: the caller's report and record, held by reference.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The caller's report and record, held by reference: an install writes into them as it goes, so what it reported and
 * recorded stays when a later step throws.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class RecordLedger {

	/**
	 * The report: gains the outcomes under the part and `dropped`.
	 *
	 * @var array<string, mixed>
	 */
	public array $report;

	/**
	 * The record: gains the rows written under the part.
	 *
	 * @var array<string, mixed>
	 */
	public array $record;

	/**
	 * Constructor; binds both arrays by reference.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param array<string, mixed> $record The record.
	 */
	public function __construct(array &$report, array &$record) {
		$this->report = &$report;
		$this->record = &$record;
	}//end __construct()
}//end class

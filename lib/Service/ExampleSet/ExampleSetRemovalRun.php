<?php

/**
 * OpenRegister Example Set Removal Run
 *
 * The state of one remove of an example set: the set, its record as the remove
 * shrinks it, the report it fills, and the accounts the record held before the
 * remove deleted them.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * One remove in progress; it never leaves ExampleSetRemover.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
final class ExampleSetRemovalRun {
	/**
	 * The report: whether there was anything to remove, counts per kind, what was left and what failed.
	 *
	 * @var array<string, mixed>
	 */
	public array $report = [];

	/**
	 * The accounts the record held before the remove deleted them.
	 *
	 * @var array<int, string>
	 */
	public array $accounts = [];

	/**
	 * Constructor.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record; loses what the remove deletes.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $set,
		public array $record,
	) {
	}//end __construct()
}//end class

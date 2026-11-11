<?php

/**
 * One metadata-column pass of the reconcile command.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console and mode of a metadata-column pass, and its counts.
 *
 * A run object (ADR-011 Rule 3): it never leaves ReconcileMagicTablesCommand.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
 */
final class MetadataReconcileRun {

	/**
	 * The counts for the summary.
	 *
	 * @var array{tables: int, columns: int, repaired: int, failed: int}
	 */
	public array $counts = ['tables' => 0, 'columns' => 0, 'repaired' => 0, 'failed' => 0];

	/**
	 * Constructor.
	 *
	 * @param OutputInterface $output Console output.
	 * @param bool            $apply  Whether to add the missing columns.
	 */
	public function __construct(
		public readonly OutputInterface $output,
		public readonly bool $apply,
	) {
	}//end __construct()
}//end class

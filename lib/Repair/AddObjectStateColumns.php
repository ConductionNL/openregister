<?php

/**
 * AddObjectStateColumns — retrofit the `_archived` and `_frozen` metadata
 * columns onto every magic table that predates them.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\MagicMapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step: give every existing magic table its metadata columns.
 *
 * The default object list carries `_archived IS NULL`, and so do the count and
 * the facet queries built from the same choke point. A table that never gained
 * the column answers that WHERE clause with an SQL error.
 *
 * Since 14 October 2026 this step is no longer the only remedy: the request
 * path heals a table on its first use, and `occ openregister:tables:reconcile
 * --apply` heals every table on demand. All three use the same
 * MagicMetadataColumnHealer, so they cannot disagree about which columns a
 * table needs. This step stays so an upgrade leaves no table to heal later.
 *
 * It walks the PHYSICAL tables, not the register to schema links: the drift
 * measured on 14 October sat almost entirely in tables whose schema no
 * register listed any more (37 of 38), which a walk over the links never
 * reaches.
 *
 * Idempotent, and never throws: a table that cannot be altered is logged and
 * the sweep continues, because one table must not abort an app upgrade.
 */
class AddObjectStateColumns implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param MagicMapper $magicMapper Magic-table discovery and the shared metadata-column healer.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'Add the archive, freeze and lifecycle status columns to existing object tables';
	}//end getName()

	/**
	 * Heal every magic table.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
	 */
	public function run(IOutput $output): void {
		try {
			$tables = $this->magicMapper->listMagicTableNames();
		} catch (\Throwable $e) {
			// A database that cannot list its tables during an upgrade is not
			// something this step can fix, and it must not stop the upgrade.
			$this->logger->info(
				'[AddObjectStateColumns] Could not list magic tables, skipping: ' . $e->getMessage()
			);
			return;
		}

		$healed = 0;
		$failed = 0;
		foreach ($tables as $tableName) {
			try {
				$result = $this->magicMapper->healMetadataColumns(tableName: $tableName);
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->warning(
					'[AddObjectStateColumns] Could not read table ' . $tableName . ': ' . $e->getMessage()
				);
				continue;
			}

			if ($result['failed'] !== []) {
				$failed++;
				continue;
			}

			if ($result['added'] !== []) {
				$healed++;
			}
		}//end foreach

		$output->info(
			sprintf(
				'Checked %d object table(s) for their metadata columns: %d healed, %d could not be.',
				count($tables),
				$healed,
				$failed
			)
		);
	}//end run()
}//end class

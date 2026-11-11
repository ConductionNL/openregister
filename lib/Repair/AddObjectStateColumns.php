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

use OCA\OpenRegister\Db\MagicMapperInterface;
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
	 * @param MagicMapperInterface $magicMapper Magic-table discovery and the shared metadata-column healer.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MagicMapperInterface $magicMapper,
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
		$sweep  = new MagicTableSweepRun(step: 'AddObjectStateColumns', output: $output);
		$tables = $sweep->listTables(magicMapper: $this->magicMapper, logger: $this->logger);
		if ($tables === null) {
			return;
		}

		foreach ($tables as $tableName) {
			$this->healTable(sweep: $sweep, tableName: $tableName);
		}

		$sweep->report(checkedFor: 'their metadata columns', changedAs: 'healed');
	}//end run()

	/**
	 * Heal one table's metadata columns and count the outcome.
	 *
	 * @param MagicTableSweepRun $sweep     The pass.
	 * @param string             $tableName The magic table.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
	 */
	private function healTable(MagicTableSweepRun $sweep, string $tableName): void {
		try {
			$result = $this->magicMapper->healMetadataColumns(tableName: $tableName);
		} catch (\Throwable $e) {
			$sweep->failed++;
			$this->logger->warning(
				'[AddObjectStateColumns] Could not read table ' . $tableName . ': ' . $e->getMessage()
			);
			return;
		}

		self::count(sweep: $sweep, result: $result);
	}//end healTable()

	/**
	 * Count one healed table: failed when a column could not be added, healed when one was.
	 *
	 * @param MagicTableSweepRun                         $sweep  The pass.
	 * @param array{added: string[], failed: string[]} $result The healer's outcome for the table.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
	 */
	private static function count(MagicTableSweepRun $sweep, array $result): void {
		if ($result['failed'] !== []) {
			$sweep->failed++;
			return;
		}

		if ($result['added'] !== []) {
			$sweep->changed++;
		}
	}//end count()
}//end class

<?php

/**
 * WidenMagicNumberColumns: widen the DECIMAL(10,2) number columns of every
 * existing magic table, once, on upgrade.
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
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step: widen every magic table's number columns to at least DECIMAL(20,6).
 *
 * Runs here and not on a table's first request, so an upgrade does not turn
 * into one ALTER per table under live traffic. It walks the physical tables,
 * one at a time, reports each table it widened, and continues past a table it
 * cannot alter. Widening never narrows, so no stored value changes, and a
 * second run finds nothing to do. Until a table is widened, a save refuses a
 * value its old column would round.
 */
class WidenMagicNumberColumns implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface     $magicMapper Magic-table discovery and widening.
	 * @param LoggerInterface $logger      Logger.
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
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function getName(): string {
		return 'Widen the number columns of existing object tables so they keep their decimals';
	}//end getName()

	/**
	 * Widen every magic table's number columns.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function run(IOutput $output): void {
		$sweep  = new MagicTableSweepRun(step: 'WidenMagicNumberColumns', output: $output);
		$tables = $sweep->listTables(magicMapper: $this->magicMapper, logger: $this->logger);
		if ($tables === null) {
			return;
		}

		foreach ($tables as $tableName) {
			$this->widenTable(sweep: $sweep, tableName: $tableName);
		}

		$sweep->report(checkedFor: 'narrow number columns', changedAs: 'widened');
	}//end run()

	/**
	 * Widen one table's number columns and count the outcome.
	 *
	 * @param MagicTableSweepRun $sweep     The pass.
	 * @param string             $tableName The magic table.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	private function widenTable(MagicTableSweepRun $sweep, string $tableName): void {
		try {
			$columns = $this->magicMapper->widenNumberColumnsToDefault(tableName: $tableName);
		} catch (\Throwable $e) {
			$sweep->failed++;
			$sweep->output->warning(sprintf('%s: could not widen its number columns: %s', $tableName, $e->getMessage()));
			return;
		}

		if ($columns !== []) {
			$sweep->changed++;
			$sweep->output->info(sprintf('%s: widened %s', $tableName, implode(', ', $columns)));
		}
	}//end widenTable()
}//end class

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

use OCA\OpenRegister\Db\MagicMapper;
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
	 * @param MagicMapper     $magicMapper Magic-table discovery and widening.
	 * @param LoggerInterface $logger      Logger.
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
		try {
			$tables = $this->magicMapper->listMagicTableNames();
		} catch (\Throwable $e) {
			$this->logger->info('[WidenMagicNumberColumns] Could not list magic tables, skipping: '.$e->getMessage());
			return;
		}

		$widened = 0;
		$failed = 0;
		foreach ($tables as $tableName) {
			try {
				$columns = $this->magicMapper->widenNumberColumnsToDefault(tableName: $tableName);
			} catch (\Throwable $e) {
				$failed++;
				$output->warning(sprintf('%s: could not widen its number columns: %s', $tableName, $e->getMessage()));
				continue;
			}

			if ($columns !== []) {
				$widened++;
				$output->info(sprintf('%s: widened %s', $tableName, implode(', ', $columns)));
			}
		}

		$output->info(
			sprintf(
				'Checked %d object table(s) for narrow number columns: %d widened, %d could not be.',
				count($tables),
				$widened,
				$failed
			)
		);
	}//end run()
}//end class

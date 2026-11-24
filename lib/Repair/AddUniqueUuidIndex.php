<?php

/**
 * AddUniqueUuidIndex: reports duplicate uuids per magic table, then adds the unique uuid index where there are none.
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
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Service\Object\UniqueUuidIndex;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: give every magic table that lacks it a unique `_uuid` index.
 *
 * A table holding two rows with the same uuid keeps them and gets no index;
 * the step names the table and the duplicated uuids so an administrator can
 * resolve them (`occ openregister:unique-uuid-index --dry-run` lists them
 * again). Once they are resolved, the next run of this step, or of that
 * command, adds the index. The step never deletes or changes a row.
 *
 * Idempotent, and never throws: one unreadable table must not abort an upgrade.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
class AddUniqueUuidIndex implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param MagicTableSelection $selection The magic tables.
	 * @param UniqueUuidIndex     $index     Reads and adds the index.
	 * @param LoggerInterface     $logger    Logger.
	 */
	public function __construct(
		private readonly MagicTableSelection $selection,
		private readonly UniqueUuidIndex $index,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function getName(): string {
		return 'Add the unique uuid index to object tables that lack it, reporting tables with duplicate uuids';
	}//end getName()

	/**
	 * Walk every magic table: report duplicates, or add the index.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$tables = $this->selection->tables(
				registers: $this->selection->registers(registerRef: null),
				schemas: $this->selection->schemas(schemaRef: null)
			);
		} catch (Throwable $e) {
			$output->warning('Could not list the object tables: ' . $e->getMessage());
			return;
		}

		foreach ($tables as $target) {
			try {
				$this->repairTable(target: $target, output: $output);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[AddUniqueUuidIndex] Skipped a table',
					context: ['table' => $target->tableName, 'exception' => $e->getMessage()]
				);
				$output->warning($target->label() . ': skipped, ' . $e->getMessage());
			}
		}
	}//end run()

	/**
	 * Report one table's duplicates, or add its index.
	 *
	 * @param MagicTableTarget $target The table.
	 * @param IOutput          $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	private function repairTable(MagicTableTarget $target, IOutput $output): void {
		if ($this->index->isUnique(tableName: $target->tableName) === true) {
			return;
		}

		$duplicates = $this->index->duplicates(tableName: $target->tableName);
		if ($duplicates !== []) {
			$output->warning(self::duplicateReport(target: $target, duplicates: $duplicates));
			return;
		}

		$this->index->add(tableName: $target->tableName);
		$output->info($target->label() . ': added the unique uuid index');
	}//end repairTable()

	/**
	 * The line that names a table's duplicated uuids.
	 *
	 * @param MagicTableTarget   $target     The table.
	 * @param array<string, int> $duplicates Row count per duplicated uuid.
	 *
	 * @return string The report line.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public static function duplicateReport(MagicTableTarget $target, array $duplicates): string {
		$listed = [];
		foreach ($duplicates as $uuid => $rows) {
			$listed[] = $uuid . ' (' . $rows . ' rows)';
		}

		return $target->label() . ': no unique uuid index added, resolve these duplicate uuids first: ' . implode(', ', $listed);
	}//end duplicateReport()
}//end class

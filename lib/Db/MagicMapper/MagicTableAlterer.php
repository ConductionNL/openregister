<?php

/**
 * MagicTableAlterer: bring the structure of an existing magic table in line with its schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;


/**
 * Bring an existing magic table in line with its schema.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicTableAlterer {
	/**
	 * Constructor.
	 *
	 * @param MagicTableSql          $sql   The connection, the table prefix and the logger.
	 * @param MagicNumberColumnSizes $sizes Forgets the sizes of a widened table.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		private readonly MagicTableSql $sql,
		private readonly MagicNumberColumnSizes $sizes,
	) {
	}//end __construct()

	/**
	 * Run every structure step and record the outcome.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array Column statistics: columnsAdded, columnsDeRequired, columnsReRequired, columnsDropped, columnsRetyped.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function update(TableStructureSync $sync): array {
		$changes = $this->applySteps(sync: $sync);

		// A DDL change to a live table is worth a permanent record.
		$this->sql->logger->info(
			message: '[MagicMapper] Successfully updated table structure',
			context: ['tableName' => $sync->tableName] + $changes
		);

		return $changes;
	}//end update()

	/**
	 * Retype relation columns; see MagicColumnRetyper::relations().
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array<string> The column names retyped.
	 *
	 * @spec exclude magic-table DDL sync
	 */
	public function retypeRelations(TableStructureSync $sync): array {
		return $this->retyper()->relations(sync: $sync);
	}//end retypeRelations()

	/**
	 * The number column widener.
	 *
	 * @return MagicDecimalWidener
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widener(): MagicDecimalWidener {
		return new MagicDecimalWidener(ddl: $this->runner(), sizes: $this->sizes);
	}//end widener()

	/**
	 * The steps, in order: add, de-require, re-require, drop duplicates, tidy
	 * obsolete columns, retype, widen.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array Column statistics.
	 */
	private function applySteps(TableStructureSync $sync): array {
		$nullability = new MagicColumnNullability(ddl: $this->runner(), logger: $this->sql->logger);
		$obsolete = new MagicObsoleteColumns(ddl: $this->runner(), logger: $this->sql->logger);

		$added = (new MagicColumnAdder(db: $this->sql->db, logger: $this->sql->logger))->addMissing(sync: $sync);
		$deRequired = $nullability->apply(change: new MagicNullabilityChange(sync: $sync, nullable: true));
		$reRequired = $nullability->apply(change: new MagicNullabilityChange(sync: $sync, nullable: false));
		$dropped = $obsolete->dropDuplicates(sync: $sync);

		return [
			'columnsAdded' => $added,
			'columnsDeRequired' => array_merge($deRequired, $obsolete->release(sync: $sync)),
			'columnsReRequired' => $reRequired,
			'columnsDropped' => $dropped,
			'columnsRetyped' => array_merge($this->retyper()->orderedJson(sync: $sync), $this->widener()->widen(sync: $sync)),
		];
	}//end applySteps()

	/**
	 * The runner of tolerated statements.
	 *
	 * @return MagicDdlRunner
	 */
	private function runner(): MagicDdlRunner {
		return new MagicDdlRunner(db: $this->sql->db, logger: $this->sql->logger);
	}//end runner()

	/**
	 * The column retyper.
	 *
	 * @return MagicColumnRetyper
	 */
	private function retyper(): MagicColumnRetyper {
		return new MagicColumnRetyper(ddl: $this->runner(), logger: $this->sql->logger);
	}//end retyper()
}//end class

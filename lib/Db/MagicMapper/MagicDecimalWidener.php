<?php

/**
 * MagicDecimalWidener: widen number columns narrower than their property maps to.
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
 * Widen number columns in place; never narrows, so no stored value changes.
 *
 * A table made before a number column took its scale from the schema has
 * DECIMAL(10,2) columns, which round every value with more decimals. A failure
 * is logged and the column left as it is: the write path refuses a value the
 * column cannot hold, so nothing is rounded.
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
 */
final class MagicDecimalWidener {
	/**
	 * Constructor.
	 *
	 * @param MagicDdlRunner         $ddl   Runs the statements.
	 * @param MagicNumberColumnSizes $sizes Forgets the sizes of a widened table.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		private readonly MagicDdlRunner $ddl,
		private readonly MagicNumberColumnSizes $sizes,
	) {
	}//end __construct()

	/**
	 * The number columns narrower than their property maps to.
	 *
	 * @param MagicColumnDrift $columns The live and required columns.
	 *
	 * @return array<string, MagicNumberWidening> The widenings, keyed by physical column name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widenings(MagicColumnDrift $columns): array {
		$widenings = [];
		foreach ($columns->shared() as [$columnName, $columnDef]) {
			$size = $this->widerSize(columns: $columns, column: [$columnName, $columnDef]);
			if ($size !== null) {
				$type = 'DECIMAL(' . $size['precision'] . ',' . $size['scale'] . ')';
				$widenings[$columnName] = new MagicNumberWidening(column: $columnName, type: $type);
			}
		}

		return $widenings;
	}//end widenings()

	/**
	 * Widen every column that needs it.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array<string> The column names widened.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widen(TableStructureSync $sync): array {
		$widened = [];
		foreach ($this->widenings(columns: $sync->columns) as $columnName => $widening) {
			if ($this->widenColumn(sync: $sync, widening: $widening) === true) {
				$widened[] = $columnName;
				$this->sizes->forget(fullTableName: $sync->fullTableName);
			}
		}

		return $widened;
	}//end widen()

	/**
	 * The required columns that ask every number column for at least DECIMAL(20,6).
	 *
	 * @param array $currentColumns Live columns, keyed by physical column name.
	 *
	 * @return array Required columns, keyed by physical column name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function defaultSizes(array $currentColumns): array {
		$required = [];
		foreach ($currentColumns as $columnName => $column) {
			if (in_array(strtolower((string)($column['type'] ?? '')), ['decimal', 'numeric'], true) === true) {
				$required[$columnName] = [
					'name' => $columnName,
					'type' => 'decimal',
					'precision' => MagicNumberColumn::INTEGER_DIGITS + MagicNumberColumn::DEFAULT_SCALE,
					'scale' => MagicNumberColumn::DEFAULT_SCALE,
				];
			}
		}

		return $required;
	}//end defaultSizes()

	/**
	 * The wider size of a decimal column, null when it fits or is no decimal.
	 *
	 * @param MagicColumnDrift $columns The live and required columns.
	 * @param array            $column  Pair of column name and required definition.
	 *
	 * @return array{precision: int, scale: int}|null The size.
	 */
	private function widerSize(MagicColumnDrift $columns, array $column): ?array {
		[$columnName, $columnDef] = $column;
		if (($columnDef['type'] ?? null) !== 'decimal') {
			return null;
		}

		return (new MagicNumberColumn())->widening(
			currentColumn: $columns->current[$columnName],
			required: [
				'precision' => (int)($columnDef['precision'] ?? MagicNumberColumn::INTEGER_DIGITS + MagicNumberColumn::DEFAULT_SCALE),
				'scale' => (int)($columnDef['scale'] ?? MagicNumberColumn::DEFAULT_SCALE),
			]
		);
	}//end widerSize()

	/**
	 * Widen one column.
	 *
	 * @param TableStructureSync  $sync     The table sync.
	 * @param MagicNumberWidening $widening The column and its new type.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function widenColumn(TableStructureSync $sync, MagicNumberWidening $widening): bool {
		return $this->ddl->attempt(
			sql: $this->statement(sync: $sync, widening: $widening),
			failure: new MagicDdlFailure(
				message: '[MagicMapper] Could not widen number column; values it cannot hold are refused on save',
				context: ['tableName' => $sync->tableName, 'columnName' => $widening->column]
			)
		);
	}//end widenColumn()

	/**
	 * The statement that widens one column.
	 *
	 * @param TableStructureSync  $sync     The table sync.
	 * @param MagicNumberWidening $widening The column and its new type.
	 *
	 * @return string The ALTER TABLE statement.
	 */
	private function statement(TableStructureSync $sync, MagicNumberWidening $widening): string {
		$column = (new MagicColumnSql(isPostgres: $sync->isPostgres))->quote(name: $widening->column);
		if ($sync->isPostgres === true) {
			return 'ALTER TABLE ' . $sync->tableNameQuoted . ' ALTER COLUMN ' . $column . ' TYPE ' . $widening->type;
		}

		$live = $sync->columns->current[$widening->column];

		return 'ALTER TABLE ' . $sync->tableNameQuoted . ' MODIFY ' . $column . ' ' . $widening->type . $this->restatedColumn(live: $live);
	}//end statement()

	/**
	 * The nullability and default MySQL needs restated: MODIFY replaces the whole column.
	 *
	 * @param array $live The live column.
	 *
	 * @return string The clauses.
	 */
	private function restatedColumn(array $live): string {
		$nullability = ' NULL';
		if (($live['nullable'] ?? true) === false) {
			$nullability = ' NOT NULL';
		}

		$default = $live['default'] ?? null;
		if (is_numeric($default) === true) {
			return $nullability . ' DEFAULT ' . $default;
		}

		return $nullability;
	}//end restatedColumn()
}//end class

<?php

/**
 * MagicNullabilityChange: one direction of a nullability sync on a magic table.
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
 * Make columns nullable or NOT NULL on one table.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicNullabilityChange {
	/**
	 * The debug line announcing a change, by direction.
	 *
	 * @var array<int, string>
	 */
	private const ANNOUNCEMENTS = [
		0 => '[MagicMapper] Making column NOT NULL (now required)',
		1 => '[MagicMapper] Making column nullable (no longer required)',
	];

	/**
	 * The warning of a failed change, by direction.
	 *
	 * @var array<int, string>
	 */
	private const FAILURES = [
		0 => '[MagicMapper] Failed to make column NOT NULL (may contain null values)',
		1 => '[MagicMapper] Failed to make column nullable',
	];

	/**
	 * Constructor.
	 *
	 * @param TableStructureSync $sync     The table sync.
	 * @param bool               $nullable Whether columns become nullable.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		public readonly TableStructureSync $sync,
		private readonly bool $nullable,
	) {
	}//end __construct()

	/**
	 * Whether the schema wants this direction and the table is the other way.
	 *
	 * @param string $columnName The physical column.
	 * @param array  $columnDef  The required column definition.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function applies(string $columnName, array $columnDef): bool {
		$tableIsNullable = ($this->sync->columns->current[$columnName]['nullable'] ?? true);

		return ($columnDef['nullable'] ?? true) === $this->nullable && $tableIsNullable === !$this->nullable;
	}//end applies()

	/**
	 * The statement that changes a column.
	 *
	 * @param string $columnName The physical column.
	 * @param string $sqlType    The SQL type MySQL needs restated.
	 *
	 * @return string The ALTER TABLE statement.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function statement(string $columnName, string $sqlType): string {
		$table = 'ALTER TABLE ' . $this->sync->tableNameQuoted;
		$column = (new MagicColumnSql(isPostgres: $this->sync->isPostgres))->quote(name: $columnName);
		if ($this->sync->isPostgres === true) {
			return $table . ' ALTER COLUMN ' . $column . ' ' . $this->postgresAction();
		}

		return $table . ' MODIFY COLUMN ' . $column . ' ' . $sqlType . ' ' . $this->mySqlNullability();
	}//end statement()

	/**
	 * The debug line announcing a change of this direction.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function announcement(): string {
		return self::ANNOUNCEMENTS[(int)$this->nullable];
	}//end announcement()

	/**
	 * The warning of a failed change of this direction.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function failureMessage(): string {
		return self::FAILURES[(int)$this->nullable];
	}//end failureMessage()

	/**
	 * The PostgreSQL clause of this direction.
	 *
	 * @return string
	 */
	private function postgresAction(): string {
		if ($this->nullable === true) {
			return 'DROP NOT NULL';
		}

		return 'SET NOT NULL';
	}//end postgresAction()

	/**
	 * The MySQL nullability of this direction.
	 *
	 * @return string
	 */
	private function mySqlNullability(): string {
		if ($this->nullable === true) {
			return 'NULL';
		}

		return 'NOT NULL';
	}//end mySqlNullability()
}//end class

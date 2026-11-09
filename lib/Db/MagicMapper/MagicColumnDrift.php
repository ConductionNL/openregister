<?php

/**
 * MagicColumnDrift: how the columns of a magic table differ from what its schema needs.
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
 * The live columns of a magic table against the columns its schema requires.
 *
 * Required columns are keyed by property name (camelCase), live columns by
 * physical column name (snake_case), so every comparison resolves the physical
 * name first.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicColumnDrift {
	use MagicColumnNameTrait;

	/**
	 * Constructor.
	 *
	 * @param array $current  Live column definitions, keyed by physical column name.
	 * @param array $required Required column definitions, keyed by property name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		public readonly array $current,
		public readonly array $required,
	) {
	}//end __construct()

	/**
	 * The physical column of a required property.
	 *
	 * @param int|string $propertyName The property name.
	 * @param array      $columnDef    The required column definition.
	 *
	 * @return string The column name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function columnName(int|string $propertyName, array $columnDef): string {
		return $columnDef['name'] ?? $this->sanitizeColumnName(name: (string)$propertyName);
	}//end columnName()

	/**
	 * The required columns the table lacks.
	 *
	 * @return array Missing column definitions keyed by property name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function missing(): array {
		$missing = [];
		foreach ($this->required as $propertyName => $columnDef) {
			if (isset($this->current[$this->columnName(propertyName: $propertyName, columnDef: $columnDef)]) === false) {
				$missing[$propertyName] = $columnDef;
			}
		}

		return $missing;
	}//end missing()

	/**
	 * The required columns the table has.
	 *
	 * @return array<int, array{0: string, 1: array}> Pairs of column name and required definition.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function shared(): array {
		$shared = [];
		foreach ($this->required as $propertyName => $columnDef) {
			$columnName = $this->columnName(propertyName: $propertyName, columnDef: $columnDef);
			if (isset($this->current[$columnName]) === true) {
				$shared[] = [$columnName, $columnDef];
			}
		}

		return $shared;
	}//end shared()

	/**
	 * The physical names of all required columns.
	 *
	 * @return array<string, bool> Column names as keys.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function requiredNames(): array {
		$names = [];
		foreach ($this->required as $propertyName => $columnDef) {
			$names[$this->columnName(propertyName: $propertyName, columnDef: $columnDef)] = true;
		}

		return $names;
	}//end requiredNames()

	/**
	 * The columns declared with one type but stored as one of the given live types.
	 *
	 * @param string        $declaredType The required column type.
	 * @param array<string> $liveTypes    The lowercase live types that need a retype.
	 *
	 * @return array<string> Physical column names.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function storedAs(string $declaredType, array $liveTypes): array {
		$columns = [];
		foreach ($this->shared() as [$columnName, $columnDef]) {
			$liveType = strtolower((string)($this->current[$columnName]['type'] ?? ''));
			if (($columnDef['type'] ?? null) === $declaredType && in_array($liveType, $liveTypes, true) === true) {
				$columns[] = $columnName;
			}
		}

		return $columns;
	}//end storedAs()
}//end class

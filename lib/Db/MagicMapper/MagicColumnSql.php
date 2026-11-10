<?php

/**
 * MagicColumnSql: identifier quoting and the SQL type of a column definition.
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
 * Write a column definition's type and name as SQL for one platform.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicColumnSql {
	/**
	 * The SQL type of each column type that needs no sizing or platform choice.
	 */
	private const FIXED_TYPES = [
		'bigint' => 'BIGINT',
		'integer' => 'INTEGER',
		'smallint' => 'SMALLINT',
		'text' => 'TEXT',
		'boolean' => 'BOOLEAN',
		// Order-preserving storage for object-typed properties: PostgreSQL JSONB
		// hashes keys, its JSON type and MySQL/MariaDB JSON keep document order (#1720).
		'json_ordered' => 'JSON',
	];

	/**
	 * Build the writer for one platform.
	 *
	 * @param bool $isPostgres Whether the platform is PostgreSQL.
	 */
	public function __construct(private readonly bool $isPostgres) {
	}//end __construct()

	/**
	 * Quote an identifier, doubling embedded quote characters so it cannot break out.
	 *
	 * @param string $name The unquoted identifier.
	 *
	 * @return string The quoted identifier.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function quote(string $name): string {
		if ($this->isPostgres === true) {
			return '"' . str_replace('"', '""', $name) . '"';
		}

		return '`' . str_replace('`', '``', $name) . '`';
	}//end quote()

	/**
	 * The SQL type of a column; an unknown type is TEXT.
	 *
	 * @param string $type   The column type.
	 * @param array  $column The column definition.
	 *
	 * @return string The SQL type.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function type(string $type, array $column): string {
		return match ($type) {
			'string' => 'VARCHAR(' . ($column['length'] ?? 255) . ')',
			'decimal' => 'DECIMAL(' . ($column['precision'] ?? 10) . ',' . ($column['scale'] ?? 2) . ')',
			'datetime' => $this->platformType(postgres: 'TIMESTAMP', other: 'DATETIME'),
			'json' => $this->platformType(postgres: 'JSONB', other: 'JSON'),
			default => self::FIXED_TYPES[$type] ?? 'TEXT',
		};
	}//end type()

	/**
	 * Pick the type name of the current platform.
	 *
	 * @param string $postgres The name on PostgreSQL.
	 * @param string $other    The name elsewhere.
	 *
	 * @return string The name.
	 */
	private function platformType(string $postgres, string $other): string {
		if ($this->isPostgres === true) {
			return $postgres;
		}

		return $other;
	}//end platformType()
}//end class

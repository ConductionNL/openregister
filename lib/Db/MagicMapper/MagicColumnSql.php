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
	 * The column types that hold JSON.
	 */
	private const JSON_TYPES = ['json', 'json_ordered'];

	/**
	 * How a JSON column is stored on MariaDB.
	 *
	 * MariaDB has no JSON type: `JSON` is `LONGTEXT` in this collation with a
	 * json_valid check. Declaring that ourselves on `MEDIUMTEXT` keeps the
	 * validation and the JSON behaviour, and keeps the column out of the query
	 * Doctrine runs for every `longtext` column whenever Nextcloud reads the
	 * database schema: one scan of every table's constraints per column, which
	 * made any migration on an instance with many magic tables take minutes (#10612).
	 */
	private const MARIADB_JSON = 'MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';

	/**
	 * Build the writer for one platform.
	 *
	 * @param bool $isPostgres Whether the platform is PostgreSQL.
	 * @param bool $jsonAsText Whether JSON is stored as checked text, as on MariaDB.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Optional so the many callers that only quote identifiers need not name it
	 */
	public function __construct(
		private readonly bool $isPostgres,
		private readonly bool $jsonAsText=false,
	) {
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
		if ($this->jsonAsText === true && in_array($type, self::JSON_TYPES, true) === true) {
			return self::MARIADB_JSON;
		}

		return match ($type) {
			'string' => 'VARCHAR(' . ($column['length'] ?? 255) . ')',
			'decimal' => 'DECIMAL(' . ($column['precision'] ?? 10) . ',' . ($column['scale'] ?? 2) . ')',
			'datetime' => $this->platformType(postgres: 'TIMESTAMP', other: 'DATETIME'),
			'json' => $this->platformType(postgres: 'JSONB', other: 'JSON'),
			default => self::FIXED_TYPES[$type] ?? 'TEXT',
		};
	}//end type()

	/**
	 * The check that closes a column definition, with its leading space; empty when the type needs none.
	 *
	 * It goes LAST: MariaDB refuses a column check ahead of NOT NULL or DEFAULT.
	 * A MODIFY restates the whole column, so it needs the check as well.
	 *
	 * @param string $type The column type.
	 * @param string $name The unquoted column name.
	 *
	 * @return string The check, or an empty string.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function check(string $type, string $name): string {
		if ($this->jsonAsText === false || in_array($type, self::JSON_TYPES, true) === false) {
			return '';
		}

		return ' CHECK (json_valid(' . $this->quote(name: $name) . '))';
	}//end check()

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

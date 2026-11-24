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

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;

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
		// hashes keys, its JSON type keeps document order (#1720). MySQL's JSON
		// sorts object keys, so there it is checked text (see orderedJsonAsText).
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
	 * @param bool $isPostgres        Whether the platform is PostgreSQL.
	 * @param bool $jsonAsText        Whether JSON is stored as checked text, as on MariaDB.
	 * @param bool $orderedJsonAsText Whether order-preserving JSON is stored as checked text, as on MySQL.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Optional so the many callers that only quote identifiers need not name it
	 *
	 * @spec openspec/changes/put-preserve-key-order/tasks.md
	 */
	public function __construct(
		private readonly bool $isPostgres,
		private readonly bool $jsonAsText=false,
		private readonly bool $orderedJsonAsText=false,
	) {
	}//end __construct()

	/**
	 * Whether the platform's native JSON type reorders object keys: MySQL, not MariaDB.
	 *
	 * MySQL normalises a JSON document on write and sorts the keys of every
	 * object in it, so an object-typed property read back in a different key
	 * order than it was saved. MariaDB's JSON is text, PostgreSQL's JSON is
	 * stored verbatim, SQLite has no JSON type.
	 *
	 * @param AbstractPlatform $platform The database platform.
	 *
	 * @return bool True when order-preserving JSON must be stored as checked text.
	 *
	 * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
	 */
	public static function reordersJsonKeys(AbstractPlatform $platform): bool {
		return $platform instanceof AbstractMySQLPlatform && ($platform instanceof MariaDBPlatform) === false;
	}//end reordersJsonKeys()

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
	 * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
	 */
	public function type(string $type, array $column): string {
		if ($this->storesAsText(type: $type) === true) {
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
	 * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
	 */
	public function check(string $type, string $name): string {
		if ($this->storesAsText(type: $type) === false) {
			return '';
		}

		return ' CHECK (json_valid(' . $this->quote(name: $name) . '))';
	}//end check()

	/**
	 * Whether a column type is stored as text that must hold JSON on this platform.
	 *
	 * @param string $type The column type.
	 *
	 * @return bool True for every JSON type on MariaDB, and for order-preserving JSON on MySQL.
	 *
	 * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
	 */
	private function storesAsText(string $type): bool {
		if ($this->jsonAsText === true && in_array($type, self::JSON_TYPES, true) === true) {
			return true;
		}

		return $this->orderedJsonAsText === true && $type === 'json_ordered';
	}//end storesAsText()

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

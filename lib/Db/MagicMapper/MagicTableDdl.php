<?php

/**
 * MagicTableDdl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;

/**
 * The CREATE TABLE statement of one magic table, collected column by column:
 * the platform it is written for, the column definitions, the primary key and
 * the unique constraints.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicTableDdl {

	/**
	 * Whether the platform is PostgreSQL.
	 *
	 * @var boolean
	 */
	public readonly bool $isPostgres;

	/**
	 * Whether the platform is SQLite.
	 *
	 * @var boolean
	 */
	public readonly bool $isSqlite;

	/**
	 * The column definitions, in order.
	 *
	 * @var string[]
	 */
	public array $columnDefs = [];

	/**
	 * The quoted primary key column, null when there is none.
	 *
	 * @var string|null
	 */
	public ?string $primaryKey = null;

	/**
	 * Whether an SQLite autoincrement column already declared the primary key inline.
	 *
	 * @var boolean
	 */
	public bool $inlinePrimaryKey = false;

	/**
	 * The quoted columns that carry a unique constraint.
	 *
	 * @var string[]
	 */
	public array $uniqueConstraints = [];

	/**
	 * Constructor.
	 *
	 * @param AbstractPlatform $platform  The database platform.
	 * @param string           $tableName The table name, without prefix.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly AbstractPlatform $platform,
		public readonly string $tableName,
	) {
		$this->isPostgres = ($platform instanceof PostgreSQLPlatform);
		$this->isSqlite = ($platform instanceof SqlitePlatform);
	}//end __construct()

	/**
	 * Quote an identifier the platform's way, without escaping.
	 *
	 * @param string $name The identifier.
	 *
	 * @return string The quoted identifier.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function quoted(string $name): string {
		if ($this->isPostgres === true) {
			return '"' . $name . '"';
		}

		return '`' . $name . '`';
	}//end quoted()
}//end class

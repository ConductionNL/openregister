<?php

/**
 * MagicUnionArm
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One arm of a multi-schema UNION ALL search: the table it reads, the query,
 * the column superset every arm projects, and the columns this table has.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */
final class MagicUnionArm {

	/**
	 * The superset of property columns across every arm, keyed by column name.
	 *
	 * @var array<string, mixed>
	 */
	public array $allPropertyColumns = [];

	/**
	 * Project only metadata columns, keeping wide unions under the target-list limit.
	 *
	 * @var boolean
	 */
	public bool $metadataOnly = false;

	/**
	 * Whether the connection is PostgreSQL.
	 *
	 * @var boolean
	 */
	public bool $isPostgres = false;

	/**
	 * The property columns this arm's table has, for the WHERE and score expressions.
	 *
	 * @var array<int, string>
	 */
	public array $existingColumns = [];

	/**
	 * Constructor.
	 *
	 * @param string   $tableName The bare table name of this arm.
	 * @param array    $query     The search query.
	 * @param Schema   $schema    The arm's schema.
	 * @param Register $register  The arm's register.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly array $query,
		public readonly Schema $schema,
		public readonly Register $register,
	) {
	}//end __construct()

	/**
	 * A column cast to text, so the same column from two schemas stays
	 * type-compatible across the UNION: `col::text` on PostgreSQL,
	 * `CAST(col AS CHAR)` on MariaDB/MySQL.
	 *
	 * @param string $column The (quoted) column.
	 *
	 * @return string The cast expression, aliased to the column.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function castAsText(string $column): string {
		if ($this->isPostgres === true) {
			return "{$column}::text AS {$column}";
		}

		return "CAST({$column} AS CHAR) AS {$column}";
	}//end castAsText()

	/**
	 * A typed NULL placeholder for a column this arm's table lacks, so every
	 * arm has the same column count.
	 *
	 * @param string $column The (quoted) column.
	 *
	 * @return string The NULL expression, aliased to the column.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function nullAs(string $column): string {
		if ($this->isPostgres === true) {
			return "NULL::text AS {$column}";
		}

		return "NULL AS {$column}";
	}//end nullAs()
}//end class

<?php

/**
 * MagicTableIndexRun
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

/**
 * The state of one index-creation pass over a magic table: the table, the
 * platform, the property column being indexed and the indexes created so far.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicTableIndexRun {

	/**
	 * Whether the connection is PostgreSQL.
	 *
	 * @var boolean
	 */
	public bool $isPostgres = false;

	/**
	 * The sanitized name of the property column being indexed.
	 *
	 * @var string
	 */
	public string $column = '';

	/**
	 * The quoted name of the property column being indexed.
	 *
	 * @var string
	 */
	public string $quotedColumn = '';

	/**
	 * Relation indexes created.
	 *
	 * @var array<int, string>
	 */
	public array $relationIndexes = [];

	/**
	 * Facet indexes created.
	 *
	 * @var array<int, string>
	 */
	public array $facetIndexes = [];

	/**
	 * Searchable (trigram) indexes created.
	 *
	 * @var array<int, string>
	 */
	public array $searchableIndexes = [];

	/**
	 * Constructor.
	 *
	 * @param string $tableName     The bare table name.
	 * @param string $fullTableName The table name with the configured prefix.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly string $fullTableName,
	) {
	}//end __construct()

	/**
	 * The name of an index on the current property column.
	 *
	 * @param string $suffix The index kind, such as `rel_idx` or `facet_idx`.
	 *
	 * @return string The index name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function columnIndexName(string $suffix): string {
		return "{$this->tableName}_{$this->column}_{$suffix}";
	}//end columnIndexName()
}//end class

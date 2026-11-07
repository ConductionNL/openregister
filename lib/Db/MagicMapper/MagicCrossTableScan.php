<?php

/**
 * MagicCrossTableScan
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
 * One scan for object uuids across every magic table: a UNION ALL that asks
 * each table which uuids match a predicate, then one fetch per matching table.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicCrossTableScan {

	/**
	 * Whether soft-deleted rows match.
	 *
	 * @var boolean
	 */
	public bool $includeDeleted = false;

	/**
	 * Whether each match is read with every stored column, not only its name and data.
	 *
	 * @var boolean
	 */
	public bool $storedRows = false;

	/**
	 * Only scan this register's tables; null scans every table.
	 *
	 * @var integer|null
	 */
	public ?int $registerIdScope = null;

	/**
	 * The WHERE predicate each UNION arm applies, with positional placeholders.
	 *
	 * @var string
	 */
	public string $predicate = '';

	/**
	 * The values one arm binds to the predicate's placeholders.
	 *
	 * @var array<int, mixed>
	 */
	public array $parameters = [];

	/**
	 * The scanned tables: full table name => registerId and schemaId.
	 *
	 * @var array<string, array{registerId: int, schemaId: int}>
	 */
	public array $tables = [];

	/**
	 * Constructor.
	 *
	 * @param string $label The calling method's name, which prefixes every log line.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly string $label,
	) {
	}//end __construct()

	/**
	 * The UNION ALL statement over every scanned table, and its parameters.
	 *
	 * @return array{0: string, 1: array<int, mixed>} The SQL and the bound values.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function unionStatement(): array {
		$deletedCondition = $this->deletedCondition();
		$parts = [];
		foreach (array_keys($this->tables) as $fullTableName) {
			$parts[] = $this->arm(fullTableName: $fullTableName, deletedCondition: $deletedCondition);
		}

		return [
			implode(' UNION ALL ', $parts),
			array_merge(...array_fill(0, count($parts), $this->parameters)),
		];
	}//end unionStatement()

	/**
	 * The condition that leaves soft-deleted rows out, or nothing when they match.
	 *
	 * @return string The condition, with a leading ` AND `, or ''.
	 */
	private function deletedCondition(): string {
		if ($this->includeDeleted === true) {
			return '';
		}

		return ' AND _deleted IS NULL';
	}//end deletedCondition()

	/**
	 * The UNION arm that scans one table.
	 *
	 * @param string $fullTableName    The table name with the configured prefix.
	 * @param string $deletedCondition The soft-delete condition from deletedCondition().
	 *
	 * @return string The SELECT.
	 */
	private function arm(string $fullTableName, string $deletedCondition): string {
		return sprintf(
			"SELECT '%s' AS _source_table, _uuid AS found_uuid FROM %s WHERE %s%s",
			$fullTableName,
			$fullTableName,
			$this->predicate,
			$deletedCondition
		);
	}//end arm()
}//end class

<?php

/**
 * The names asked of one table, and what has been read so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * One magic table, the UUIDs asked of it, and the names found across its columns.
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
final class NameTableQuery {

	/**
	 * The names found, by UUID.
	 *
	 * @var array<string, array{name: string, organisation: string|null, source: string|null}>
	 */
	public array $names = [];

	/**
	 * Constructor.
	 *
	 * @param string      $tableName The table.
	 * @param array       $uuids     The UUIDs to look up.
	 * @param string|null $source    The table's name source, "<registerId>:<schemaId>".
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly array $uuids,
		public readonly ?string $source,
	) {
	}//end __construct()

	/**
	 * The statement for one name column. `_organisation` travels with the name for the tenancy check.
	 *
	 * The column comes from a fixed list; QueryBuilder accepts neither it nor the runtime table name.
	 *
	 * @param string $column The name column.
	 *
	 * @return string The SQL.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function sqlFor(string $column): string {
		$placeholders = implode(',', array_fill(0, count($this->uuids), '?'));

		return "SELECT _uuid, _organisation, {$column} as name_value
                        FROM {$this->tableName}
                        WHERE _uuid IN ({$placeholders})
                        AND _deleted IS NULL";
	}//end sqlFor()

	/**
	 * Keep a row's name, unless it is blank.
	 *
	 * @param array $row A row with `_uuid`, `name_value` and `_organisation`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function add(array $row): void {
		$name = $row['name_value'];
		if ($name === null || trim((string)$name) === '') {
			return;
		}

		$organisation = ($row['_organisation'] ?? null);
		if ($organisation !== null) {
			$organisation = (string)$organisation;
		}

		$this->names[$row['_uuid']] = ['name' => (string)$name, 'organisation' => $organisation, 'source' => $this->source];
	}//end add()
}//end class

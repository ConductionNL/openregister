<?php

/**
 * BaseSet: a whole base read from another product, as a neutral set of tables.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use InvalidArgumentException;

/**
 * A base as one set of tables, the shape both an uploaded export archive and
 * a source adapter hand to the schema proposer.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
final class BaseSet {

	/**
	 * The tables by name.
	 *
	 * @var array<string, BaseTable>
	 */
	public readonly array $tables;

	/**
	 * Constructor.
	 *
	 * @param string                $name   The base's name in the source product.
	 * @param string                $source The product it was read from (e.g. baserow).
	 * @param array<int, BaseTable> $tables The tables.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When two tables share a name or a link names no table in the set.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $source,
		array $tables,
	) {
		$byName = [];
		foreach ($tables as $table) {
			if (isset($byName[$table->name]) === true) {
				throw new InvalidArgumentException('The base holds two tables named "'.$table->name.'".');
			}

			$byName[$table->name] = $table;
		}

		foreach ($tables as $table) {
			foreach ($table->columns as $column) {
				if ($column->isLink() === true && isset($byName[$column->linkTable]) === false) {
					throw new InvalidArgumentException(
						'Column "'.$table->name.'.'.$column->name.'" links to table "'.$column->linkTable.'", which is not in the base.'
					);
				}
			}
		}

		$this->tables = $byName;
	}//end __construct()

	/**
	 * A table by name, or null when the set has none by that name.
	 *
	 * @param string $name The table name.
	 *
	 * @return BaseTable|null
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function table(string $name): ?BaseTable {
		return ($this->tables[$name] ?? null);
	}//end table()
}//end class

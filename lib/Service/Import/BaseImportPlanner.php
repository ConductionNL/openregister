<?php

/**
 * OpenRegister BaseImportPlanner
 *
 * Reads a whole base export and plans its import: the proposed register,
 * schemas and relations, the row count of each table and the order the
 * tables are loaded in (REQ-IPC-006).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use InvalidArgumentException;

/**
 * The plan a person confirms before anything is written.
 *
 * Tables are ordered so a table comes after the tables it links to, which is
 * the order its rows can be written in with their links already resolvable.
 * Links that form a cycle (two tables pointing at each other, or a table at
 * itself) cannot be ordered that way; those tables keep their export order
 * and are named in `deferredLinks`, because their links are resolved only
 * once every table is written.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
class BaseImportPlanner {

	/**
	 * Constructor.
	 *
	 * @param BaseSetReader      $reader   Reads an export of a known product format.
	 * @param BaseSchemaProposer $proposer Turns the set into a register proposal.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function __construct(
		private readonly BaseSetReader $reader,
		private readonly BaseSchemaProposer $proposer,
	) {
	}//end __construct()

	/**
	 * The formats a base can be uploaded in.
	 *
	 * @return array<int, string> The formats.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function formats(): array {
		return $this->reader->formats();
	}//end formats()

	/**
	 * Read an export and plan its import. Writes nothing.
	 *
	 * @param string $format   The product format.
	 * @param string $filePath The uploaded export.
	 *
	 * @return array{format: string, proposal: array<string, mixed>, tables: array<string, int>, order: array<int, string>, deferredLinks: array<int, string>}
	 *         The plan.
	 *
	 * @throws InvalidArgumentException When the format is unknown or the file is not an export of it.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function plan(string $format, string $filePath): array {
		$set = $this->reader->read(format: $format, filePath: $filePath);
		$order = $this->loadOrder(set: $set);

		return [
			'format' => strtolower(trim($format)),
			'proposal' => $this->proposer->propose(set: $set),
			'tables' => array_map(static fn (BaseTable $table): int => count($table->rows), $set->tables),
			'order' => $order['order'],
			'deferredLinks' => $order['deferred'],
		];
	}//end plan()

	/**
	 * The tables in an order where each comes after the tables it links to.
	 *
	 * @param BaseSet $set The base.
	 *
	 * @return array{order: array<int, string>, deferred: array<int, string>} The order, and the tables whose links form a cycle.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function loadOrder(BaseSet $set): array {
		$pending = $this->dependencies(set: $set);
		$order = [];
		while ($pending !== []) {
			$ready = array_keys(array_filter($pending, static fn (array $needs): bool => array_diff($needs, $order) === []));
			if ($ready === []) {
				break;
			}

			foreach ($ready as $table) {
				$order[] = (string)$table;
				unset($pending[$table]);
			}
		}

		$deferred = array_map('strval', array_keys($pending));

		return ['order' => [...$order, ...$deferred], 'deferred' => $deferred];
	}//end loadOrder()

	/**
	 * Per table, the other tables of the set it links to.
	 *
	 * A link to the table itself, or to a table outside the set, is no
	 * dependency here: the first is a cycle of one, resolved after the write;
	 * the second becomes a per-row outcome of the proposal.
	 *
	 * @param BaseSet $set The base.
	 *
	 * @return array<string, array<int, string>> Table to the tables it needs first, in export order.
	 */
	private function dependencies(BaseSet $set): array {
		$needs = [];
		foreach ($set->tables as $name => $table) {
			$needs[$name] = [];
			foreach ($table->columns as $column) {
				$target = (string)$column->linkTable;
				if ($column->isLink() === true && $target !== (string)$name && $set->table(name: $target) !== null) {
					$needs[$name][] = $target;
				}
			}

			$needs[$name] = array_values(array_unique($needs[$name]));
		}

		return $needs;
	}//end dependencies()
}//end class

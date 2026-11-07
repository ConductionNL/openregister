<?php

/**
 * Plain-array descriptors of Nextcloud Tables entities.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Throwable;

/**
 * Maps Tables row and column entities to plain arrays.
 *
 * Tables is a soft dependency without a stable API, so every getter is guarded.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Every Tables-entity getter is individually guarded (soft dependency).
 */
trait TablesEntityDescriptors {

	/**
	 * Extract a plain descriptor from a Tables Row2 entity.
	 *
	 * @param object $row The Tables row entity.
	 *
	 * @return array<string, mixed> The row descriptor.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function rowDescriptor(object $row): array {
		return [
			'id' => ($this->intGetter(entity: $row, getter: 'getId') ?? 0),
			'createdBy' => $this->nullableStringGetter(entity: $row, getter: 'getCreatedBy'),
			'createdAt' => $this->dateGetter(entity: $row, getter: 'getCreatedAt'),
			'lastEditBy' => $this->nullableStringGetter(entity: $row, getter: 'getLastEditBy'),
			'lastEditAt' => $this->dateGetter(entity: $row, getter: 'getLastEditAt'),
			'cells' => $this->rowCells(row: $row),
		];
	}//end rowDescriptor()

	/**
	 * The normalised cells of a Tables row, skipping cells without a column.
	 *
	 * @param object $row The Tables row entity.
	 *
	 * @return array<int, array{columnId: int, value: mixed}> The cells.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function rowCells(object $row): array {
		$cells = array_map(
			fn (mixed $cell): ?array => $this->cellToArray(cell: $cell),
			$this->arrayGetter(entity: $row, getter: 'getData')
		);

		return array_values(array_filter($cells, static fn (?array $cell): bool => $cell !== null));
	}//end rowCells()

	/**
	 * Normalise a single Tables cell (array or object) to `{columnId, value}`.
	 *
	 * @param mixed $cell The raw cell (array or object).
	 *
	 * @return array{columnId: int, value: mixed}|null The normalised cell, or null.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function cellToArray(mixed $cell): ?array {
		if (is_array($cell) === true) {
			return $this->arrayCell(cell: $cell);
		}

		if (is_object($cell) === true) {
			return $this->entityCell(cell: $cell);
		}

		return null;
	}//end cellToArray()

	/**
	 * Normalise a cell that Tables delivers as an array.
	 *
	 * @param array<string, mixed> $cell The raw cell.
	 *
	 * @return array{columnId: int, value: mixed}|null The normalised cell, or null without a column.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function arrayCell(array $cell): ?array {
		if (isset($cell['columnId']) === false) {
			return null;
		}

		return ['columnId' => (int)$cell['columnId'], 'value' => ($cell['value'] ?? null)];
	}//end arrayCell()

	/**
	 * Normalise a cell that Tables delivers as an entity.
	 *
	 * @param object $cell The raw cell.
	 *
	 * @return array{columnId: int, value: mixed}|null The normalised cell, or null without a column.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function entityCell(object $cell): ?array {
		$columnId = $this->intGetter(entity: $cell, getter: 'getColumnId');
		if ($columnId === null) {
			return null;
		}

		return ['columnId' => $columnId, 'value' => $this->rawGetter(entity: $cell, getter: 'getValue')];
	}//end entityCell()

	/**
	 * Extract a plain descriptor from a Tables Column entity.
	 *
	 * @param object $column The Tables column entity.
	 *
	 * @return array<string, mixed> The column descriptor.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function columnDescriptor(object $column): array {
		return [
			'id' => ($this->intGetter(entity: $column, getter: 'getId') ?? 0),
			'title' => $this->stringGetter(entity: $column, getter: 'getTitle'),
			'technicalName' => $this->nullableStringGetter(entity: $column, getter: 'getTechnicalName'),
			'type' => $this->stringGetter(entity: $column, getter: 'getType'),
			'subtype' => $this->nullableStringGetter(entity: $column, getter: 'getSubtype'),
			'mandatory' => ($this->boolGetter(entity: $column, getter: 'getMandatory') === true),
			'numberMin' => $this->floatGetter(entity: $column, getter: 'getNumberMin'),
			'numberMax' => $this->floatGetter(entity: $column, getter: 'getNumberMax'),
			'numberDecimals' => $this->intGetter(entity: $column, getter: 'getNumberDecimals'),
			'selectionOptions' => $this->selectionOptions(column: $column),
			'relationTargetTableId' => $this->intGetter(entity: $column, getter: 'getRelationTableId'),
		];
	}//end columnDescriptor()

	/**
	 * Extract selection-option labels from a Tables column, tolerant of shapes.
	 *
	 * @param object $column The Tables column entity.
	 *
	 * @return array<int, string> The option labels.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function selectionOptions(object $column): array {
		$options = [];
		foreach ($this->arrayGetter(entity: $column, getter: 'getSelectionOptions') as $option) {
			if (is_array($option) === true) {
				$options[] = (string)($option['label'] ?? $option['value'] ?? '');
				continue;
			}

			$options[] = (string)$option;
		}

		return array_values(array_filter($options, static fn (string $label) => $label !== ''));
	}//end selectionOptions()

	/**
	 * Read an integer getter from a Tables entity, guarded (magic getters).
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return int|null The integer value, or null when unavailable.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function intGetter(object $entity, string $getter): ?int {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intGetter()

	/**
	 * Read a float getter from a Tables entity, guarded.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return float|null The float value, or null when unavailable.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function floatGetter(object $entity, string $getter): ?float {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (float)$value;
	}//end floatGetter()

	/**
	 * Read a boolean getter from a Tables entity, guarded.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return bool The boolean value (false when unavailable).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function boolGetter(object $entity, string $getter): bool {
		return (bool)$this->rawGetter(entity: $entity, getter: $getter);
	}//end boolGetter()

	/**
	 * Read a string getter from a Tables entity, defaulting to '' on failure.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return string The string value, or ''.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function stringGetter(object $entity, string $getter): string {
		return (string)($this->nullableStringGetter(entity: $entity, getter: $getter) ?? '');
	}//end stringGetter()

	/**
	 * Read a string getter from a Tables entity, returning null when absent.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return string|null The string value, or null.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function nullableStringGetter(object $entity, string $getter): ?string {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end nullableStringGetter()

	/**
	 * Read an ISO-8601 date getter from a Tables entity, or null.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return string|null The ISO-8601 date string, or null.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function dateGetter(object $entity, string $getter): ?string {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if ($value instanceof \DateTimeInterface) {
			return $value->format(\DateTime::ATOM);
		}

		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end dateGetter()

	/**
	 * Read an array getter from a Tables entity, defaulting to [] on failure.
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return array<int|string, mixed> The array value, or [].
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function arrayGetter(object $entity, string $getter): array {
		$value = $this->rawGetter(entity: $entity, getter: $getter);
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayGetter()

	/**
	 * Invoke a getter on a Tables entity, guarded (OCP Entity magic getters make
	 * method_exists unreliable, so each call is wrapped).
	 *
	 * @param object $entity The Tables entity.
	 * @param string $getter The getter method name.
	 *
	 * @return mixed The raw value, or null on failure.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function rawGetter(object $entity, string $getter): mixed {
		try {
			return $entity->$getter();
		} catch (Throwable $e) {
			return null;
		}
	}//end rawGetter()
}//end trait
